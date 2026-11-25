<?php declare(strict_types=1);

namespace Piccolo\Container\Exception;

/**
 * A service depends on itself, directly or not.
 */
class CircularDependencyException extends ContainerException {}
