<?php declare(strict_types=1);

namespace Piccolo\Container\Exception;

use Psr\Container\NotFoundExceptionInterface;

/**
 * The identifier is neither registered nor an instantiable class.
 */
class NotFoundException extends ContainerException implements NotFoundExceptionInterface {}
