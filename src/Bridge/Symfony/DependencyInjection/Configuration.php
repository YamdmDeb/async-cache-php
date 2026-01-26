<?php

namespace Fyennyi\AsyncCache\Bridge\Symfony\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder() : TreeBuilder
    {
        $treeBuilder = new TreeBuilder('async_cache');
        $rootNode = $treeBuilder->getRootNode();

        $rootNode
            ->children()
                ->scalarNode('rate_limiter_type')
                    ->defaultValue('auto')
                    ->validate()
                        ->ifNotInArray(['auto', 'symfony', 'in_memory'])
                        ->thenInvalid('Invalid rate limiter type %s')
                    ->end()
                ->end()
                ->scalarNode('default_strategy')
                    ->defaultValue('strict')
                ->end()
            ->end();

        return $treeBuilder;
    }
}
