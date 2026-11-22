<?php

declare(strict_types=1);

namespace Cairo\Octane;

use Cairo\RequestLocal;

/**
 * The static properties that belong to one booted app, for fiber mode.
 *
 * In fiber mode a PHP thread runs several requests at once, each on its own
 * booted app (see WorkerPool). Laravel and its packages keep some of an app's
 * state in statics: the facade root, Eloquent's booted models, and closures
 * that service providers set while the app boots (Uri::$urlGeneratorResolver,
 * Request::$macros and so on). These are made request-local
 * (Cairo\RequestLocal), so each request sees its own values, and each app
 * keeps its own copy between requests: restore() puts an app's values in
 * place when a request starts, save() keeps them when it ends.
 *
 * They are the statics in PROPERTIES, which an app sets while it runs, and
 * every static that booting the first app changed (discover()).
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

    /** @var list<\ReflectionProperty> */
    private static array $reflected = [];
    /** @var list<mixed> each one's value before any app booted */
    private static array $defaults = [];

    /** @return list<string> "Class::$property" of PROPERTIES, as App::detectLeaks() names them */
    public static function names(): array
    {
        $names = [];
        foreach (self::PROPERTIES as $class => $properties) {
            foreach ($properties as $property) {
                $names[] = $class . '::$' . $property;
            }
        }
        return $names;
    }

    /**
     * Runs $boot, which boots the first app, and makes PROPERTIES and every
     * static it changed request-local. Call it in server.php.
     *
     * @template T
     * @param callable(): T $boot
     * @return T
     */
    public static function discover(callable $boot): mixed
    {
        foreach (array_keys(self::PROPERTIES) as $class) {
            class_exists($class);   // loaded now, so that it is seen
        }
        $before = self::statics();
        $booted = $boot();
        foreach (self::statics() as $name => [$property]) {
            $was = isset($before[$name]) ? $before[$name][1] : $property->getDefaultValue();
            $listed = in_array($property->getName(), self::PROPERTIES[$property->getDeclaringClass()->getName()] ?? [], true);
            if ($listed || $property->getValue() !== $was) {
                RequestLocal::register($property->getDeclaringClass()->getName(), $property->getName());
                self::$reflected[] = $property;
                self::$defaults[] = $was;
            }
        }
        return $booted;
    }

    /** @return list<mixed> the current request's values */
    public static function save(): array
    {
        $values = [];
        foreach (self::$reflected as $p) {
            $values[] = $p->getValue();
        }
        return $values;
    }

    /** @param list<mixed> $values from save() */
    public static function restore(array $values): void
    {
        foreach (self::$reflected as $i => $p) {
            $p->setValue(null, $values[$i]);
        }
    }

    /** @return list<mixed> the values before any app booted */
    public static function defaults(): array
    {
        return self::$defaults;
    }

    /**
     * The initialized static properties of the app's classes, each where it
     * is declared. Cairo's are left out: they are not an app's.
     *
     * @return array<string, array{0: \ReflectionProperty, 1: mixed}>
     */
    private static function statics(): array
    {
        $statics = [];
        foreach (get_declared_classes() as $class) {
            if (str_starts_with($class, 'Cairo\\') || (new \ReflectionClass($class))->getFileName() === false) {
                continue;
            }
            foreach ((new \ReflectionClass($class))->getProperties(\ReflectionProperty::IS_STATIC) as $property) {
                if ($property->getDeclaringClass()->getName() === $class && $property->isInitialized()) {
                    $statics[$class . '::$' . $property->getName()] = [$property, $property->getValue()];
                }
            }
        }
        return $statics;
    }
}
