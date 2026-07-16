<?php

namespace Odnavi\Orm\Service\Value;

use BackedEnum;
use Soffio\Core\Service\ReflectionFactory;
use Odnavi\Orm\Entity\AbstractEntity;
use ReflectionProperty;

/** Доступ к свойствам сущности (магические геттеры/сеттеры и инициализация дефолтами) с приведением типов. */
final class PropertyAccessor
{
    /** Читает значение свойства с приведением для доменного слоя (магический геттер). */
    public static function get(AbstractEntity $entity, string $property, array $params): mixed
    {
        $reflection = ReflectionFactory::getProperty($entity::class, $property);
        if ($reflection === null) {
            return null;
        }

        $value = $reflection->isInitialized($entity) ? $reflection->getValue($entity) : null;
        $type  = $reflection->getType();

        if ($type === null) {
            return $value;
        }

        // Тип DateTime.
        if (ValueCaster::isDateTime($type->getName())) {
            if ($type->allowsNull() && !$value) {
                return null;
            }

            $format = $params[0] ?? 'U';
            $value  = $value->format($format);
            return $format === 'U' ? (int) $value : $value;
        }

        if ($value instanceof BackedEnum) {
            return ($params[0] ?? false) ? $value : $value->value;
        }

        return $value;
    }

    /** Записывает значение свойства с приведением для доменного слоя (магический сеттер). */
    public static function set(AbstractEntity $entity, string $property, array $params): void
    {
        $reflection = ReflectionFactory::getProperty($entity::class, $property);
        if ($reflection === null) {
            return;
        }

        $value = $params[0] ?? null;
        $type  = $reflection->getType();

        if ($type === null) {
            $reflection->setValue($entity, $value);
            return;
        }

        // Разрешён null.
        if ($type->allowsNull() && $value === null) {
            return;
        }

        $typeName = $type->getName();

        // Тип DateTime.
        if (ValueCaster::isDateTime($typeName)) {
            if ($type->allowsNull() && !$value) {
                $reflection->setValue($entity, null);
                return;
            }

            !$value && $value = time();
            $reflection->setValue($entity, ValueCaster::toDateTime($typeName, $value, $params[1] ?? 'U'));
            return;
        }

        if ($type->isBuiltin()) {
            $value = ValueCaster::toBuiltin($value, $typeName);
        } elseif (!$value instanceof $typeName) {
            if (ValueCaster::isBackedEnum($typeName)) {
                $value = ValueCaster::toEnum($typeName, $value);
            } else {
                return;
            }
        }

        $current = $reflection->isInitialized($entity) ? $reflection->getValue($entity) : null;
        if ($current === null || $current !== $value) {
            $reflection->setValue($entity, $value);
        }
    }

    /** Инициализирует незаполненные свойства сущности значениями по умолчанию согласно их типам. */
    public static function initializeDefaults(AbstractEntity $entity): void
    {
        $reflection = ReflectionFactory::getClass($entity::class);

        foreach ($reflection->getProperties(ReflectionProperty::IS_PROTECTED) as $property) {
            if ($property->isStatic() || $property->isInitialized($entity)) {
                continue;
            }

            $type = $property->getType();
            if ($type === null) {
                continue;
            }

            if ($type->allowsNull()) {
                $property->setValue($entity, null);
                continue;
            }

            if ($type->isBuiltin()) {
                $property->setValue($entity, ValueCaster::toBuiltin(null, $type->getName()));
                continue;
            }

            $typeName = $type->getName();
            if (ValueCaster::isBackedEnum($typeName)) {
                $property->setValue($entity, $typeName::cases()[0]);
            }
        }
    }
}
