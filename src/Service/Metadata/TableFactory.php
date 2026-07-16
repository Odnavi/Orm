<?php

namespace Odnavi\Orm\Service\Metadata;

use Soffio\Core\Service\AttributeReader;
use Soffio\Core\Service\ReflectionFactory;
use Odnavi\Orm\Attribute\Table;
use Odnavi\Orm\Service\Support\Caching;
use ReflectionClass;

class TableFactory
{
    /**
     * Возвращает метаданные таблицы сущности. Результат кэшируется на процесс
     * (статически) и, если приложение внедрило разделяемый кэш, между запросами.
     */
    public static function get(string $entityClass): ?Table
    {
        /** @var array<string, ?Table> $cache */
        static $cache = [];

        if (array_key_exists($entityClass, $cache)) {
            return $cache[$entityClass];
        }

        $reflection = ReflectionFactory::getClass($entityClass);
        if (!$reflection) {
            return $cache[$entityClass] = null;
        }

        $key   = self::cacheKey($entityClass, $reflection);
        $table = $key ? Caching::get()->get($key) : null;

        if (!$table instanceof Table) {
            $table = self::build($entityClass, $reflection);
            // Прогреваем ленивые метаданные до сериализации, чтобы кэш реально
            // экономил разбор атрибутов, а не только рефлексию класса.
            $table && $table->getColumns();
            $table && $key && Caching::get()->set($key, $table);
        }

        return $cache[$entityClass] = $table;
    }

    /** Собирает объект таблицы из атрибута #[Table] сущности. */
    private static function build(string $entityClass, ReflectionClass $reflection): ?Table
    {
        /** @var Table[] $tables */
        $tables = AttributeReader::getForClass($reflection, Table::class);
        if (!$tables) {
            return null;
        }

        $table = $tables[0];
        $table->setEntityClass($entityClass);

        return $table;
    }

    /**
     * Формирует ключ разделяемого кэша с меткой времени файла сущности —
     * при изменении кода метаданные инвалидируются автоматически.
     */
    private static function cacheKey(string $entityClass, ReflectionClass $reflection): ?string
    {
        $file  = $reflection->getFileName();
        $mtime = $file ? @filemtime($file) : false;

        return $mtime === false
            ? null
            : 'orm.table.' . md5($entityClass) . '.' . $mtime;
    }
}
