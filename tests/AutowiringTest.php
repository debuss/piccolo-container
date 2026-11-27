<?php declare(strict_types=1);

use Piccolo\Container\{Container, ContainerBuilder, ScopeFactory, ScopeInterface};
use Piccolo\Container\Exception\{CircularDependencyException, ContainerException, NotFoundException, ScopeException};
use Psr\Container\{ContainerExceptionInterface, ContainerInterface, NotFoundExceptionInterface};
use Tests\Fixtures\{AbstractService,
    Level,
    Clock,
    ClockInterface,
    CycleA,
    NoConstructor,
    PrivateConstructor,
    WithBuiltin,
    WithContainer,
    WithDefaults,
    WithDependency,
    WithEnumDefault,
    WithInterfaceDependency,
    WithNullableInterface,
    WithOptionalInterface,
    WithUnion,
    WithVariadic};

test('autowires the classes that are not registered, as transient services', function () {
    $container = new ContainerBuilder()->build();

    expect($container->get(NoConstructor::class))->toBeInstanceOf(NoConstructor::class)
        ->and($container->get(NoConstructor::class))->not->toBe($container->get(NoConstructor::class))
        ->and($container->get(WithDependency::class)->clock)->toBeInstanceOf(Clock::class);
});

test('resolves the dependencies from their definitions', function () {
    $container = new ContainerBuilder()
        ->singleton(ClockInterface::class, Clock::class)
        ->build();

    expect($container->get(WithInterfaceDependency::class)->clock)->toBe($container->get(ClockInterface::class));
});

test('resolves the parameters of the factories', function () {
    $container = new ContainerBuilder()
        ->singleton(Clock::class)
        ->singleton('clock.name', fn (Clock $clock, string $format = 'Y-m-d') => $clock::class . '@' . $format)
        ->build();

    expect($container->get('clock.name'))->toBe('Tests\Fixtures\Clock@Y-m-d');
});

test('uses the default values, even for an autowirable class', function () {
    $first = new ContainerBuilder()->build()->get(WithDefaults::class);
    $second = new ContainerBuilder()->build()->get(WithDefaults::class);

    expect($first->name)->toBe('default')
        ->and($first->nullable)->toBeNull()
        ->and($first->clock)->toBeInstanceOf(Clock::class)
        // A `new` default value is created again for each instance
        ->and($first->clock)->not->toBe($second->clock);
});

test('prefers a registered service to the default value', function () {
    $container = new ContainerBuilder()->singleton(Clock::class)->build();

    expect($container->get(WithDefaults::class)->clock)->toBe($container->get(Clock::class));
});

test('gives null to a nullable interface that is not registered, the service when it is', function () {
    $without = new ContainerBuilder()->build();
    $with = new ContainerBuilder()->singleton(ClockInterface::class, Clock::class)->build();

    expect($without->get(WithOptionalInterface::class)->clock)->toBeNull()
        ->and($without->get(WithNullableInterface::class)->clock)->toBeNull()
        ->and($with->get(WithOptionalInterface::class)->clock)->toBeInstanceOf(Clock::class);
});

test('ignores the variadic parameters', function () {
    expect(new ContainerBuilder()->build()->get(WithVariadic::class)->items)->toBe([]);
});

test('refuses a builtin parameter without default value', function () {
    expect(fn () => new ContainerBuilder()->build()->get(WithBuiltin::class))
        ->toThrow(ContainerException::class, 'Unable to resolve the parameter $name of Tests\Fixtures\WithBuiltin::__construct(): its type "string" is builtin');
});

test('refuses a union type', function () {
    expect(fn () => new ContainerBuilder()->build()->get(WithUnion::class))
        ->toThrow(ContainerException::class, 'union and intersection types');
});

test('refuses an interface that is not registered', function () {
    expect(fn () => new ContainerBuilder()->build()->get(WithInterfaceDependency::class))
        ->toThrow(ContainerException::class, '"Tests\Fixtures\ClockInterface" is not registered');
});

test('refuses the classes that cannot be instantiated', function (string $class) {
    expect(fn () => new ContainerBuilder()->build()->get($class))
        ->toThrow(ContainerException::class, 'is not instantiable');
})->with([
    'abstract' => [AbstractService::class],
    'private constructor' => [PrivateConstructor::class],
]);

test('throws a PSR-11 not found exception for an unknown identifier', function () {
    $container = new ContainerBuilder()->build();

    try {
        $container->get('unknown');
        $this->fail('No exception thrown');
    } catch (Throwable $e) {
        // toThrow() only accepts classes, not interfaces
        expect($e)->toBeInstanceOf(NotFoundExceptionInterface::class);
    }

    expect(fn () => $container->get(ClockInterface::class))->toThrow(NotFoundException::class)
        ->and(new NotFoundException())->toBeInstanceOf(ContainerExceptionInterface::class);
});

test('detects the circular dependencies, with their chain', function () {
    expect(fn () => new ContainerBuilder()->build()->get(CycleA::class))
        ->toThrow(
            CircularDependencyException::class,
            'Tests\Fixtures\CycleA → Tests\Fixtures\CycleB → Tests\Fixtures\CycleA'
        );
});

test('tells whether an identifier can be resolved', function () {
    $container = new ContainerBuilder()
        ->singleton('service', fn () => new Clock())
        ->alias('alias', 'service')
        ->build();

    expect($container->has('service'))->toBeTrue()
        ->and($container->has('alias'))->toBeTrue()
        ->and($container->has(Clock::class))->toBeTrue()
        ->and($container->has(ContainerInterface::class))->toBeTrue()
        ->and($container->has(ClockInterface::class))->toBeFalse()
        ->and($container->has(AbstractService::class))->toBeFalse()
        ->and($container->has('unknown'))->toBeFalse();
});

test('gives the scope as ContainerInterface in a scope, and the root container outside', function () {
    $container = new ContainerBuilder()->build();
    $scope = $container->createScope();

    expect($container->get(WithContainer::class)->container)->toBe($container)
        ->and($scope->get(WithContainer::class)->container)->toBe($scope)
        ->and($scope->get(ScopeInterface::class))->toBe($scope)
        ->and($scope->get(Container::class))->toBe($container)
        ->and($scope->get(ScopeFactory::class))->toBe($container)
        ->and(fn () => $container->get(ScopeInterface::class))->toThrow(ScopeException::class);
});

test('a singleton gets the root container, even resolved from a scope', function () {
    $container = new ContainerBuilder()->singleton(WithContainer::class)->build();

    expect($container->createScope()->get(WithContainer::class)->container)->toBe($container);
});

// --- Plans: how to create a service is worked out once, then reused ------------------------------------------------

test('creates a new default object on each resolution from the same container', function () {
    $container = new ContainerBuilder()->build();

    expect($container->get(WithDefaults::class)->clock)->not->toBe($container->get(WithDefaults::class)->clock);
});

test('keeps an enum default value', function () {
    $container = new ContainerBuilder()->build();
    $container->get(WithEnumDefault::class);

    expect($container->get(WithEnumDefault::class)->level)->toBe(Level::Info);
});

test('resolves the dependencies again on each creation, with their own lifetime', function () {
    $container = new ContainerBuilder()
        ->transient(Clock::class)
        ->singleton(NoConstructor::class)
        ->transient('pair', fn (Clock $clock, NoConstructor $shared) => [$clock, $shared])
        ->build();

    [$firstClock, $firstShared] = $container->get('pair');
    [$secondClock, $secondShared] = $container->get('pair');

    expect($secondClock)->not->toBe($firstClock)
        ->and($secondShared)->toBe($firstShared)
        ->and($container->get(WithDependency::class)->clock)->not->toBe($container->get(WithDependency::class)->clock);
});

test('fails again on each resolution when a service cannot be created', function () {
    $container = new ContainerBuilder()->build();

    expect(fn () => $container->get(WithBuiltin::class))->toThrow(ContainerException::class)
        ->and(fn () => $container->get(WithBuiltin::class))->toThrow(ContainerException::class);
});
