<?php

namespace Odnavi\Orm\Service\Hydration;

use Odnavi\Core\Service\AttributeReader;
use Odnavi\Core\Service\ReflectionFactory;
use Odnavi\Orm\Attribute\Entity;
use Odnavi\Orm\Entity\AbstractEntity;
use Odnavi\Orm\Service\RepositoryFactory;
use ReflectionProperty;

/** Догружает связанные сущности (свойства с атрибутом #[Entity]) одним запросом на тип. */
final class RelationPreloader
{
    public function preload(AbstractEntity $entity): void
    {
        $relations = $this->collectRelations($entity);
        if (!$relations) {
            return;
        }

        $relatedById = $this->loadRelated($entity, $relations);

        foreach ($relations as $entityClass => $relation) {
            $id = $entity->{$relation['getter']}();
            if (!empty($relatedById[$entityClass][$id])) {
                $entity->{$relation['setter']}($relatedById[$entityClass][$id]);
            }
        }
    }

    /**
     * Собирает описания связей из атрибутов #[Entity] на свойствах сущности.
     *
     * @return array<class-string, array{name: string, foreign_key: string, setter: string, getter: string}>
     */
    private function collectRelations(AbstractEntity $entity): array
    {
        $reflection = ReflectionFactory::getClass($entity::class);
        if ($reflection === null) {
            return [];
        }

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

            $entityClass = $relation->getClass();
            if (!$entityClass) {
                continue;
            }

            $foreignKey = $relation->getForeignKey();
            $name       = $property->getName();

            $relations[$entityClass] = [
                'name'        => $name,
                'foreign_key' => $foreignKey,
                'setter'      => 'set' . ucfirst($name),
                'getter'      => 'get' . ucfirst($foreignKey),
            ];
        }

        return $relations;
    }

    /**
     * Загружает связанные сущности батчем по каждому типу.
     *
     * @param array<class-string, array{getter: string}> $relations
     * @return array<class-string, array<int|string, AbstractEntity>>
     */
    private function loadRelated(AbstractEntity $entity, array $relations): array
    {
        $ids = [];
        foreach ($relations as $entityClass => $relation) {
            $value = $entity->{$relation['getter']}();
            if ($value) {
                $ids[$entityClass][] = $value;
            }
        }

        $relatedById = [];
        foreach ($relations as $entityClass => $relation) {
            if (empty($ids[$entityClass])) {
                continue;
            }

            $relatedById[$entityClass] = RepositoryFactory::get($entityClass)
                ->findAll(['id' => array_filter(array_unique($ids[$entityClass]))])
                ->pluck(null, fn($related) => $related->getId());
        }

        return $relatedById;
    }
}
