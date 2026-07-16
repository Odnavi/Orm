<?php

namespace Odnavi\Orm\Service;

use Odnavi\Orm\Entity\AbstractEntity;

/**
 * Отслеживает исходное состояние сущностей и вычисляет изменения (changeset).
 * Минимальный аналог Doctrine UnitOfWork: только снимки значений и диф, без identity map,
 * каскадов и очередей вставок/апдейтов.
 *
 * Снимок хранится на самой сущности (см. AbstractEntity::ormSnapshot), а не в глобальной
 * статике по spl_object_id — это исключает утечку памяти и коллизию при повторном
 * использовании id объекта после сборки мусора.
 */
final class UnitOfWork
{
    /**
     * Фиксирует текущее состояние сущности как «чистое» (после загрузки из БД или сохранения).
     *
     * @param array<string, mixed> $values Сырые значения свойств: имя свойства => значение.
     */
    public static function snapshot(AbstractEntity $entity, array $values): void
    {
        $entity->ormSnapshot($values);
    }

    /**
     * Вычисляет изменённые свойства относительно снимка.
     *
     * @param array<string, mixed> $current Текущие сырые значения свойств.
     * @return array<string, array{0: mixed, 1: mixed}> Имя свойства => [старое, новое].
     */
    public static function computeChangeSet(AbstractEntity $entity, array $current): array
    {
        $original  = $entity->ormOriginal();
        $changeSet = [];

        foreach ($current as $property => $value) {
            $old = $original[$property] ?? null;
            if (!array_key_exists($property, $original) || $old !== $value) {
                $changeSet[$property] = [$old, $value];
            }
        }

        return $changeSet;
    }

    /** Убирает снимок сущности (например, после удаления). */
    public static function detach(AbstractEntity $entity): void
    {
        $entity->ormSnapshot([]);
    }
}
