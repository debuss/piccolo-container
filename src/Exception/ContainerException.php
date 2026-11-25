<?php declare(strict_types=1);

namespace Piccolo\Container\Exception;

use Psr\Container\ContainerExceptionInterface;
use RuntimeException;

/**
 * A service could not be created (unresolvable parameter, failing factory, ...).
 */
class ContainerException extends RuntimeException implements ContainerExceptionInterface
{

    /**
     * @param string[] $chain The services being resolved, from the first requested one (only shown when there are
     *                       several, the message already naming the last one).
     */
    public static function withChain(string $message, array $chain): static
    {
        return new static(count($chain) < 2 ? $message : sprintf('%s (resolving %s)', $message, implode(' → ', $chain)));
    }
}
