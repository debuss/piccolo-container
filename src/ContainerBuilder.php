<?php declare(strict_types=1);

namespace Piccolo\Container;

use Closure;
use Piccolo\Container\Exception\CircularDependencyException;

/**
 * Registers the services, then builds the container.
 *
 *  $builder = new ContainerBuilder();
 *  $builder->singleton(LoggerInterface::class, static fn (Config $config) => new Logger(...));
 *  $builder->scoped(UnitOfWork::class);
 *  $builder->transient(PostClientInterface::class, PostClient::class);
 *  $builder->alias(RouterInterface::class, FastRouteRouter::class);
 *  $container = $builder->build();
 *
 * A service registered again replaces the previous definition, so that an application can override the definitions of
 * a provider. The classes that are not registered are autowired as transient services.
 */
final class ContainerBuilder
{

    /** @var array<string, Definition> */
    private array $definitions = [];

    /** @var array<string, string> */
    private array $aliases = [];

    /** @var array<string, list<Closure>> */
    private array $inflectors = [];

    /** @var array<string, list<Closure>> */
    private array $resetters = [];

    /**
     * A single instance for the whole container. Its dependencies are resolved from the root container.
     *
     * @param Closure|string|null $concrete A factory whose parameters are autowired, a class name to autowire (or the
     *                                      id of another service), or null to autowire the class named by the id.
     */
    public function singleton(string $id, Closure|string|null $concrete = null): self
    {
        return $this->define(new Definition($id, Lifetime::Singleton, $concrete));
    }

    /**
     * A single instance per scope: it cannot be resolved from the root container, nor be a dependency of a singleton.
     *
     * @param Closure|string|null $concrete See singleton().
     */
    public function scoped(string $id, Closure|string|null $concrete = null): self
    {
        return $this->define(new Definition($id, Lifetime::Scoped, $concrete));
    }

    /**
     * A new instance on each resolution.
     *
     * @param Closure|string|null $concrete See singleton().
     */
    public function transient(string $id, Closure|string|null $concrete = null): self
    {
        return $this->define(new Definition($id, Lifetime::Transient, $concrete));
    }

    /**
     * A value already created (configuration, enum, ...), a singleton.
     */
    public function instance(string $id, mixed $value): self
    {
        return $this->define(new Definition($id, Lifetime::Singleton, hasInstance: true, instance: $value));
    }

    /**
     * Another identifier for a service: both give the same instance, with the lifetime of the target.
     */
    public function alias(string $id, string $target): self
    {
        unset($this->definitions[$id]);
        $this->aliases[$id] = $target;

        return $this;
    }

    /**
     * Calls the callback with each new instance of the given class or interface, whatever its lifetime, e.g. to inject
     * optional dependencies with a setter:
     *
     *  $builder->inflect(LoggerAwareInterface::class, static fn (LoggerAwareInterface $service, ContainerInterface $container) =>
     *      $service->setLogger($container->get(LoggerInterface::class))
     *  );
     *
     * The container given to the callback is the scope the instance is created in, or the root container.
     *
     * @template T of object
     * @param class-string<T> $type
     * @param Closure(T, \Psr\Container\ContainerInterface): mixed $callback
     */
    public function inflect(string $type, Closure $callback): self
    {
        $this->inflectors[$type][] = $callback;

        return $this;
    }

    /**
     * Calls the callback with the instance of the singleton on Container::reset() (after each request in a worker),
     * if it was created, for the classes that cannot implement Resettable:
     *
     *  $builder->onReset(Logger::class, static fn (Logger $logger) => $logger->reset());
     *
     * @template T of object
     * @param class-string<T>|string $id
     * @param Closure(T): mixed $callback
     */
    public function onReset(string $id, Closure $callback): self
    {
        $this->resetters[$id][] = $callback;

        return $this;
    }

    public function addProvider(ServiceProvider $provider): self
    {
        $provider->register($this);

        return $this;
    }

    /**
     * Whether the identifier is registered (as a service or an alias). Autowirable classes are not.
     */
    public function has(string $id): bool
    {
        return isset($this->definitions[$id]) || isset($this->aliases[$id]);
    }

    /**
     * @throws CircularDependencyException If an alias points to itself, directly or not.
     */
    public function build(): Container
    {
        $aliases = [];
        foreach (array_keys($this->aliases) as $id) {
            $aliases[$id] = $this->target($id);
        }

        $resetters = [];
        foreach ($this->resetters as $id => $callbacks) {
            $target = $aliases[$id] ?? $id;
            $resetters[$target] = [...$resetters[$target] ?? [], ...$callbacks];
        }

        return new Container($this->definitions, $aliases, $this->inflectors, $resetters);
    }

    private function define(Definition $definition): self
    {
        unset($this->aliases[$definition->id]);
        $this->definitions[$definition->id] = $definition;

        return $this;
    }

    /**
     * The service an alias ends on, following the aliases of aliases.
     */
    private function target(string $id): string
    {
        $chain = [$id];
        while (isset($this->aliases[$id])) {
            $id = $this->aliases[$id];
            if (in_array($id, $chain, true)) {
                throw CircularDependencyException::withChain('Circular alias', [...$chain, $id]);
            }

            $chain[] = $id;
        }

        return $id;
    }
}
