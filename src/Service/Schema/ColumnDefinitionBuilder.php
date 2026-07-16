<?php

namespace Odnavi\Orm\Service\Schema;

use Odnavi\Orm\Attribute\Column;

/** Формирует SQL-описание колонки (DDL) на основе её декларации. */
final readonly class ColumnDefinitionBuilder
{
    public function __construct(
        private TypeMapper $typeMapper = new MysqlTypeMapper()
    ) {}

    public function build(Column $column): string
    {
        $sqlType    = $this->typeMapper->toSqlType($column->getType(), $column->getLength());
        $definition = "{$column->getName()} $sqlType";

        $default = $column->getDefault();
        if ($default !== null) {
            $definition .= is_string($default)
                ? " DEFAULT '$default'"
                : " DEFAULT $default";
        }

        if (!$column->isPrimary()) {
            $definition .= $column->isRequired() ? ' NOT NULL' : ' NULL';

            if ($column->isUnique()) {
                $definition .= ' UNIQUE';
            }
        }

        if ($column->isAutoGenerate()) {
            $definition .= ' AUTO_INCREMENT';
        }

        if ($column->isPrimary()) {
            $definition .= ' PRIMARY KEY';
        }

        if ($column->getComment()) {
            $definition .= " COMMENT '{$column->getComment()}'";
        }

        return $definition;
    }
}
