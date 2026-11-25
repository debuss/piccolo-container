# Piccolo Container

A small PSR-11 dependency injection container with three lifetimes, as in `Microsoft.Extensions.DependencyInjection`:

| Lifetime | Instances | Typical services |
|---|---|---|
| **Singleton** | one for the whole container | configuration, logger, router, HTTP factories |
| **Scoped** | one per scope (per request) | unit of work, current user, connection in a transaction |
| **Transient** | a new one on each resolution | request handlers, middlewares, light services |

It is made for long-running PHP workers (FrankenPHP, RoadRunner, ...): the container is built once, each request gets
its own scope, and nothing of a request leaks to the next one.

```bash
composer require debuss-a/piccolo-container
```

## Usage

```php
use Piccolo\Container\ContainerBuilder;

$builder = new ContainerBuilder();

$builder->singleton(LoggerInterface::class, static fn (Config $config) => new Logger($config->get('LOGGER_NAME')));
$builder->scoped(UnitOfWork::class);
$builder->transient(PostClientInterface::class, PostClient::class);
$builder->instance(Config::class, $config);
$builder->alias(RouterInterface::class, FastRouteRouter::class);

$container = $builder->build();

// For each request
$scope = $container->createScope();
try {
    $scope->get(PostHandler::class)->handle($request);
} finally {
    $scope->dispose();
}
```

A definition is either:
- `null`: the class named by the identifier is autowired;
- a class name: that class is autowired (or, if it is the identifier of another service, that service is resolved);
- a closure: a factory whose parameters are autowired.

The classes that are not registered are autowired as **transient** services.

### Autowiring

The parameters of the constructors and factories are resolved in this order:

1. a registered service for a class or interface type;
2. the default value of the parameter;
3. an autowired instance for an instantiable class type;
4. `null` for a nullable parameter.

Union and intersection types, and builtin types without a default value, cannot be resolved. The reflection of the
constructors is cached, so in a worker it is only done once per class.

Special identifiers: `ContainerInterface` is the scope the service is resolved from (or the root container),
`ScopeInterface` the current scope, `Container` and `ScopeFactory` the root container.

### Scope validation

A scoped service resolved from the root container, or as a dependency of a singleton (a "captive dependency", which
would keep the instance of the first request forever), throws a `ScopeException` with the resolution chain:

```
The scoped service "UnitOfWork" cannot be resolved outside a scope (from the root container, or as a dependency of a
singleton) (resolving PostRepository → UnitOfWork)
```

### Service providers

```php
final class LoggerServiceProvider implements ServiceProvider
{
    public function register(ContainerBuilder $builder): void
    {
        $builder->singleton(Logger::class, static fn (Config $config) => new Logger(...));
        $builder->alias(LoggerInterface::class, Logger::class);
    }
}

$builder->addProvider(new LoggerServiceProvider());
```

Providers are registered right away: they only store definitions, the services are still created on their first
resolution. A service registered again replaces the previous definition, so an application can override the
definitions of a provider.

### Inflectors

Called with each new instance of a class or interface, whatever its lifetime, e.g. to inject optional dependencies:

```php
$builder->inflect(
    LoggerAwareInterface::class,
    static fn (LoggerAwareInterface $service, ContainerInterface $container) => $service->setLogger($container->get(LoggerInterface::class))
);
```

### Disposing and resetting

- The scoped and transient instances implementing `Disposable` that a scope created are disposed with the scope,
  newest first.
- `Container::reset()`, called by a worker after each request, resets the singletons already created that implement
  `Resettable`, and calls the hooks registered with `$builder->onReset(Logger::class, fn (Logger $l) => $l->reset())`.

## Tests

```bash
composer test
```
