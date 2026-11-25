<?php declare(strict_types=1);

namespace Piccolo\Container;

/**
 * A container able to create scopes.
 */
interface ScopeFactory
{

    public function createScope(): ScopeInterface;
}
