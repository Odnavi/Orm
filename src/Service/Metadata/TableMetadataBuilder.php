<?php

namespace Odnavi\Orm\Service\Metadata;

use BackedEnum;
use Odnavi\Core\Service\AttributeReader;
use Odnavi\Core\Service\ReflectionFactory;
use Odnavi\Core\Util\StringUtil;
use Odnavi\Orm\Attribute\Column;
use Odnavi\Orm\Attribute\JoinColumn;
use ReflectionEnum;
use ReflectionNamedType;
use ReflectionProperty;

/** Собирает метаданные таблицы (колонки, join-колонки, первичный ключ) из рефлексии сущности. */
final class TableMetadataBuilder
{
    /**
     * Разбирает атрибуты сущности и её родителей в метаданные таблицы.
     *
     * @param string $entityClass FQCN класса сущности.
     * @return array{columns: Column[], joinColumns: JoinColumn[], primaryKey: string}
     */
    public function build(string $entityClass): array
    {
        $columns     = [];
        $joinColumns = [];
        $primaryKey  = '';

        $entityReflection = ReflectionFactory::getClass($entityClass);
        if ($entityReflection === null) {
            return ['columns' => $columns, 'joinColumns' => $joinColumns, 'primaryKey' => $primaryKey];
        }

        foreach (AttributeReader::getForProperties($entityReflection) as ['property' => $property, 'attrs' => $attrs]) {
            [$column, $joinColumn] = $this->pickAttributes($attrs);

            $propertyName = $property->getName();
            $columnName   = StringUtil::toSnakeCase($propertyName);

            if ($column !== null) {
                // Тип свойства не поддерживается — колонку не регистрируем.
                if (!$this->applyColumnType($column, $property)) {
                    continue;
                }

                $column->setPropertyName($propertyName);
                $column->setName($columnName);
                $columns[] = $column;

                if ($column->isPrimary()) {
                    $primaryKey = $columnName;
                }
                continue;
            }

            if ($joinColumn !== null) {
                $joinColumn->setPropertyName($propertyName);
                $joinColumn->setName($columnName);
                $joinColumns[] = $joinColumn;
            }
        }

        return ['columns' => $columns, 'joinColumns' => $joinColumns, 'primaryKey' => $primaryKey];
    }

    /**
     * Выбирает атрибуты Column и JoinColumn из уже инстанцированных фабрикой
     * атрибутов свойства.
     *
     * @param object[] $attrs Инстансы атрибутов свойства.
     * @return array{0: ?Column, 1: ?JoinColumn}
     */
    private function pickAttributes(array $attrs): array
    {
        $column = $joinColumn = null;

        foreach ($attrs as $attr) {
            if ($attr instanceof Column) {
                $column = $attr;
            } elseif ($attr instanceof JoinColumn) {
                $joinColumn = $attr;
            }
        }

        return [$column, $joinColumn];
    }

    /**
     * Выводит тип колонки из PHP-типа свойства, если он не задан явно.
     *
     * @return bool false — тип свойства не поддерживается, колонку регистрировать не нужно.
     */
    private function applyColumnType(Column $column, ReflectionProperty $property): bool
    {
        $propertyType = $property->getType();
        $typeName     = $propertyType instanceof ReflectionNamedType ? $propertyType->getName() : '';

        switch ($typeName) {
            case 'int':
            case 'string':
            case 'bool':
            case 'float':
                if ($column->getType() === '') {
                    $column->setType($typeName);
                }
                return true;

            case 'DateTime':
            case 'DateTimeImmutable':
                if ($column->getType() === '') {
                    $column->setType('datetime');
                }
                return true;

            default:
                if (!enum_exists($typeName) || !is_subclass_of($typeName, BackedEnum::class)) {
                    return false;
                }

                $backingType = (new ReflectionEnum($typeName))->getBackingType()?->getName();
                if ($backingType !== null && $column->getType() === '') {
                    $column->setType($backingType);
                }
                return true;
        }
    }
}
