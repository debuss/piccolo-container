<?php declare(strict_types=1);

use Piccolo\Container\ContainerBuilder;
use Piccolo\Container\Exception\ScopeException;
use Tests\Fixtures\{Buffer, Clock, Connection, DisposeLog, FailingDisposable, ThirdPartyLogger, Transaction};

test('disposes the scoped and transient instances of the scope, newest first, once', function () {
    $container = new ContainerBuilder()
        ->singleton(DisposeLog::class)
        ->scoped(Connection::class)
        ->transient(Transaction::class)
        ->build();
    $log = $container->get(DisposeLog::class);

    $scope = $container->createScope();
    $scope->get(Transaction::class);

    expect($log->entries)->toBe([]);

    $scope->dispose();
    $scope->dispose();

    expect($log->entries)->toBe(['transaction', 'connection']);
});

test('does not dispose the singletons with the scope', function () {
    $container = new ContainerBuilder()
        ->singleton(DisposeLog::class)
        ->singleton(Connection::class)
        ->build();

    $scope = $container->createScope();
    $scope->get(Connection::class);
    $scope->dispose();

    expect($container->get(DisposeLog::class)->entries)->toBe([]);
});

test('disposes all the instances even when one fails, then throws the error', function () {
    $container = new ContainerBuilder()
        ->singleton(DisposeLog::class)
        ->scoped(Connection::class)
        ->scoped(FailingDisposable::class)
        ->build();

    $scope = $container->createScope();
    $scope->get(Connection::class);
    $scope->get(FailingDisposable::class);

    expect(fn () => $scope->dispose())->toThrow(RuntimeException::class, 'dispose failed')
        ->and($container->get(DisposeLog::class)->entries)->toBe(['failing', 'connection']);
});

test('a disposed scope cannot be used anymore', function () {
    $scope = new ContainerBuilder()->build()->createScope();
    $scope->dispose();

    expect(fn () => $scope->get(Clock::class))->toThrow(ScopeException::class, 'The scope is disposed');
});

test('resets the Resettable singletons once, even registered under several identifiers', function () {
    $container = new ContainerBuilder()
        ->singleton(Buffer::class)
        ->alias('buffer', Buffer::class)
        ->singleton('same.buffer', Buffer::class)
        ->build();

    $buffer = $container->get('same.buffer');
    $buffer->lines[] = 'request 1';

    $container->reset();

    expect($buffer->lines)->toBe([])
        ->and($buffer->resets)->toBe(1);
});

test('only resets the singletons already created', function () {
    $created = 0;
    $container = new ContainerBuilder()
        ->singleton(Buffer::class, function () use (&$created) {
            $created++;

            return new Buffer();
        })
        ->build();

    $container->reset();

    expect($created)->toBe(0);
});

test('calls the reset hooks of the singletons already created, registered on an alias or not', function () {
    $container = new ContainerBuilder()
        ->singleton(ThirdPartyLogger::class)
        ->alias('logger', ThirdPartyLogger::class)
        ->onReset(ThirdPartyLogger::class, fn (ThirdPartyLogger $logger) => $logger->records = [])
        ->onReset('logger', fn (ThirdPartyLogger $logger) => $logger->records[] = 'reset')
        ->onReset('not.created', fn () => throw new LogicException('Not created'))
        ->build();

    $container->get('logger')->records[] = 'request 1';
    $container->reset();

    expect($container->get(ThirdPartyLogger::class)->records)->toBe(['reset']);
});
