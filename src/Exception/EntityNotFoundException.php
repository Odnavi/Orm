<?php

namespace Odnavi\Orm\Exception;

use RuntimeException;

/**
 * Сущность не найдена в хранилище. Бросается EntityRepository::find/findOneBy.
 *
 * Несёт statusCode 404 (без зависимости от HTTP-слоя) — обработчик ошибок
 * приложения может использовать его для ответа.
 */
class EntityNotFoundException extends RuntimeException
{
    /** @var int */
    protected $code = 2;
    /** @var string */
    protected $message = 'Данные не найдены.';
}
