<?php

namespace Odnavi\Orm\Service\Hydration;

use Odnavi\Orm\Attribute\Table;
use Odnavi\Orm\Entity\AbstractEntity;
use Odnavi\Orm\Service\IdentityMap;

/** Создаёт сущности и наполняет их данными строки из БД. */
final class EntityHydrator
{
    /**
     * Создаёт сущность и наполняет её значениями колонок из строки данных.
     *
     * Если сущность с тем же первичным ключом уже загружена в этом запросе,
     * возвращается тот же объект (identity map). При этом из свежих данных
     * обновляются только «чистые» (неизменённые) поля — несохранённые
     * изменения в памяти не затираются.
     */
    public function hydrate(Table $table, array $data): AbstractEntity
    {
        static $cache = [];

        $class      = $table->getEntityClass();
        $primaryKey = $table->getPrimaryKey();
        $id         = $primaryKey !== '' && isset($data[$primaryKey]) ? $data[$primaryKey] : null;

        $map = IdentityMap::getInstance();

        if ($id !== null && ($existing = $map->get($class, $id)) !== null) {
            $this->refreshClean($table, $existing, $data);
            return $existing;
        }

        $entity = clone $cache[$table->getName()] ??= $table->newInstance();

        foreach ($table->getColumns() as $column) {
            $column->setValue($entity, $data[$column->getName()] ?? null);
        }

        foreach ($table->getJoinColumns() as $column) {
            $column->setValue($entity, $data[$column->getName()] ?? null);
        }

        $table->flushValue($entity);

        $id !== null && $map->put($class, $id, $entity);

        return $entity;
    }

    /**
     * Обновляет из свежих данных только «чистые» (совпадающие со снимком) колонки,
     * сохраняя несохранённые изменения в памяти. Join-колонки (связи) не трогаются.
     */
    private function refreshClean(Table $table, AbstractEntity $entity, array $data): void
    {
        $original = $entity->ormOriginal();
        $current  = $table->currentValues($entity);

        $cleanProps = [];
        foreach ($table->getColumns() as $column) {
            $property = $column->getPropertyName();
            $isClean  = array_key_exists($property, $original)
                && ($current[$property] ?? null) === ($original[$property] ?? null);

            if ($isClean) {
                $column->setValue($entity, $data[$column->getName()] ?? null);
                $cleanProps[] = $property;
            }
        }

        if (!$cleanProps) {
            return;
        }

        // Пере-снимок: обновлённые чистые поля фиксируем на новых значениях,
        // грязные остаются в снимке как были — то есть остаются изменёнными.
        $refreshed = $table->currentValues($entity);
        $snapshot  = $original;
        foreach ($cleanProps as $property) {
            $snapshot[$property] = $refreshed[$property];
        }

        $entity->ormSnapshot($snapshot);
    }
}
