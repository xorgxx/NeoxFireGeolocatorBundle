<?php

declare(strict_types=1);

namespace Neox\FireGeolocatorBundle;

use Neox\FireGeolocatorBundle\DependencyInjection\Compiler\FilterPriorityCompilerPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\Bundle;

class NeoxFireGeolocatorBundle extends Bundle
{
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $container->addCompilerPass(new FilterPriorityCompilerPass());
    }
}
