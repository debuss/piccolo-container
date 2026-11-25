<?php declare(strict_types=1);

namespace Piccolo\Container;

use Closure;
use Piccolo\Container\Exception\ScopeException;
use Throwable;

/**
 * A scope of the container, created by Container::createScope(), usually for a request.
 *
 * It holds its own instances of the scoped services, resolves the singletons from the root container, and disposes the
 * Disposable instances it created (scoped and transient) when it is disposed.
 */
final class Scope implements ScopeInterface
{

    /** @var array<string, mixed> */
    private array $instances = [];

    /** @var list<Disposable> */
    private array $disposables = [];

    private bool $disposed = false;

    /**
     * @internal Use Container::createScope().
     */
    public function __construct(
        private readonly Container $root
    ) {}

    public function get(string $id): mixed
    {
        if ($this->disposed) {
            throw new ScopeException(sprintf('The scope is disposed, "%s" cannot be resolved from it', $id));
        }

        return $this->root->resolve($id, $this);
    }

    public function has(string $id): bool
    {
        return $this->root->has($id);
    }

    /**
     * Disposes the Disposable instances, newest first. If some fail, the others are disposed anyway, and the first
     * error is thrown at the end.
     *
     * @throws Throwable The first error thrown by a dispose() method.
     */
    public function dispose(): void
    {
        if ($this->disposed) {
            return;
        }

        $this->disposed = true;
        $error = null;

        foreach (array_reverse($this->disposables) as $disposable) {
            try {
                $disposable->dispose();
            } catch (Throwable $e) {
                $error ??= $e;
            }
        }

        $this->instances = [];
        $this->disposables = [];

        if ($error !== null) {
            throw $error;
        }
    }

    /**
     * The instance of a scoped service, created by the factory the first time.
     *
     * @internal Called by the root container.
     */
    public function scoped(string $id, Closure $factory): mixed
    {
        if (!array_key_exists($id, $this->instances)) {
            $this->instances[$id] = $factory();
        }

        return $this->instances[$id];
    }

    /**
     * @internal Called by the root container.
     */
    public function track(Disposable $disposable): void
    {
        $this->disposables[] = $disposable;
    }
}
