<?php

declare(strict_types=1);

namespace Manuxi\SuluPdfBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

final class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $tree = new TreeBuilder('sulu_pdf');
        $root = $tree->getRootNode();

        $root
            ->children()
                ->scalarNode('paper')->defaultValue('A4')->info('Paper size, e.g. A4 or letter.')->end()
                ->scalarNode('logo')->defaultNull()->info('PNG or JPG in the page header, absolute or relative to the project dir; "{locale}" is replaced (logo.{locale}.png). Without: the site name.')->end()
                ->floatNode('logo_width')->defaultValue(50)->info('Width of the logo in mm.')->end()
                ->scalarNode('site_name')->defaultValue('')->info('Printed in the header when there is no logo.')->end()
                ->arrayNode('colors')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('primary')->defaultValue('#2f6fed')->info('Brand color: rule, headings accents, boxes (darker and lighter shades are derived).')->end()
                        ->scalarNode('text')->defaultValue('#2d2d2d')->end()
                        ->scalarNode('muted')->defaultValue('#777777')->end()
                    ->end()
                ->end()
                ->arrayNode('font')
                    ->addDefaultsIfNotSet()
                    ->info('Without font files the built-in "DejaVu Sans" is used. TTF files, absolute or relative to the project dir.')
                    ->children()
                        ->scalarNode('family')->defaultValue('DejaVu Sans')->end()
                        ->scalarNode('regular')->defaultNull()->end()
                        ->scalarNode('italic')->defaultNull()->end()
                        ->scalarNode('bold')->defaultNull()->end()
                        ->scalarNode('bold_italic')->defaultNull()->end()
                    ->end()
                ->end()
                ->scalarNode('company_data_provider')
                    ->defaultNull()
                    ->info('Service id of a CompanyDataProviderInterface implementation (the "company data" switch).')
                ->end()
                ->arrayNode('profiles')
                    ->info('Per profile name: rules overrides for a bundled profile (e.g. "articles"), or - with resource_key - a profile of its own.')
                    ->useAttributeAsKey('name')
                    ->arrayPrototype()
                        ->children()
                            ->scalarNode('resource_key')->defaultNull()->info('Route resource key, e.g. "events" or "pages". Defines a new profile.')->end()
                            ->booleanNode('enabled')->defaultTrue()->end()
                            ->append($this->options())
                            ->append($this->rules())
                        ->end()
                    ->end()
                ->end()
            ->end();

        return $tree;
    }

    private function options(): ArrayNodeDefinition
    {
        return (new TreeBuilder('options'))->getRootNode()
            ->addDefaultsIfNotSet()
            ->children()
                ->booleanNode('show_captions')->defaultTrue()->end()
                ->booleanNode('show_author')->defaultTrue()->end()
                ->booleanNode('show_modified')->defaultTrue()->end()
                ->booleanNode('show_online_link')->defaultTrue()->end()
                ->enumNode('company_data')->values(['none', 'footer', 'end'])->defaultValue('none')->end()
            ->end();
    }

    private function rules(): ArrayNodeDefinition
    {
        $node = (new TreeBuilder('rules'))->getRootNode();
        $node->addDefaultsIfNotSet();
        $children = $node->children();
        foreach (['root', 'title', 'overline', 'subtitle', 'badges', 'meta', 'lead', 'hero', 'main', 'gallery', 'author'] as $key) {
            $children->scalarNode($key)->defaultNull()->end();
        }
        $children
            ->arrayNode('remove')->scalarPrototype()->end()->end()
            ->arrayNode('exclude_figures')->scalarPrototype()->end()->end()
        ->end();

        return $node;
    }
}
