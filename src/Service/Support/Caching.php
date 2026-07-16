<?php

namespace Odnavi\Orm\Service\Support;

use Soffio\Core\Contract\Cache;

/**
 * Держатель активного кэша ORM. По умолчанию — NullCache (no-op).
 * Приложение внедряет свою реализацию через set() при инициализации.
 */
final class Caching
{
    private static ?Cache $cache = null;

    /** Внедряет реализацию кэша. */
    public static function set(Cache $cache): void
    {
        self::$cache = $cache;
    }

    /** Возвращает активный кэш (NullCache, если не задан). */
    public static function get(): Cache
    {
        return self::$cache ??= new NullCache();
    }

    /** Сбрасывает кэш (например, в тестах). */
    public static function reset(): void
    {
        self::$cache = null;
    }
}
