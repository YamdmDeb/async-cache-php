<?php

namespace Fyennyi\AsyncCache\Bridge\Symfony;

use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * Symfony Bundle for AsyncCache integration
 */
class AsyncCacheBundle extends Bundle
{
    public function getContainerExtension() : ?\Symfony\Component\DependencyInjection\Extension\ExtensionInterface
    {
        return new DependencyInjection\AsyncCacheExtension();
    }
}
