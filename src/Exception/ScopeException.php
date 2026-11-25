<?php declare(strict_types=1);

namespace Piccolo\Container\Exception;

/**
 * A scoped service was resolved outside a scope: from the root container, or as a dependency of a singleton (which
 * would keep the instance of the first scope forever, a "captive dependency"), or from a disposed scope.
 */
class ScopeException extends ContainerException {}
