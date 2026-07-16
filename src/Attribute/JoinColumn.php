<?php

namespace Odnavi\Orm\Attribute;

use Attribute;
use Soffio\Core\Service\ReflectionFactory;
use Odnavi\Orm\Entity\AbstractEntity;

#[Attribute(Attribute::TARGET_PROPERTY)]
class JoinColumn
{
    private string $propertyName = '';

    public function __construct(
        public string $name,
        public string $targetTable,
        public string $targetColumn,
        public string $refTargetColumn,
        public ?string $refColumn = null
    ) {}

    /** Запоминает имя свойства сущности, к которому привязана колонка. */
    public function setPropertyName(string $propertyName): void
    {
        $this->propertyName = $propertyName;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $value): void
    {
        $this->name = $value;
    }

    /** Заполняет колонку сущности, не вызывая сеттер. */
    public function setValue(AbstractEntity $entity, $value): void
    {
        if (!isset($value)) {
            return;
        }

        $reflection = ReflectionFactory::getProperty($entity::class, $this->propertyName);
        $reflection && $reflection->setValue($entity, $value);
    }

    public function getTargetTable(): string
    {
        return $this->targetTable;
    }

    public function getTargetColumn(): string
    {
        return $this->targetColumn;
    }

    public function getRefTargetColumn(): string
    {
        return $this->refTargetColumn;
    }

    public function getRefColumn(): ?string
    {
        return $this->refColumn;
    }
}