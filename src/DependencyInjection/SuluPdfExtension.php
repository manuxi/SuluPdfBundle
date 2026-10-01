<?php

declare(strict_types=1);

namespace Manuxi\SuluPdfBundle\DependencyInjection;

use Manuxi\SuluArticleConfigurationBundle\Service\ArticleConfigurationResolver;
use Manuxi\SuluPdfBundle\Model\PdfOptions;
use Manuxi\SuluPdfBundle\Model\PdfRules;
use Manuxi\SuluPdfBundle\Profile\ArticleProfile;
use Manuxi\SuluPdfBundle\Profile\ConfigProfile;
use Manuxi\SuluPdfBundle\Profile\EventProfile;
use Manuxi\SuluPdfBundle\Profile\ExcerptProfile;
use Manuxi\SuluPdfBundle\Profile\ExcerptReader;
use Manuxi\SuluPdfBundle\Service\CompanyDataProviderInterface;
use Manuxi\SuluPdfBundle\Service\NullCompanyDataProvider;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\DependencyInjection\Reference;

class SuluPdfExtension extends Extension implements PrependExtensionInterface
{
    /**
     * Where the excerpt data of each kind of content lives: table, uuid column, excerpt column, modified column, changed column.
     */
    private const EXCERPT_TABLES = [
        'pages' => ['pa_page_dimension_contents', 'pageUuid', 'excerptData', 'lastModified', 'changed'],
        'articles' => ['ar_article_dimension_contents', 'articleUuid', 'excerptData', 'lastModified', 'changed'],
        'events' => ['app_event_dimension_content', 'event_uuid', 'excerpt_data', 'last_modified', 'changed'],
    ];

    public function prepend(ContainerBuilder $container): void
    {
        $container->prependExtensionConfig('framework', [
            'translator' => ['paths' => [__DIR__ . '/../Resources/translations/']],
        ]);

        // sulu_pdf.excerpt.<pages|articles|events>: the PDF switches join the excerpt tab of that content (admin form of this bundle)
        if ($container->hasExtension('sulu_admin')) {
            foreach (\array_keys(self::EXCERPT_TABLES) as $kind) {
                if ($this->excerptEnabled($container->getExtensionConfig('sulu_pdf'), $kind)) {
                    $container->prependExtensionConfig('sulu_admin', [
                        'forms' => ['directories' => [__DIR__ . '/../Resources/config/forms/' . $kind]],
                    ]);
                }
            }
        }
    }

    /**
     * @param list<array<string, mixed>> $configs the raw (unprocessed) config of this bundle
     */
    private function excerptEnabled(array $configs, string $kind): bool
    {
        $enabled = false;
        foreach ($configs as $config) {
            if (isset($config['excerpt'][$kind])) {
                $enabled = (bool) $config['excerpt'][$kind];
            }
        }

        return $enabled;
    }

    public function load(array $configs, ContainerBuilder $container): void
    {
        $config = $this->processConfiguration(new Configuration(), $configs);

        $loader = new YamlFileLoader($container, new FileLocator(__DIR__ . '/../Resources/config'));
        $loader->load('services.yaml');

        $container->setParameter('sulu_pdf.config', [
            'paper' => $config['paper'],
            'logo' => $config['logo'],
            'logo_width' => $config['logo_width'],
            'site_name' => $config['site_name'],
            'colors' => $config['colors'],
            'font' => $config['font'],
        ]);

        // company data: the project's provider, or none
        $container->setAlias(
            CompanyDataProviderInterface::class,
            $config['company_data_provider'] ?: NullCompanyDataProvider::class,
        )->setPublic(false);

        // profiles of the bundles that are installed: they bring their own switches or data
        $bundled = $this->registerBundledProfiles($container, $config);

        // rule overrides by profile name; a profile with a resource_key is a profile of its own,
        // unless a bundled profile of that name exists (the config entry then only tunes it)
        $overrides = [];
        foreach ($config['profiles'] as $name => $profile) {
            $overrides[$name] = \array_filter($profile['rules'], static fn ($value) => null !== $value && [] !== $value);

            if ($profile['resource_key'] && !isset($bundled[$name])) {
                $options = (new Definition(PdfOptions::class))
                    ->setFactory([PdfOptions::class, 'fromArray'])
                    ->setArguments([$profile['options']]);

                $container->setDefinition('sulu_pdf.profile.' . $name, (new Definition(ConfigProfile::class))
                    ->setArguments([$name, $profile['resource_key'], $profile['enabled'], $options, new Definition(PdfRules::class)])
                    ->addTag('sulu_pdf.profile'));
            }
        }
        $container->setParameter('sulu_pdf.rule_overrides', $overrides);
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array<string, true> names of the registered profiles
     */
    private function registerBundledProfiles(ContainerBuilder $container, array $config): array
    {
        $bundles = $container->getParameter('kernel.bundles');
        $connection = new Reference('doctrine.dbal.default_connection');
        $registered = [];

        // readers of the excerpt switches, one per kind of content that has them (sulu_pdf.excerpt.*)
        $readers = [];
        foreach (self::EXCERPT_TABLES as $kind => $columns) {
            if ($config['excerpt'][$kind]) {
                $readers[$kind] = (new Definition(ExcerptReader::class))->setArguments([$connection, ...$columns]);
            }
        }

        // articles: the excerpt switches (sulu_pdf.excerpt.articles) or, without them, the per-article switches of the
        // article configuration bundle
        if (isset($readers['articles'])) {
            $container->setDefinition(ExcerptProfile::class . '.articles', (new Definition(ExcerptProfile::class))
                ->setArguments(['articles', $readers['articles'], (new Definition(PdfRules::class))->setFactory([ArticleProfile::class, 'themeRules']), true])
                ->addTag('sulu_pdf.profile'));
            $registered['articles'] = true;
        } elseif (\class_exists(ArticleConfigurationResolver::class) && isset($bundles['SuluArticleConfigurationBundle'])) {
            $container->setDefinition(ArticleProfile::class, (new Definition(ArticleProfile::class))
                ->setArguments([new Reference(ArticleConfigurationResolver::class), $connection])
                ->addTag('sulu_pdf.profile'));
            $registered['articles'] = true;
        }

        // pages: per-page switches in the excerpt tab (sulu_pdf.excerpt.pages)
        if (isset($readers['pages'])) {
            $container->setDefinition(ExcerptProfile::class . '.pages', (new Definition(ExcerptProfile::class))
                ->setArguments(['pages', $readers['pages'], new Definition(PdfRules::class), false])
                ->addTag('sulu_pdf.profile'));
            $registered['pages'] = true;
        }

        // events: date and venue from the event's data; per-event switches in the excerpt tab (sulu_pdf.excerpt.events),
        // otherwise on/off and the options come from the config for all events
        if (isset($bundles['SuluEventBundle'])) {
            $events = $config['profiles']['events'] ?? ['options' => [], 'enabled' => true];
            $options = (new Definition(PdfOptions::class))
                ->setFactory([PdfOptions::class, 'fromArray'])
                ->setArguments([$events['options']]);

            $container->setDefinition(EventProfile::class, (new Definition(EventProfile::class))
                ->setArguments([$connection, $options, $events['enabled'], $readers['events'] ?? null])
                ->addTag('sulu_pdf.profile'));
            $registered['events'] = true;
        }

        return $registered;
    }
}
