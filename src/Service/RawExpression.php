<?php

namespace Odnavi\Orm\Service;

/**
 * Сырое SQL-выражение для SET с привязываемыми аргументами.
 * Используется, когда значение колонки нельзя передать литералом
 * (например, ссылка на другую колонку: `balance + ?`).
 */
final readonly class RawExpression
{
    /**
     * @param string $expr Выражение SQL с плейсхолдерами `?`.
     * @param array  $args Аргументы для плейсхолдеров выражения.
     */
    public function __construct(
        public string $expr,
        public array $args = []
    ) {}
}
