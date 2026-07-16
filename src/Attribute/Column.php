<?php

namespace Odnavi\Orm\Attribute;

use Attribute;
use Odnavi\Orm\Entity\AbstractEntity;
use Odnavi\Orm\Service\Value\ColumnValueMapper;
use Odnavi\Orm\Service\Schema\ColumnDefinitionBuilder;

#[Attribute(Attribute::TARGET_PROPERTY)]
final class Column
{
    public const PRIMARY       = 0b00001;
    public const AUTO_GENERATE = 0b00010;
    public const REQUIRED      = 0b00100;
    public const UNIQUE        = 0b01000;
    public const INDEXED       = 0b10000;

    private string $propertyName = '';

    private static ColumnValueMapper $valueMapper;
    private static ColumnDefinitionBuilder $definitionBuilder;

    public function __construct(
        private readonly int $flags = 0,
        private string $type = '',
        private string $name = '',
        private readonly ?int $length = null,
        private readonly int|null|string $default = null,
        private readonly string $comment = ''
    ) {}

    /** Запоминает имя свойства сущности, к которому привязана колонка. */
    public function setPropertyName(string $propertyName): void
    {
        $this->propertyName = $propertyName;
    }

    /** Возвращает объявленный тип колонки. */
    public function getType(): string
    {
        return $this->type;
    }

    public function setType(string $value): void
    {
        $this->type = $value;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $value): void
    {
        $this->name = $value;
    }

    public function getLength(): ?int
    {
        return $this->length;
    }

    public function getDefault(): int|null|string
    {
        return $this->default;
    }

    public function getComment(): string
    {
        return $this->comment;
    }

    public function getPropertyName(): string
    {
        return $this->propertyName;
    }

    public function isPrimary(): bool
    {
        return (bool) ($this->flags & self::PRIMARY);
    }

    public function isRequired(): bool
    {
        return (bool) ($this->flags & self::REQUIRED);
    }

    public function isUnique(): bool
    {
        return (bool) ($this->flags & self::UNIQUE);
    }

    public function isAutoGenerate(): bool
    {
        return (bool) ($this->flags & self::AUTO_GENERATE);
    }

    public function isIndexed(): bool
    {
        return (bool) ($this->flags & self::INDEXED);
    }

    /** Формирует SQL-описание колонки для DDL. */
    public function getColumnDefinition(): string
    {
        return (self::$definitionBuilder ??= new ColumnDefinitionBuilder())->build($this);
    }

    /** Возвращает значение колонки из сущности, не вызывая геттер. */
    public function getValue(AbstractEntity $entity): mixed
    {
        return (self::$valueMapper ??= new ColumnValueMapper())->read($this, $entity);
    }

    /** Заполняет колонку сущности, не вызывая сеттер. */
    public function setValue(AbstractEntity $entity, mixed $value): void
    {
        (self::$valueMapper ??= new ColumnValueMapper())->write($this, $entity, $value);
    }
}
