<?php declare(strict_types=1);

namespace Piccolo\Container;

use Closure;

/**
 * How to create a service, and how long its instances live.
 *
 * @internal Created by the ContainerBuilder.
 */
final readonly class Definition
{

    /**
     * @param Closure|string|null $concrete A factory (its parameters are autowired), a class name (autowired, or the
     *                                      id of another service), or null to autowire the class named by the id.
     */
    public function __construct(
        public string $id,
        public Lifetime $lifetime,
        public Closure|string|null $concrete = null,
        public bool $hasInstance = false,
        public mixed $instance = null
    ) {}
}
