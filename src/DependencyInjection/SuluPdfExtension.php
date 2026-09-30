<?php

declare(strict_types=1);

namespace Manuxi\SuluPdfBundle\DependencyInjection;

use Manuxi\SuluArticleConfigurationBundle\Service\ArticleConfigurationResolver;
use Manuxi\SuluPdfBundle\Model\PdfOptions;
use Manuxi\SuluPdfBundle\Model\PdfRules;
use Manuxi\SuluPdfBundle\Profile\ArticleProfile;
use Manuxi\SuluPdfBundle\Profile\ConfigProfile;
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
    public function prepend(ContainerBuilder $container): void
    {
        $container->prependExtensionConfig('framework', [
            'translator' => ['paths' => [__DIR__ . '/../Resources/translations/']],
        ]);
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

        // rule overrides by profile name; a profile with a resource_key is a profile of its own
        $overrides = [];
        foreach ($config['profiles'] as $name => $profile) {
            $overrides[$name] = \array_filter($profile['rules'], static fn ($value) => null !== $value && [] !== $value);

            if ($profile['resource_key']) {
                $options = (new Definition(PdfOptions::class))
                    ->setFactory([PdfOptions::class, 'fromArray'])
                    ->setArguments([$profile['options']]);

                $container->setDefinition('sulu_pdf.profile.' . $name, (new Definition(ConfigProfile::class))
                    ->setArguments([$name, $profile['resource_key'], $profile['enabled'], $options, new Definition(PdfRules::class)])
                    ->addTag('sulu_pdf.profile'));
            }
        }
        $container->setParameter('sulu_pdf.rule_overrides', $overrides);

        // articles: automatic with the article configuration bundle (its per-article switches)
        if (\class_exists(ArticleConfigurationResolver::class)
            && isset($container->getParameter('kernel.bundles')['SuluArticleConfigurationBundle'])) {
            $container->setDefinition(ArticleProfile::class, (new Definition(ArticleProfile::class))
                ->setArguments([new Reference(ArticleConfigurationResolver::class), new Reference('doctrine.dbal.default_connection')])
                ->addTag('sulu_pdf.profile'));
        }
    }
}
