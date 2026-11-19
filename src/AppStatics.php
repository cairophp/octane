<?php

declare(strict_types=1);

namespace Cairo\Octane;

use Cairo\RequestLocal;

/**
 * Laravel's static properties that belong to one booted app, for fiber mode.
 *
 * In fiber mode a PHP thread runs several requests at once, each on its own
 * booted app (see WorkerPool). These statics are request-local
 * (Cairo\RequestLocal), so each request sees its own values, and each app
 * keeps its own copy between requests: restore() puts an app's values in
 * place when a request starts, save() keeps them when it ends.
 */
final class AppStatics
{
    /** @var array<class-string, list<string>> */
    public const PROPERTIES = [
        \Illuminate\Container\Container::class => ['instance'],
        \Illuminate\Support\Facades\Facade::class => ['app', 'resolvedInstance'],
        \Illuminate\Database\Eloquent\Model::class => ['resolver', 'dispatcher', 'booted', 'booting', 'bootedCallbacks', 'traitInitializers', 'globalScopes'],
        \Illuminate\Pagination\AbstractPaginator::class => ['currentPathResolver', 'currentPageResolver', 'queryStringResolver', 'viewFactoryResolver'],
        \Illuminate\Pagination\AbstractCursorPaginator::class => ['currentCursorResolver'],
        \Illuminate\Support\Once::class => ['instance'],
        \Illuminate\Support\Number::class => ['locale', 'currency'],
    ];

    /** Makes the statics request-local; call it in server.php. */
    public static function register(): void
    {
        foreach (self::PROPERTIES as $class => $properties) {
            if (class_exists($class)) {
                RequestLocal::register($class, ...$properties);
            }
        }
    }

    /** @var list<\ReflectionProperty>|null */
    private static ?array $reflected = null;

    /** @return list<mixed> the current request's values, in PROPERTIES order */
    public static function save(): array
    {
        return array_map(static fn (\ReflectionProperty $p): mixed => $p->getValue(), self::reflected());
    }

    /** @param list<mixed> $values from save() */
    public static function restore(array $values): void
    {
        foreach (self::reflected() as $i => $p) {
            $p->setValue(null, $values[$i]);
        }
    }

    /** @return list<mixed> the values before any app booted */
    public static function defaults(): array
    {
        return array_map(static fn (\ReflectionProperty $p): mixed => $p->getDefaultValue(), self::reflected());
    }

    /** @return list<\ReflectionProperty> */
    private static function reflected(): array
    {
        if (self::$reflected === null) {
            self::$reflected = [];
            foreach (self::PROPERTIES as $class => $properties) {
                foreach (class_exists($class) ? $properties : [] as $property) {
                    self::$reflected[] = new \ReflectionProperty($class, $property);
                }
            }
        }
        return self::$reflected;
    }
}
