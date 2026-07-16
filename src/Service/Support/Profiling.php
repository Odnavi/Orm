<?php

namespace Odnavi\Orm\Service\Support;

use Soffio\Core\Contract\Profiler;

/**
 * Держатель активного профайлера ORM. По умолчанию — NullProfiler (no-op).
 * Приложение может внедрить свою реализацию через set().
 */
final class Profiling
{
    private static ?Profiler $profiler = null;

    /** Внедряет реализацию профайлера. */
    public static function set(Profiler $profiler): void
    {
        self::$profiler = $profiler;
    }

    /** Возвращает активный профайлер (NullProfiler, если не задан). */
    public static function get(): Profiler
    {
        return self::$profiler ??= new NullProfiler();
    }
}
