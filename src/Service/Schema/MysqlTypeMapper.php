<?php

namespace Odnavi\Orm\Service\Schema;

/** Маппинг типов колонок в синтаксис MySQL. */
final class MysqlTypeMapper implements TypeMapper
{
    public function toSqlType(string $type, ?int $length): string
    {
        $isUnsigned = str_contains($type, 'unsigned');
        $isUnsigned && $type = trim(str_replace('unsigned', '', $type));

        $type = match ($type) {
            'string'             => $length ? "varchar($length)" : 'text',
            'bool'               => 'tinyint(1)',
            'int'                => 'int unsigned',
            'float'              => 'double',
            'varchar', 'tinyint' => $type . ($length ? "($length)" : ''),
            default              => $type,
        };

        $isUnsigned && $type .= ' unsigned';

        return $type;
    }
}
