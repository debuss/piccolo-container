<?php declare(strict_types=1);

namespace Piccolo\Container;

/**
 * How long an instance of a service lives, as in Microsoft.Extensions.DependencyInjection.
 */
enum Lifetime
{

    /**
     * A single instance for the whole container, created on its first resolution. Its dependencies are always resolved
     * from the root container: a singleton cannot depend on a scoped service.
     */
    case Singleton;

    /**
     * A single instance per scope (usually, per request). It cannot be resolved from the root container.
     */
    case Scoped;

    /**
     * A new instance on each resolution.
     */
    case Transient;
}
