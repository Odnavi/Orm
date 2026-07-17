<?php

namespace Odnavi\Orm\Service;

use Odnavi\Core\DbRegistry;
use Odnavi\Orm\Entity\AbstractEntity;
use Odnavi\Orm\Repository\EntityRepository;
use RuntimeException;

/**
 * Единица работы уровня приложения: копит запланированные сохранения и удаления
 * и применяет их одной транзакцией во flush(). Аддитивна к немедленным
 * EntityRepository::create/update/delete — существующий код продолжает работать как есть.
 *
 * Живёт синглтоном на процесс (= на запрос). Очереди сбрасываются в Kernel на каждый
 * запрос и вручную через clear() в долгих процессах/тестах.
 */
final class EntityManager
{
    private static ?self $instance = null;

    /** @var array<int, AbstractEntity> Сущности к сохранению (insert/update), по spl_object_id. */
    private array $persist = [];

    /** @var array<int, AbstractEntity> Сущности к удалению, по spl_object_id. */
    private array $remove = [];

    public static function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    /**
     * Планирует сохранение сущности. insert или update выбирается во flush()
     * по наличию значения первичного ключа.
     */
    public function persist(AbstractEntity $entity): self
    {
        $id = spl_object_id($entity);
        unset($this->remove[$id]);
        $this->persist[$id] = $entity;
        return $this;
    }

    /** Планирует удаление сущности. */
    public function remove(AbstractEntity $entity): self
    {
        $id = spl_object_id($entity);
        unset($this->persist[$id]);
        $this->remove[$id] = $entity;
        return $this;
    }

    /** Очищает очереди без применения (между запросами в воркере/кроне и в тестах). */
    public function clear(): void
    {
        $this->persist = [];
        $this->remove  = [];
    }

    /**
     * Применяет все запланированные изменения одной транзакцией: при успехе фиксирует и
     * очищает очереди, при любой ошибке откатывает транзакцию, сохраняет очереди и
     * пробрасывает исключение.
     *
     * @throws \Throwable Ошибка одной из операций после отката транзакции.
     */
    public function flush(): void
    {
        if (!$this->persist && !$this->remove) {
            return;
        }

        DbRegistry::get()->transactional(function (): void {
            foreach ($this->persist as $entity) {
                $repo = RepositoryFactory::get($entity::class);
                $ok   = $this->isNew($entity, $repo)
                    ? $repo->create($entity)
                    : $repo->update($entity);

                if (!$ok) {
                    throw new RuntimeException('Flush: не удалось сохранить ' . $entity::class);
                }
            }

            foreach ($this->remove as $entity) {
                if (!RepositoryFactory::get($entity::class)->delete($entity)) {
                    throw new RuntimeException('Flush: не удалось удалить ' . $entity::class);
                }
            }
        });

        $this->persist = [];
        $this->remove  = [];
    }

    /** Новая сущность (нужен insert), если первичный ключ ещё не присвоен. */
    private function isNew(AbstractEntity $entity, EntityRepository $repo): bool
    {
        $primary = $repo->getPrimaryValue($entity);
        return $primary === null || $primary === 0 || $primary === '';
    }
}
