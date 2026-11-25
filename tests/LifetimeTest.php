<?php declare(strict_types=1);

use Piccolo\Container\ContainerBuilder;
use Piccolo\Container\Exception\ScopeException;
use Tests\Fixtures\{Clock, ClockInterface, RequestContext, UsesRequestContext, WithDependency};

test('a singleton is created once for the whole container, scopes included', function () {
    $container = new ContainerBuilder()->singleton(Clock::class)->build();
    $scope = $container->createScope();

    expect($container->get(Clock::class))->toBe($container->get(Clock::class))
        ->and($scope->get(Clock::class))->toBe($container->get(Clock::class))
        ->and($container->createScope()->get(Clock::class))->toBe($container->get(Clock::class));
});

test('a singleton is only created when it is first resolved', function () {
    $created = 0;
    $container = new ContainerBuilder()
        ->singleton(Clock::class, function () use (&$created) {
            $created++;

            return new Clock();
        })
        ->build();

    expect($created)->toBe(0);

    $container->get(Clock::class);
    $container->get(Clock::class);

    expect($created)->toBe(1);
});

test('a transient service is created on each resolution', function () {
    $container = new ContainerBuilder()->transient(Clock::class)->build();

    expect($container->get(Clock::class))->not->toBe($container->get(Clock::class))
        ->and($container->get(Clock::class))->toBeInstanceOf(Clock::class);
});

test('a scoped service is created once per scope', function () {
    $container = new ContainerBuilder()->scoped(RequestContext::class)->build();
    $first = $container->createScope();
    $second = $container->createScope();

    expect($first->get(RequestContext::class))->toBe($first->get(RequestContext::class))
        ->and($second->get(RequestContext::class))->toBe($second->get(RequestContext::class))
        ->and($first->get(RequestContext::class))->not->toBe($second->get(RequestContext::class));
});

test('the state of a scoped service does not leak to the next scope', function () {
    $container = new ContainerBuilder()->scoped(RequestContext::class)->build();

    $first = $container->createScope();
    $first->get(RequestContext::class)->user = 'alice';
    $first->dispose();

    expect($container->createScope()->get(RequestContext::class)->user)->toBeNull();
});

test('a scoped service cannot be resolved from the root container', function () {
    $container = new ContainerBuilder()->scoped(RequestContext::class)->build();

    expect(fn () => $container->get(RequestContext::class))
        ->toThrow(ScopeException::class, 'The scoped service "Tests\Fixtures\RequestContext" cannot be resolved outside a scope');
});

test('a singleton cannot depend on a scoped service (captive dependency), even resolved from a scope', function () {
    $container = new ContainerBuilder()
        ->scoped(RequestContext::class)
        ->singleton(UsesRequestContext::class)
        ->build();

    expect(fn () => $container->createScope()->get(UsesRequestContext::class))
        ->toThrow(ScopeException::class, 'Tests\Fixtures\UsesRequestContext → Tests\Fixtures\RequestContext');
});

test('a transient service resolved from a scope gets the scoped instances of that scope', function () {
    $container = new ContainerBuilder()
        ->scoped(RequestContext::class)
        ->transient(UsesRequestContext::class)
        ->build();
    $scope = $container->createScope();

    expect($scope->get(UsesRequestContext::class)->context)->toBe($scope->get(RequestContext::class))
        ->and($scope->get(UsesRequestContext::class))->not->toBe($scope->get(UsesRequestContext::class));
});

test('a scoped service gets the singletons of the root container', function () {
    $container = new ContainerBuilder()
        ->singleton(Clock::class)
        ->scoped(WithDependency::class)
        ->build();

    expect($container->createScope()->get(WithDependency::class)->clock)->toBe($container->get(Clock::class));
});

test('an instance is a singleton, whatever its type', function () {
    $container = new ContainerBuilder()
        ->instance('config', ['debug' => true])
        ->instance('name', 'piccolo')
        ->instance('nothing', null)
        ->build();

    expect($container->get('config'))->toBe(['debug' => true])
        ->and($container->get('name'))->toBe('piccolo')
        ->and($container->get('nothing'))->toBeNull()
        ->and($container->has('nothing'))->toBeTrue();
});

test('a service can be bound to a class, autowired with the lifetime of the binding', function () {
    $container = new ContainerBuilder()->singleton(ClockInterface::class, Clock::class)->build();

    expect($container->get(ClockInterface::class))->toBeInstanceOf(Clock::class)
        ->and($container->get(ClockInterface::class))->toBe($container->get(ClockInterface::class));
});

test('a service bound to another registered service is resolved through it', function () {
    $container = new ContainerBuilder()
        ->singleton(Clock::class, fn () => new Clock())
        ->singleton(ClockInterface::class, Clock::class)
        ->build();

    expect($container->get(ClockInterface::class))->toBe($container->get(Clock::class));
});
