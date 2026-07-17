<?php

namespace Odnavi\Orm\Service\Support;

use Odnavi\Core\CacheRegistry;
use Odnavi\Core\Contract\Cache;

/**
 * Тонкий фасад над Core\CacheRegistry для кода ORM. Единый источник кэша —
 * реестр ядра, который приложение наполняет при инициализации; по умолчанию
 * там NullCache (no-op).
 */
final class Caching
{
    /** Внедряет реализацию кэша. */
    public static function set(Cache $cache): void
    {
        CacheRegistry::set($cache);
    }

    /** Возвращает активный кэш (NullCache, если не задан). */
    public static function get(): Cache
    {
        return CacheRegistry::get();
    }

    /** Сбрасывает кэш (например, в тестах). */
    public static function reset(): void
    {
        CacheRegistry::reset();
    }
}
