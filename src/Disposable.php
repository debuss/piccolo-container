<?php declare(strict_types=1);

namespace Piccolo\Container;

/**
 * A service holding resources (connection, transaction, file, ...) to release at the end of the scope it was created
 * in: the scoped and transient instances created by a scope are disposed when the scope is, newest first.
 */
interface Disposable
{

    public function dispose(): void;
}
