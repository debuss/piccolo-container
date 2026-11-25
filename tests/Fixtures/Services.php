<?php declare(strict_types=1);

namespace Tests\Fixtures;

use Piccolo\Container\{Disposable, Resettable};
use Psr\Container\ContainerInterface;

interface ClockInterface {}

final class Clock implements ClockInterface {}

final class NoConstructor {}

final class WithDependency
{
    public function __construct(public readonly Clock $clock) {}
}

final class WithInterfaceDependency
{
    public function __construct(public readonly ClockInterface $clock) {}
}

final class WithOptionalInterface
{
    public function __construct(public readonly ?ClockInterface $clock = null) {}
}

final class WithNullableInterface
{
    public function __construct(public readonly ?ClockInterface $clock) {}
}

final class WithDefaults
{
    public function __construct(
        public readonly string $name = 'default',
        public readonly ?string $nullable = null,
        public readonly Clock $clock = new Clock()
    ) {}
}

final class WithBuiltin
{
    public function __construct(public readonly string $name) {}
}

final class WithUnion
{
    public function __construct(public readonly Clock|NoConstructor $dependency) {}
}

final class WithContainer
{
    public function __construct(public readonly ContainerInterface $container) {}
}

final class WithVariadic
{
    public array $items;

    public function __construct(public readonly Clock $clock, string ...$items)
    {
        $this->items = $items;
    }
}

final class CycleA
{
    public function __construct(public readonly CycleB $b) {}
}

final class CycleB
{
    public function __construct(public readonly CycleA $a) {}
}

abstract class AbstractService {}

final class PrivateConstructor
{
    private function __construct() {}
}

final class RequestContext
{
    public ?string $user = null;
}

final class UsesRequestContext
{
    public function __construct(public readonly RequestContext $context) {}
}

interface Greeter {}

final class AwareService implements Greeter
{
    public array $calls = [];
}

final class Connection implements Disposable
{
    public function __construct(public readonly DisposeLog $log, public readonly string $name = 'connection') {}

    public function dispose(): void
    {
        $this->log->entries[] = $this->name;
    }
}

final class Transaction implements Disposable
{
    public function __construct(public readonly Connection $connection, public readonly DisposeLog $log) {}

    public function dispose(): void
    {
        $this->log->entries[] = 'transaction';
    }
}

final class FailingDisposable implements Disposable
{
    public function __construct(public readonly DisposeLog $log) {}

    public function dispose(): void
    {
        $this->log->entries[] = 'failing';

        throw new \RuntimeException('dispose failed');
    }
}

final class DisposeLog
{
    public array $entries = [];
}

final class Buffer implements Resettable
{
    public array $lines = [];
    public int $resets = 0;

    public function reset(): void
    {
        $this->lines = [];
        $this->resets++;
    }
}

final class ThirdPartyLogger
{
    public array $records = [];
}
