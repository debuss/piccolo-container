<?php declare(strict_types=1);

use Piccolo\Container\{ContainerBuilder, ServiceProvider};
use Piccolo\Container\Exception\CircularDependencyException;
use Tests\Fixtures\{AwareService, Clock, ClockInterface, Greeter, NoConstructor};
use Psr\Container\ContainerInterface;

test('a definition registered again replaces the previous one', function () {
    $container = new ContainerBuilder()
        ->singleton('service', fn () => 'first')
        ->singleton('service', fn () => 'second')
        ->build();

    expect($container->get('service'))->toBe('second');
});

test('a definition replaces an alias with the same identifier, and the other way round', function () {
    $builder = new ContainerBuilder()
        ->singleton('target', fn () => 'target')
        ->alias('service', 'target')
        ->singleton('service', fn () => 'service');

    expect($builder->build()->get('service'))->toBe('service');

    $builder->alias('service', 'target');

    expect($builder->build()->get('service'))->toBe('target');
});

test('an alias gives the same instance as its target, aliases of aliases included', function () {
    $container = new ContainerBuilder()
        ->singleton(Clock::class)
        ->alias(ClockInterface::class, Clock::class)
        ->alias('clock', ClockInterface::class)
        ->build();

    expect($container->get('clock'))->toBe($container->get(Clock::class))
        ->and($container->get(ClockInterface::class))->toBe($container->get(Clock::class));
});

test('refuses circular aliases', function () {
    expect(fn () => new ContainerBuilder()->alias('a', 'b')->alias('b', 'a')->build())
        ->toThrow(CircularDependencyException::class, 'a → b → a');
});

test('registers the definitions of the providers', function () {
    $provider = new class implements ServiceProvider {
        public function register(ContainerBuilder $builder): void
        {
            $builder->singleton(ClockInterface::class, Clock::class);
        }
    };

    $builder = new ContainerBuilder()->addProvider($provider);

    expect($builder->has(ClockInterface::class))->toBeTrue()
        ->and($builder->has(Clock::class))->toBeFalse()
        ->and($builder->build()->get(ClockInterface::class))->toBeInstanceOf(Clock::class);
});

test('the inflectors are called once with each new instance of their type, with the container', function () {
    $container = new ContainerBuilder()
        ->singleton(AwareService::class)
        ->inflect(Greeter::class, fn (AwareService $service, ContainerInterface $container) => $service->calls[] = $container)
        ->inflect(Greeter::class, fn (AwareService $service) => $service->calls[] = 'second')
        ->inflect(NoConstructor::class, fn () => throw new LogicException('Not a Greeter'))
        ->build();

    $container->get(AwareService::class);

    expect($container->get(AwareService::class)->calls)->toBe([$container, 'second']);
});

test('the inflectors get the scope the instance is created in', function () {
    $container = new ContainerBuilder()
        ->transient(AwareService::class)
        ->inflect(AwareService::class, fn (AwareService $service, ContainerInterface $container) => $service->calls[] = $container)
        ->build();
    $scope = $container->createScope();

    expect($scope->get(AwareService::class)->calls)->toBe([$scope]);
});

test('the inflectors apply to the instances of the factories and the autowired classes', function () {
    $container = new ContainerBuilder()
        ->singleton('factory', fn () => new AwareService())
        ->inflect(AwareService::class, fn (AwareService $service) => $service->calls[] = 'inflected')
        ->build();

    expect($container->get('factory')->calls)->toBe(['inflected'])
        ->and($container->get(AwareService::class)->calls)->toBe(['inflected']);
});

test('the inflectors apply to the class of each instance a factory creates', function () {
    $created = 0;
    $container = new ContainerBuilder()
        ->transient('service', function () use (&$created): object {
            return ++$created === 1 ? new NoConstructor() : new AwareService();
        })
        ->inflect(AwareService::class, fn (AwareService $service) => $service->calls[] = 'inflected')
        ->build();

    expect($container->get('service'))->toBeInstanceOf(NoConstructor::class)
        ->and($container->get('service')->calls)->toBe(['inflected']);
});

test('the inflectors are applied once to a service bound to another one', function () {
    $container = new ContainerBuilder()
        ->singleton(AwareService::class)
        ->singleton(Greeter::class, AwareService::class)
        ->inflect(AwareService::class, fn (AwareService $service) => $service->calls[] = 'inflected')
        ->build();

    expect($container->get(Greeter::class)->calls)->toBe(['inflected']);
});
