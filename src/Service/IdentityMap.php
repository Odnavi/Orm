<?php

namespace Odnavi\Orm\Service;

use Odnavi\Orm\Entity\AbstractEntity;

/**
 * Реестр загруженных сущностей: один объект на пару (класс, первичный ключ)
 * в пределах запроса — аналог identity map Doctrine.
 *
 * Живёт синглтоном на процесс (= на запрос в classic PHP-per-request и в
 * поминутных cron-вызовах). В долгоживущих процессах и между тест-кейсами
 * карту нужно сбрасывать через clear().
 */
final class IdentityMap
{
    private static ?self $instance = null;

    /** @var array<class-string, array<int|string, AbstractEntity>> */
    private array $entities = [];

    public static function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    /** Сбрасывает карту (между запросами в воркере/кроне и в тестах). */
    public function clear(): void
    {
        $this->entities = [];
    }

    /** Возвращает ранее загруженную сущность или null. */
    public function get(string $class, int|string $id): ?AbstractEntity
    {
        return $this->entities[$class][$id] ?? null;
    }

    /** Регистрирует сущность в карте. */
    public function put(string $class, int|string $id, AbstractEntity $entity): void
    {
        $this->entities[$class][$id] = $entity;
    }

    /** Убирает сущность из карты (например, после удаления). */
    public function remove(string $class, int|string $id): void
    {
        unset($this->entities[$class][$id]);
    }
}
