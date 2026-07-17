<?php

namespace Odnavi\Orm\Attribute;

use Attribute;
use Odnavi\Core\Service\ReflectionFactory;
use Odnavi\Orm\Entity\AbstractEntity;
use Odnavi\Orm\Service\Metadata\TableMetadataBuilder;
use Odnavi\Orm\Service\UnitOfWork;

#[Attribute(Attribute::TARGET_CLASS)]
final class Table
{
    /** @var Column[] */
    private array  $columns = [];
    /** @var JoinColumn[] */
    private array  $joinColumns = [];
    private array  $indexes = [];
    private string $primaryKey = '';

    private string $name;
    private string $entityClass;
    private bool   $initAttributes = false;

    public function __construct(string $name, array $indexes = [])
    {
        $this->name    = $name;
        $this->indexes = $indexes;
    }

    /** Запоминает FQCN класса сущности, к которому привязана таблица. */
    public function setEntityClass(string $entityClass): void
    {
        $this->entityClass = $entityClass;
    }

    /** Возвращает FQCN класса сущности, к которому привязана таблица. */
    public function getEntityClass(): string
    {
        return $this->entityClass;
    }

    /** @return Column[] */
    public function getColumns(): array
    {
        $this->initAttributes();
        return $this->columns;
    }

    /** @return JoinColumn[] */
    public function getJoinColumns(): array
    {
        $this->initAttributes();
        return $this->joinColumns;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getPrimaryKey(): string
    {
        $this->initAttributes();
        return $this->primaryKey;
    }

    /** @return array[] */
    public function getIndexes(): array
    {
        return $this->indexes;
    }

    /** Создаёт новый пустой экземпляр сущности таблицы. */
    public function newInstance(): AbstractEntity
    {
        /** @var AbstractEntity $entity */
        $entity = ReflectionFactory::getClass($this->entityClass)->newInstance();
        return $entity;
    }

    /** Фиксирует текущее состояние сущности как «чистое». Требуется после занесения изменений в БД. */
    public function flushValue(AbstractEntity $entity): void
    {
        UnitOfWork::snapshot($entity, $this->currentValues($entity));
    }

    /**
     * Собирает сырые значения свойств колонок сущности.
     *
     * @return array<string, mixed> Имя свойства => значение.
     */
    public function currentValues(AbstractEntity $entity): array
    {
        $values = [];
        foreach ($this->getColumns() as $column) {
            $property   = $column->getPropertyName();
            $reflection = ReflectionFactory::getProperty($entity::class, $property);

            $values[$property] = $reflection && $reflection->isInitialized($entity)
                ? $reflection->getValue($entity)
                : null;
        }

        return $values;
    }

    /** Лениво собирает метаданные таблицы из атрибутов сущности. */
    private function initAttributes(): void
    {
        if ($this->initAttributes) {
            return;
        }

        $metadata = (new TableMetadataBuilder())->build($this->entityClass);

        $this->columns     = $metadata['columns'];
        $this->joinColumns = $metadata['joinColumns'];
        $this->primaryKey  = $metadata['primaryKey'];

        $this->initAttributes = true;
    }
}
