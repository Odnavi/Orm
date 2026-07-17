<?php

namespace Odnavi\Orm\Entity;

use BadMethodCallException;
use Odnavi\Core\Service\ReflectionFactory;
use Odnavi\Core\Util\StringUtil;
use Odnavi\Orm\Attribute\Table;
use Odnavi\Orm\Service\Hydration\RelationPreloader;
use Odnavi\Orm\Service\Metadata\TableFactory;
use Odnavi\Orm\Service\Value\PropertyAccessor;
use ReflectionProperty;

class AbstractEntity
{
    private ?Table $table;

    private array $propertyCache = [];

    /** Снимок «чистых» значений свойств для вычисления изменений. */
    private array $ormOriginalData = [];

    public function __construct()
    {
        $class       = get_class($this);
        $this->table = TableFactory::get($class);

        PropertyAccessor::initializeDefaults($this);

        // Фиксируем значения
        $this->table && $this->table->flushValue($this);
    }

    /**
     * Обрабатывает вызовы несуществующих методов
     *
     * @param $name
     * @param $params
     *
     * @return self|mixed
     */
    public function __call($name, $params)
    {
        $isGetter = str_starts_with($name, 'get');
        $isSetter = str_starts_with($name, 'set');
        $message  = "Попытка вызвать несуществующий метод: $name.";

        if (!$isGetter && !$isSetter) {
            throw new BadMethodCallException($message);
        }

        // Проверка через кэш
        if (!isset($this->propertyCache[$name])) {
            $column                     = lcfirst(substr($name, 3));
            $propertyExist              = property_exists($this, $column);
            $this->propertyCache[$name] = [
                'name'            => $column,
                'property_exists' => $propertyExist
            ];
        } else {
            $column        = $this->propertyCache[$name]['name'];
            $propertyExist = $this->propertyCache[$name]['property_exists'];
        }

        if ($propertyExist) {
            if ($isGetter) {
                return PropertyAccessor::get($this, $column, $params);
            }

            if ($isSetter) {
                PropertyAccessor::set($this, $column, $params);
                return $this;
            }
        }

        throw new BadMethodCallException($message);
    }

    /**
     * Заполняет сущность данными из массива
     *
     * @param array $data
     *
     * @return self
     */
    final public function fromArray(array $data): self
    {
        if (!$this->table) {
            return $this;
        }

        $columns       = $this->table->getColumns();
        $propertyNames = array_map(fn($column) => $column->getPropertyName(), $columns);

        foreach ($propertyNames as $propertyName) {
            $snakeCase = StringUtil::toSnakeCase($propertyName);
            if (!isset($data[$snakeCase])) {
                continue;
            }

            $setterFunc = 'set' . ucfirst($propertyName);
            $this->{$setterFunc}($data[$snakeCase]);
        }

        return $this;
    }

    /**
     * Преобразует сущность в массив
     *
     * @param array|null $fields
     *
     * @return array
     */
    final public function toArray(?array $fields = null): array
    {
        $item = [];

        // TODO 26-04-2022 parfentev: Добавить сортировку вызова геттеров и скрытие свойств из ответа

        $properties = $this->getSortedProperties();

        foreach ($properties as $property) {
            if ($property->isStatic()) {
                continue;
            }

            $propertyName = $property->getName();
            $field        = StringUtil::toSnakeCase($propertyName);

            // Если поле не требуется, то пропускаем
            if ($fields && $fields !== ['all'] && in_array($field, $fields)) {
                continue;
            }

            $field  = StringUtil::toSnakeCase($propertyName);
            $getter = 'get' . ucfirst($propertyName);

            $value = $this->{$getter}();
            $value instanceof AbstractEntity && $value = $value->toArray();

            $item[$field] = $value;
        }

        return $item;
    }

    /**
     * Возвращает список защищенных полей сущности в указанном формате.
     * По умолчанию поля возвращаются в camelCase.
     *
     * @param string $format Формат имени поля (snake_case или camelCase)
     *
     * @return array
     */
    public static function getFieldNames(string $format = StringUtil::FORMAT_CAMEL_CASE): array
    {
        $reflection = ReflectionFactory::getClass(static::class);
        $properties = $reflection->getProperties(ReflectionProperty::IS_PROTECTED);

        $fields = [];
        foreach ($properties as $property) {
            $propertyName  = $property->getName();
            $formattedName = StringUtil::formatCase($propertyName, $format);
            $fields[]      = $formattedName;
        }
        return $fields;
    }

    /**
     * Получает свойства класса отсортированные по иерархии наследования классов
     *
     * @return array
     */
    private function getSortedProperties(): array
    {
        $properties = ReflectionFactory::getClass(static::class)->getProperties(ReflectionProperty::IS_PROTECTED);

        $properties = array_reverse(array_reduce($properties, function ($carry, $item) {
            $carry[$item->class][] = $item;
            return $carry;
        }, []));

        return array_merge(...array_values($properties));
    }

    public function preloadRelations(): void
    {
        (new RelationPreloader())->preload($this);
    }

    /**
     * Фиксирует снимок «чистых» значений свойств сущности.
     *
     * @internal Используется ORM-слоем (UnitOfWork), не доменным кодом.
     *
     * @param array<string, mixed> $values Имя свойства => значение.
     */
    public function ormSnapshot(array $values): void
    {
        $this->ormOriginalData = $values;
    }

    /**
     * Возвращает снимок «чистых» значений свойств сущности.
     *
     * @internal Используется ORM-слоем (UnitOfWork), не доменным кодом.
     *
     * @return array<string, mixed> Имя свойства => значение.
     */
    public function ormOriginal(): array
    {
        return $this->ormOriginalData;
    }
}
