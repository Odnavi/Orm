<?php

namespace Odnavi\Orm\Service\Value;

use BackedEnum;
use DateTime;
use DateTimeImmutable;

/** Приведение значений между скалярами и типами свойств сущности (дата/время, backed enum, встроенные). */
final class ValueCaster
{
    private const DATETIME_TYPES = ['DateTime', 'DateTimeImmutable'];

    /** Является ли тип классом даты/времени. */
    public static function isDateTime(string $type): bool
    {
        return in_array($type, self::DATETIME_TYPES, true);
    }

    /** Является ли тип backed enum. */
    public static function isBackedEnum(string $type): bool
    {
        return enum_exists($type) && is_subclass_of($type, BackedEnum::class);
    }

    /** Приводит значение к встроенному типу PHP. */
    public static function toBuiltin(mixed $value, string $type): mixed
    {
        settype($value, $type);
        return $value;
    }

    /**
     * Создаёт объект даты/времени указанного класса из значения по формату.
     *
     * @param class-string<DateTime|DateTimeImmutable> $class
     */
    public static function toDateTime(string $class, mixed $value, string $format): DateTime|DateTimeImmutable|false
    {
        return $class::createFromFormat($format, (string) $value);
    }

    /**
     * Приводит значение к backed enum указанного класса, null если не подошло.
     *
     * @param class-string<BackedEnum> $enumClass
     */
    public static function toEnum(string $enumClass, mixed $value): ?BackedEnum
    {
        return $enumClass::tryFrom($value);
    }
}
