<?php declare(strict_types=1);

namespace Piccolo\Container;

/**
 * Groups the definitions of a feature (logger, router, ...), registered on the builder when the provider is added.
 *
 * Registering only stores the definitions: the services are still created the first time they are resolved.
 */
interface ServiceProvider
{

    public function register(ContainerBuilder $builder): void;
}
