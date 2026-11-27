<?php declare(strict_types=1);

/*
 * Checked by PHPStan only (composer analyse): the types the container gives to the applications analysed with PHPStan.
 */

use Piccolo\Container\{Container, ContainerBuilder, ScopeInterface};
use Psr\Container\ContainerInterface;
use Tests\Fixtures\{Clock, ClockInterface, Greeter};

use function PHPStan\Testing\assertType;

function containerTypes(Container $container, ScopeInterface $scope): void
{
    assertType(Clock::class, $container->get(Clock::class));
    assertType(ClockInterface::class, $scope->get(ClockInterface::class));
    assertType('mixed', $container->get('clock.name'));

    new ContainerBuilder()
        ->inflect(Greeter::class, static function (Greeter $greeter, ContainerInterface $container): void {
            assertType(Greeter::class, $greeter);
        })
        ->onReset(Clock::class, static function (Clock $clock): void {
            assertType(Clock::class, $clock);
        });
}
