<?php

namespace Fyennyi\AsyncCache\Bridge\Symfony\DependencyInjection;

use Fyennyi\AsyncCache\AsyncCacheManager;
use Fyennyi\AsyncCache\Enum\RateLimiterType;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Reference;
use Psr\SimpleCache\CacheInterface;
use Psr\Log\LoggerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Fyennyi\AsyncCache\Lock\LockInterface;

class AsyncCacheExtension extends Extension
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);

        $definition = new Definition(AsyncCacheManager::class);
        
        $definition->setArguments([
            new Reference(CacheInterface::class),
            null, // rate_limiter (null triggers factory)
            RateLimiterType::from($config['rate_limiter_type']),
            new Reference(LoggerInterface::class),
            new Reference(LockInterface::class),
            [], // middlewares
            new Reference(EventDispatcherInterface::class)
        ]);

        $definition->setPublic(true);
        $container->setDefinition(AsyncCacheManager::class, $definition);
    }
}
