<?php

namespace Odnavi\Orm\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY)]
class Entity
{
    public function __construct(
        private string $foreignKey,
        private string $class
    ) {}

    public function getForeignKey(): string
    {
        return $this->foreignKey;
    }

    public function getClass(): string
    {
        return $this->class;
    }
}