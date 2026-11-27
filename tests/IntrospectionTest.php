<?php declare(strict_types=1);

use Piccolo\Container\{ContainerBuilder, Definition, Lifetime};
use Tests\Fixtures\{AwareService, Clock, ClockInterface, Greeter, NoConstructor};

test('gives the registered services, in their order of registration, without the autowired classes', function () {
    $factory = static fn (): Clock => new Clock();
    $clock = new Clock();

    $container = new ContainerBuilder()
        ->singleton(ClockInterface::class, Clock::class)
        ->scoped('factory', $factory)
        ->transient(NoConstructor::class)
        ->instance('clock', $clock)
        ->build();
    $container->get(AwareService::class);

    $definitions = $container->definitions();

    expect(array_keys($definitions))->toBe([ClockInterface::class, 'factory', NoConstructor::class, 'clock'])
        ->and($definitions)->each->toBeInstanceOf(Definition::class)
        ->and($definitions[ClockInterface::class]->lifetime)->toBe(Lifetime::Singleton)
        ->and($definitions[ClockInterface::class]->concrete)->toBe(Clock::class)
        ->and($definitions['factory']->lifetime)->toBe(Lifetime::Scoped)
        ->and($definitions['factory']->concrete)->toBe($factory)
        ->and($definitions[NoConstructor::class]->lifetime)->toBe(Lifetime::Transient)
        ->and($definitions[NoConstructor::class]->concrete)->toBeNull()
        ->and($definitions['clock']->hasInstance)->toBeTrue()
        ->and($definitions['clock']->instance)->toBe($clock);
});

test('gives the aliases, to the service they end on', function () {
    $container = new ContainerBuilder()
        ->singleton(Clock::class)
        ->alias(ClockInterface::class, Clock::class)
        ->alias('clock', ClockInterface::class)
        ->build();

    expect($container->aliases())->toBe([ClockInterface::class => Clock::class, 'clock' => Clock::class]);
});

test('gives the types having inflectors', function () {
    $container = new ContainerBuilder()
        ->inflect(Greeter::class, fn () => null)
        ->inflect(AwareService::class, fn () => null)
        ->inflect(Greeter::class, fn () => null)
        ->build();

    expect($container->inflectedTypes())->toBe([Greeter::class, AwareService::class]);
});
