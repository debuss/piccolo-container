<?php declare(strict_types=1);

namespace Piccolo\Container;

/**
 * A singleton holding a state that must not outlive a request (buffers, identifiers, ...): the instantiated
 * singletons implementing it are reset by Container::reset(), called by a worker after each request.
 *
 * For a class that cannot implement it, register a hook with ContainerBuilder::onReset() instead.
 */
interface Resettable
{

    public function reset(): void;
}
