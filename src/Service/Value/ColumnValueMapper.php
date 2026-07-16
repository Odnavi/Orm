<?php

namespace Odnavi\Orm\Service\Value;

use BackedEnum;
use Soffio\Core\Service\ReflectionFactory;
use DateTimeInterface;
use Odnavi\Orm\Attribute\Column;
use Odnavi\Orm\Entity\AbstractEntity;

/** Читает и записывает значения колонок в сущность через рефлексию, минуя геттеры/сеттеры. */
final class ColumnValueMapper
{
    private const DATE_FORMATS = [
        'date'     => 'Y-m-d',
        'datetime' => 'Y-m-d H:i:s',
    ];

    /** Читает значение колонки из сущности, не вызывая геттер. */
    public function read(Column $column, AbstractEntity $entity): mixed
    {
        $reflection = ReflectionFactory::getProperty($entity::class, $column->getPropertyName());

        $value = $reflection && $reflection->isInitialized($entity)
            ? $reflection->getValue($entity)
            : null;

        // При возможности хранения null преобразовываем пустые строки.
        if (!$column->isRequired() && is_string($value) && $value === '') {
            $value = null;
        }

        if ($value instanceof DateTimeInterface) {
            $value = match ($column->getType()) {
                'datetime' => $value->format('Y-m-d H:i:s'),
                'date'     => $value->format('Y-m-d'),
                default    => $value,
            };
        }

        if ($value instanceof BackedEnum) {
            $value = $value->value;
        }

        return $value;
    }

    /** Заполняет колонку сущности, не вызывая сеттер. */
    public function write(Column $column, AbstractEntity $entity, mixed $value): void
    {
        $reflection = ReflectionFactory::getProperty($entity::class, $column->getPropertyName());
        if ($reflection === null) {
            return;
        }

        $propertyType = $reflection->getType();

        if ($propertyType !== null) {
            $typeName   = $propertyType->getName();
            $columnType = $column->getType();

            // Тип колонки datetime/date.
            if (ValueCaster::isDateTime($typeName) && isset(self::DATE_FORMATS[$columnType])) {
                $value = ValueCaster::toDateTime($typeName, $value, self::DATE_FORMATS[$columnType]);
                if (!$value) {
                    return;
                }

                if ($columnType === 'date') {
                    $value = $value->setTime(0, 0, 0);
                }
            }

            if (ValueCaster::isBackedEnum($typeName)) {
                if ($value instanceof $typeName) {
                    $reflection->setValue($entity, $value);
                    return;
                }

                if ($value === null) {
                    return;
                }

                $value = ValueCaster::toEnum($typeName, $value);
            }
        }

        isset($value) && $reflection->setValue($entity, $value);
    }
}
