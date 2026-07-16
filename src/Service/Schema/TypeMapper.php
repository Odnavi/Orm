<?php

namespace Odnavi\Orm\Service\Schema;

interface TypeMapper
{
    /** Преобразует объявленный тип колонки в SQL-тип конкретной СУБД. */
    public function toSqlType(string $type, ?int $length): string;
}
