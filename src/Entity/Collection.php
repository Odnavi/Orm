<?php

namespace Odnavi\Orm\Entity;

use ArrayAccess;
use ArrayIterator;
use Odnavi\Core\Service\AttributeReader;
use Odnavi\Core\Service\ReflectionFactory;
use Countable;
use IteratorAggregate;
use Odnavi\Orm\Attribute\Entity;
use Odnavi\Orm\Service\RepositoryFactory;
use ReflectionProperty;

class Collection implements Countable, IteratorAggregate, ArrayAccess
{
    private int $total = 0;

    /**
     * @param string $entityClass
     * @param AbstractEntity[] $collection
     */
    public function __construct(private string $entityClass, private array $collection = []) {}

    public function setTotal(int $value): void
    {
        $this->total = $value;
    }

    public function getTotal(): int
    {
        return $this->total;
    }

    /**
     * Применяет пользовательскую функцию к каждому члену коллекции
     *
     * @param callable $callback
     *
     * @return self
     * @see array_walk
     */
    public function walk(callable $callback): self
    {
        array_walk($this->collection, $callback);
        return $this;
    }

    public function map(callable $callback): array
    {
        return array_filter(array_map($callback, $this->collection));
    }

    /**
     * Извлекает значения
     *
     * @param callable|null $callbackValue Если оставить null, будет использована вся сущность
     * @param callable|null $callbackIndex Если оставить null, то результат будет нумерованный массив
     *
     * @return array
     */
    public function pluck(?callable $callbackValue = null, ?callable $callbackIndex = null): array
    {
        return $this->reduce(function ($carry, AbstractEntity $entity) use ($callbackValue, $callbackIndex) {
            $value = $callbackValue ? $callbackValue($entity) : $entity;

            $callbackIndex
                ? $carry[$callbackIndex($entity)] = $value
                : $carry[] = $value;

            return $carry;
        });
    }

    public function reduce(callable $callback, array $initial = []): array
    {
        return array_reduce($this->collection, $callback, $initial);
    }

    public function __toString(): string
    {
        return $this->count() > 0 ? '1' : '';
    }

    // Методы Countable, IteratorAggregate, ArrayAccess

    public function getIterator(): ArrayIterator
    {
        return new ArrayIterator($this->collection);
    }

    public function offsetExists($offset): bool
    {
        return isset($this->collection[$offset]);
    }

    public function offsetGet($offset): ?AbstractEntity
    {
        return $this->collection[$offset] ?? null;
    }

    public function offsetSet($offset, $value): void
    {
        is_null($offset)
            ? $this->collection[] = $value
            : $this->collection[$offset] = $value;
    }

    public function offsetUnset($offset): void
    {
        unset($this->collection[$offset]);
    }

    public function count(): int
    {
        return count($this->collection);
    }

    public function toArray(?callable $callback = null): array
    {
        $this->preloadRelations();
        return $this->map($callback ?? fn (AbstractEntity $entity) => $entity->toArray());
    }// Убедитесь, что вы правильно подключили атрибут Entity

    public function preloadRelations(): void
    {
        if (empty($this->collection)) {
            return; // Нет элементов в коллекции
        }

        // Кэш свойств с атрибутом Entity
        $reflection = ReflectionFactory::getClass($this->entityClass);
        if ($reflection === null) {
            return;
        }
        // Группируем все сущности по типу
        $relations = [];
        foreach (AttributeReader::getForProperties($reflection, ReflectionProperty::IS_PUBLIC | ReflectionProperty::IS_PROTECTED | ReflectionProperty::IS_PRIVATE) as ['property' => $property, 'attrs' => $attrs]) {
            $relation = null;
            foreach ($attrs as $attr) {
                if ($attr instanceof Entity) {
                    $relation = $attr;
                    break;
                }
            }

            if ($relation === null) {
                continue;
            }

            $name       = $property->getName();
            $foreignKey = $relation->getForeignKey();

            $relations[$relation->getClass()] = [
                'name'        => $name,
                'foreign_key' => $foreignKey,
                'setter'      => 'set' . ucfirst($name),
                'getter'      => 'get' . ucfirst($foreignKey),
            ];
        }

        if (!$relations) {
            return;
        }

        $data = $this->reduce(function (array $carry, AbstractEntity $entity) use ($relations) {
            foreach ($relations as $entityClass => $args) {
                $carry[$entityClass][] = $entity->{$args['getter']}();
            }
            return $carry;
        });

        $relationsData = [];
        // Загружаем сущности одним запросом для каждого типа
        foreach ($relations as $entityClass => $args) {
            if (!empty($data[$entityClass])) {
                $ids = array_filter(array_unique($data[$entityClass]));
                $ids && $relationsData[$entityClass] = RepositoryFactory::get($entityClass)
                    ->findAll(['id' => $ids])
                    ->pluck(null, fn($entity) => $entity->getId());
            }
        }

        foreach ($this->collection as $entity) {
            foreach ($relations as $entityClass => $args) {
                $id = $entity->{$args['getter']}();
                if (!empty($relationsData[$entityClass][$id])) {
                    $entity->{$args['setter']}($relationsData[$entityClass][$id]);
                }
            }
        }
    }
}