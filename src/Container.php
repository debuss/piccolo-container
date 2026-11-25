<?php declare(strict_types=1);

namespace Piccolo\Container;

use Closure;
use Piccolo\Container\Exception\{CircularDependencyException, ContainerException, NotFoundException, ScopeException};
use Psr\Container\ContainerInterface;
use ReflectionClass;
use ReflectionFunction;
use ReflectionNamedType;
use ReflectionParameter;
use WeakMap;

/**
 * The root container, built by the ContainerBuilder.
 *
 * It holds the singletons, and creates the scopes holding the scoped services (one scope per request). The transient
 * services are created on each resolution, from the scope or the root container they are resolved from.
 *
 * Special identifiers:
 *  - ContainerInterface: the scope the service is resolved from, or the root container;
 *  - ScopeInterface: the scope the service is resolved from (not available from the root container);
 *  - Container and ScopeFactory: the root container.
 *
 * The parameters of the constructors and factories are resolved in this order:
 *  1. a registered service (or a special identifier) for a class or interface type;
 *  2. the default value of the parameter;
 *  3. an autowired instance for an instantiable class type;
 *  4. null for a nullable parameter.
 * Union and intersection types, and builtin types without a default value, cannot be resolved.
 */
final class Container implements ContainerInterface, ScopeFactory
{

    /** @var array<string, mixed> The singletons created so far. */
    private array $singletons = [];

    /** @var array<class-string, list<array{ReflectionParameter, ?string}>> The parameters of the constructors. */
    private array $constructors = [];

    /** @var array<string, bool> */
    private array $instantiable = [];

    /** @var WeakMap<Closure, list<array{ReflectionParameter, ?string}>> The parameters of the factories. */
    private WeakMap $factories;

    /**
     * @param array<string, Definition> $definitions
     * @param array<string, string> $aliases The aliases, to their final target.
     * @param array<string, list<Closure>> $inflectors
     * @param array<string, list<Closure>> $resetters
     *
     * @internal Use the ContainerBuilder.
     */
    public function __construct(
        private readonly array $definitions = [],
        private readonly array $aliases = [],
        private readonly array $inflectors = [],
        private readonly array $resetters = []
    ) {
        $this->factories = new WeakMap();

        foreach ($definitions as $id => $definition) {
            if ($definition->hasInstance) {
                $this->singletons[$id] = $definition->instance;
            }
        }
    }

    public function get(string $id): mixed
    {
        return $this->resolve($id, null);
    }

    public function has(string $id): bool
    {
        return isset($this->aliases[$id])
            || isset($this->definitions[$id])
            || $this->isSpecial($id)
            || $this->isInstantiable($id);
    }

    public function createScope(): ScopeInterface
    {
        return new Scope($this);
    }

    /**
     * Resets the singletons created so far (see Resettable and ContainerBuilder::onReset()), e.g. after each request
     * in a worker.
     */
    public function reset(): void
    {
        $reset = [];

        foreach ($this->singletons as $id => $instance) {
            foreach ($this->resetters[$id] ?? [] as $callback) {
                $callback($instance);
            }

            // A singleton can be registered under several identifiers (an interface and its class)
            if ($instance instanceof Resettable && !isset($reset[spl_object_id($instance)])) {
                $reset[spl_object_id($instance)] = true;
                $instance->reset();
            }
        }
    }

    /**
     * @param string[] $chain The services being resolved, to detect the circular dependencies.
     *
     * @internal Called by the scopes.
     */
    public function resolve(string $id, ?Scope $scope, array $chain = []): mixed
    {
        $id = $this->aliases[$id] ?? $id;

        if ($this->isSpecial($id)) {
            return $this->special($id, $scope, $chain);
        }

        if (in_array($id, $chain, true)) {
            throw CircularDependencyException::withChain(sprintf('Circular dependency on "%s"', $id), [...$chain, $id]);
        }

        $definition = $this->definitions[$id] ?? $this->autowired($id, $chain);

        switch ($definition->lifetime) {
            case Lifetime::Singleton:
                if (!array_key_exists($id, $this->singletons)) {
                    // Built from the root container: a scoped dependency fails instead of being kept forever
                    $this->singletons[$id] = $this->build($definition, null, $chain);
                }

                return $this->singletons[$id];

            case Lifetime::Scoped:
                if ($scope === null) {
                    throw ScopeException::withChain(
                        sprintf(
                            'The scoped service "%s" cannot be resolved outside a scope (from the root container, or as a dependency of a singleton)',
                            $id
                        ),
                        [...$chain, $id]
                    );
                }

                return $scope->scoped($id, fn () => $this->build($definition, $scope, $chain));

            default:
                return $this->build($definition, $scope, $chain);
        }
    }

    private function isSpecial(string $id): bool
    {
        return $id === ContainerInterface::class
            || $id === ScopeInterface::class
            || $id === self::class
            || $id === ScopeFactory::class;
    }

    private function special(string $id, ?Scope $scope, array $chain): ContainerInterface
    {
        if ($id === ScopeInterface::class) {
            return $scope ?? throw ScopeException::withChain(
                'The current scope cannot be resolved outside a scope',
                [...$chain, $id]
            );
        }

        return $id === ContainerInterface::class ? ($scope ?? $this) : $this;
    }

    private function isRegistered(string $id): bool
    {
        return isset($this->definitions[$id]) || isset($this->aliases[$id]) || $this->isSpecial($id);
    }

    private function isInstantiable(string $class): bool
    {
        return $this->instantiable[$class] ??= class_exists($class) && new ReflectionClass($class)->isInstantiable();
    }

    private function autowired(string $id, array $chain): Definition
    {
        if (!class_exists($id)) {
            throw NotFoundException::withChain(
                sprintf('"%s" is neither a registered service nor an existing class', $id),
                [...$chain, $id]
            );
        }

        return new Definition($id, Lifetime::Transient);
    }

    private function build(Definition $definition, ?Scope $scope, array $chain): mixed
    {
        $chain[] = $definition->id;
        $concrete = $definition->concrete ?? $definition->id;

        if ($concrete instanceof Closure) {
            $instance = $concrete(...$this->arguments($this->factoryParameters($concrete), $scope, $chain));
        } elseif ($concrete !== $definition->id && $this->isRegistered($concrete)) {
            // Another service: it is inflected (and tracked by the scope) when it is created
            return $this->resolve($concrete, $scope, $chain);
        } else {
            $instance = $this->instantiate($concrete, $scope, $chain);
        }

        if (is_object($instance)) {
            $this->inflect($instance, $scope);

            if ($scope !== null && $instance instanceof Disposable) {
                $scope->track($instance);
            }
        }

        return $instance;
    }

    private function instantiate(string $class, ?Scope $scope, array $chain): object
    {
        if (!class_exists($class)) {
            throw NotFoundException::withChain(
                sprintf('"%s" is neither a registered service nor an existing class', $class),
                $chain
            );
        }

        $parameters = $this->constructors[$class] ??= $this->constructorParameters($class, $chain);

        return $parameters === [] ? new $class() : new $class(...$this->arguments($parameters, $scope, $chain));
    }

    /**
     * @return list<array{ReflectionParameter, ?string}>
     */
    private function constructorParameters(string $class, array $chain): array
    {
        $reflection = new ReflectionClass($class);

        if (!$reflection->isInstantiable()) {
            throw ContainerException::withChain(
                sprintf('The class "%s" is not instantiable (abstract, or with a non-public constructor)', $class),
                $chain
            );
        }

        return $this->parameters($reflection->getConstructor()?->getParameters() ?? []);
    }

    /**
     * @return list<array{ReflectionParameter, ?string}>
     */
    private function factoryParameters(Closure $factory): array
    {
        return $this->factories[$factory] ??= $this->parameters(new ReflectionFunction($factory)->getParameters());
    }

    /**
     * The parameters, with the class or interface of their type (null for a builtin, union or intersection type).
     *
     * The reflection is kept rather than the default values, as a default value can be a new object (`new Foo()`)
     * that must not be shared.
     *
     * @param ReflectionParameter[] $parameters
     * @return list<array{ReflectionParameter, ?string}>
     */
    private function parameters(array $parameters): array
    {
        return array_map(
            static function (ReflectionParameter $parameter): array {
                $type = $parameter->getType();

                return [$parameter, $type instanceof ReflectionNamedType && !$type->isBuiltin() ? $type->getName() : null];
            },
            $parameters
        );
    }

    /**
     * @param list<array{ReflectionParameter, ?string}> $parameters
     */
    private function arguments(array $parameters, ?Scope $scope, array $chain): array
    {
        $arguments = [];

        foreach ($parameters as [$parameter, $class]) {
            if ($parameter->isVariadic()) {
                break;
            }

            $arguments[] = match (true) {
                $class !== null && $this->isRegistered($class) => $this->resolve($class, $scope, $chain),
                $parameter->isDefaultValueAvailable() => $parameter->getDefaultValue(),
                $class !== null && $this->isInstantiable($class) => $this->resolve($class, $scope, $chain),
                $parameter->allowsNull() => null,
                default => throw ContainerException::withChain($this->unresolvable($parameter, $class), $chain),
            };
        }

        return $arguments;
    }

    private function unresolvable(ReflectionParameter $parameter, ?string $class): string
    {
        $function = $parameter->getDeclaringFunction();
        $where = $parameter->getDeclaringClass() !== null
            ? sprintf('%s::%s()', $parameter->getDeclaringClass()->getName(), $function->getName())
            : sprintf('the factory defined in %s:%d', $function->getFileName(), $function->getStartLine());

        $reason = match (true) {
            $class !== null => sprintf('"%s" is not registered, and is not an instantiable class', $class),
            $parameter->getType() === null => 'it has no type nor default value',
            $parameter->getType() instanceof ReflectionNamedType => sprintf(
                'its type "%s" is builtin and it has no default value',
                $parameter->getType()
            ),
            default => sprintf('union and intersection types ("%s") cannot be autowired', $parameter->getType()),
        };

        return sprintf('Unable to resolve the parameter $%s of %s: %s', $parameter->getName(), $where, $reason);
    }

    private function inflect(object $instance, ?Scope $scope): void
    {
        foreach ($this->inflectors as $type => $callbacks) {
            if (!$instance instanceof $type) {
                continue;
            }

            foreach ($callbacks as $callback) {
                $callback($instance, $scope ?? $this);
            }
        }
    }
}
