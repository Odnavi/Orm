<?php

namespace Odnavi\Orm\Service\Support;

use Odnavi\Core\Contract\Cache;

/**
 * Пустая реализация кэша (no-op). Используется по умолчанию, пока приложение
 * не внедрит настоящий адаптер через Caching::set().
 */
final class NullCache implements Cache
{
    public function get(string $key): mixed
    {
        return null;
    }

    public function set(string $key, mixed $value, ?int $ttl = null): void {}

    public function delete(string $key): void {}
}
