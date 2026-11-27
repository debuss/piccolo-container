<?php declare(strict_types=1);

namespace Piccolo\Container;

use Psr\Container\ContainerInterface;

/**
 * A scope (usually, a request): it holds its own instances of the scoped services, and resolves the singletons from
 * its root container.
 */
interface ScopeInterface extends ContainerInterface
{

    /**
     * @template T of object
     * @param class-string<T>|string $id
     * @return ($id is class-string<T> ? T : mixed)
     */
    public function get(string $id): mixed;

    /**
     * Disposes the Disposable instances created by the scope, newest first. The scope cannot be used anymore.
     */
    public function dispose(): void;
}
