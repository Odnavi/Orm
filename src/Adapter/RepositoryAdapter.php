<?php

namespace Odnavi\Orm\Adapter;

use InvalidArgumentException;
use Odnavi\Core\Contract\{Entity, EntityCollection, Repository};
use Odnavi\Orm\Entity\AbstractEntity;
use Odnavi\Orm\Repository\EntityRepository;

/**
 * Оборачивает EntityRepository в контракт Core\Contract\Repository, которым
 * пользуется роутинг. EntityRepository не реализует контракт напрямую: его
 * методы create/update/delete типизированы конкретным AbstractEntity, а
 * интерфейс требует Entity — сузить параметр при реализации интерфейса нельзя.
 */
final class RepositoryAdapter implements Repository
{
    public function __construct(private readonly EntityRepository $repo)
    {
    }

    public function find(int|string $id): Entity
    {
        return $this->repo->find($id);
    }

    public function findAll(
        array $criteria = [],
        ?array $orderBy = null,
        ?int $limit = null,
        ?int $offset = null,
        bool $withTotal = false
    ): EntityCollection {
        return $this->repo->findAll($criteria, $orderBy, $limit, $offset, $withTotal);
    }

    public function create(Entity $entity): bool
    {
        return $this->repo->create($this->assertAbstractEntity($entity));
    }

    public function update(Entity $entity): bool
    {
        return $this->repo->update($this->assertAbstractEntity($entity));
    }

    public function delete(Entity $entity): bool
    {
        return $this->repo->delete($this->assertAbstractEntity($entity));
    }

    public function getEntityClass(): string
    {
        return $this->repo->getEntityClass();
    }

    private function assertAbstractEntity(Entity $entity): AbstractEntity
    {
        if (!$entity instanceof AbstractEntity) {
            throw new InvalidArgumentException(
                'Ожидалась ORM-сущность ' . AbstractEntity::class . ', получено ' . $entity::class . '.'
            );
        }

        return $entity;
    }
}
