<?php declare(strict_types=1);

namespace Piccolo\Container;

use Closure;
use Piccolo\Container\Exception\{CircularDependencyException, ContainerException, NotFoundException, ScopeException};
use Psr\Container\ContainerInterface;
use ReflectionClass;
use ReflectionFunction;
use ReflectionNamedType;
use ReflectionParameter;
use UnitEnum;

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
 *
 * How to create a service (the arguments of its constructor or factory, and the inflectors of its class) is worked out
 * once, the first time it is resolved, and kept for the lifetime of the container: in a worker, the next resolutions
 * only create the instances.
 */
final class Container implements ContainerInterface, ScopeFactory
{

    /** The identifiers resolved to a container, see special(). */
    private const array SPECIAL = [
        ContainerInterface::class => true,
        ScopeInterface::class => true,
        self::class => true,
        ScopeFactory::class => true,
    ];

    /** The kinds of argument of a plan: a service to resolve, a value, or the default value of a parameter. */
    private const int SERVICE = 0;
    private const int VALUE = 1;
    private const int DEFAULT = 2;

    /** @var array<string, mixed> The singletons created so far. */
    private array $singletons = [];

    /** @var array<string, Definition> The definitions of the autowired classes resolved so far. */
    private array $autowired = [];

    /**
     * @var array<string, array{Closure(?Scope, string[]): mixed, bool}> How to create each service resolved so far, and
     *      whether it is another service (created, inflected and tracked on its own).
     */
    private array $plans = [];

    /** @var array<class-string, list<Closure>> The inflectors applying to each class created so far. */
    private array $classInflectors = [];

    /** @var array<string, bool> */
    private array $instantiable = [];

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

        if (isset(self::SPECIAL[$id])) {
            return $this->special($id, $scope, $chain);
        }

        if (in_array($id, $chain, true)) {
            throw CircularDependencyException::withChain(sprintf('Circular dependency on "%s"', $id), [...$chain, $id]);
        }

        $definition = $this->definitions[$id] ?? ($this->autowired[$id] ??= $this->autowire($id, $chain));

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
        return isset(self::SPECIAL[$id]);
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

    private function autowire(string $id, array $chain): Definition
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
        [$create, $delegates] = $this->plans[$definition->id] ??= $this->plan($definition, $chain);

        $instance = $create($scope, $chain);

        if ($delegates || !is_object($instance)) {
            return $instance;
        }

        foreach ($this->classInflectors[$instance::class] ??= $this->inflectorsOf($instance::class) as $callback) {
            $callback($instance, $scope ?? $this);
        }

        if ($scope !== null && $instance instanceof Disposable) {
            $scope->track($instance);
        }

        return $instance;
    }

    /**
     * How to create the service of a definition: a closure taking the scope and the chain of the resolution, and
     * whether it is another service (created, inflected and tracked on its own).
     *
     * @param string[] $chain
     * @return array{Closure(?Scope, string[]): mixed, bool}
     */
    private function plan(Definition $definition, array $chain): array
    {
        $concrete = $definition->concrete ?? $definition->id;

        if ($concrete instanceof Closure) {
            $arguments = $this->argumentsPlan(new ReflectionFunction($concrete)->getParameters(), $chain);

            return [
                $arguments === []
                    ? static fn (): mixed => $concrete()
                    : fn (?Scope $scope, array $chain): mixed => $concrete(...$this->arguments($arguments, $scope, $chain)),
                false
            ];
        }

        if ($concrete !== $definition->id && $this->isRegistered($concrete)) {
            return [fn (?Scope $scope, array $chain): mixed => $this->resolve($concrete, $scope, $chain), true];
        }

        if (!class_exists($concrete)) {
            throw NotFoundException::withChain(
                sprintf('"%s" is neither a registered service nor an existing class', $concrete),
                $chain
            );
        }

        $reflection = new ReflectionClass($concrete);

        if (!$reflection->isInstantiable()) {
            throw ContainerException::withChain(
                sprintf('The class "%s" is not instantiable (abstract, or with a non-public constructor)', $concrete),
                $chain
            );
        }

        $arguments = $this->argumentsPlan($reflection->getConstructor()?->getParameters() ?? [], $chain);

        return [
            $arguments === []
                ? static fn (): object => new $concrete()
                : fn (?Scope $scope, array $chain): object => new $concrete(...$this->arguments($arguments, $scope, $chain)),
            false
        ];
    }

    /**
     * How to resolve each parameter, in the order of the class documentation. The services are resolved on each
     * creation, the values are kept, except a default value that is an object (`new Foo()`), which must not be shared.
     *
     * @param ReflectionParameter[] $parameters
     * @param string[] $chain
     * @return list<array{int, mixed}>
     */
    private function argumentsPlan(array $parameters, array $chain): array
    {
        $plan = [];

        foreach ($parameters as $parameter) {
            if ($parameter->isVariadic()) {
                break;
            }

            $type = $parameter->getType();
            $class = $type instanceof ReflectionNamedType && !$type->isBuiltin() ? $type->getName() : null;

            $plan[] = match (true) {
                $class !== null && $this->isRegistered($class) => [self::SERVICE, $class],
                $parameter->isDefaultValueAvailable() => $this->defaultValuePlan($parameter),
                $class !== null && $this->isInstantiable($class) => [self::SERVICE, $class],
                $parameter->allowsNull() => [self::VALUE, null],
                default => throw ContainerException::withChain($this->unresolvable($parameter, $class), $chain),
            };
        }

        return $plan;
    }

    /**
     * @return array{int, mixed}
     */
    private function defaultValuePlan(ReflectionParameter $parameter): array
    {
        $value = $parameter->getDefaultValue();

        return is_object($value) && !$value instanceof UnitEnum ? [self::DEFAULT, $parameter] : [self::VALUE, $value];
    }

    /**
     * @param list<array{int, mixed}> $plan
     * @param string[] $chain
     */
    private function arguments(array $plan, ?Scope $scope, array $chain): array
    {
        $arguments = [];

        foreach ($plan as [$kind, $value]) {
            $arguments[] = match ($kind) {
                self::SERVICE => $this->resolve($value, $scope, $chain),
                self::VALUE => $value,
                default => $value->getDefaultValue(),
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

    /**
     * The inflectors of the types a class is an instance of, in the order they were added.
     *
     * @param class-string $class
     * @return list<Closure>
     */
    private function inflectorsOf(string $class): array
    {
        $callbacks = [];

        foreach ($this->inflectors as $type => $typeCallbacks) {
            if (is_a($class, $type, true)) {
                array_push($callbacks, ...$typeCallbacks);
            }
        }

        return $callbacks;
    }
}
