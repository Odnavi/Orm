<?php

namespace Odnavi\Orm\Repository;

use Odnavi\Core\ConnectionRegistry;
use Odnavi\Core\Contract\Connection;
use Odnavi\Core\Util\StringUtil;
use Exception;
use Odnavi\Orm\Attribute\{Column, Table};
use Odnavi\Orm\Entity\{AbstractEntity, Collection};
use Odnavi\Orm\Exception\EntityNotFoundException;
use Odnavi\Orm\Service\Hydration\EntityHydrator;
use Odnavi\Orm\Service\IdentityMap;
use Odnavi\Orm\Service\Metadata\TableFactory;
use Odnavi\Orm\Service\QueryBuilder;
use Odnavi\Orm\Service\Support\Profiling;
use Odnavi\Orm\Service\UnitOfWork;

class EntityRepository
{
    protected Table      $table;
    protected Connection $db;
    protected string   $entityClass;
    protected string   $entityNameLog;

    private EntityHydrator $hydrator;

    private array  $columnNames = [];
    private string $alias;
    /** @var array Локальное кэширование сущностей */
    private array $entities = [];

    public function __construct(?string $entityClass = null)
    {
        $entityClass && $this->entityClass = $entityClass;
        if (isset($this->entityClass)) {
            $this->db = ConnectionRegistry::get();
            $table    = TableFactory::get($this->entityClass);
            $table && $this->table = $table;

            $this->hydrator = new EntityHydrator();

            $this->entityNameLog = substr($this->entityClass, strrpos($this->entityClass, '\\') + 1);
            $this->entityNameLog = StringUtil::toSnakeCase($this->entityNameLog);
        }
    }

    // Search

    /**
     * Находит запись по первичному ключу
     *
     * @param int|string $primaryValue
     * @param bool $cache
     *
     * @return \Odnavi\Orm\Entity\AbstractEntity
     */
    public function find(int|string $primaryValue, bool $cache = true): AbstractEntity
    {
        if ($cache && isset($this->entities[$this->entityClass][$primaryValue])) {
            return $this->entities[$this->entityClass][$primaryValue];
        }

        $entity = $this->findOneBy([$this->table->getPrimaryKey() => $primaryValue]);

        $this->entities[$this->entityClass][$primaryValue] = $entity;
        return $entity;
    }

    /**
     * Находит запись по указанным критериям
     *
     * @param array $criteria
     * @param ?array $orderBy
     *
     * @return \Odnavi\Orm\Entity\AbstractEntity
     * @throws EntityNotFoundException
     */
    public function findOneBy(array $criteria = [], ?array $orderBy = null): AbstractEntity
    {
        $query = $this->getQueryBuilder($criteria);
        $this->applySorts($query, $orderBy ?? [], $criteria);

        $item = $this->queryRow($query);
        if (!$item) {
            throw new EntityNotFoundException();
        }

        return $this->prepareItem($item);
    }

    /**
     * Находит записи по указанным критериям, возвращает коллекцию
     * (временно решение, необходимо поправить вызовы findAll)
     *
     * @param array $criteria
     * @param array|null $orderBy
     * @param int|null $limit
     * @param int|null $offset
     * @param bool $withTotal
     *
     * @return \Odnavi\Orm\Entity\Collection
     */
    public function findAll(array $criteria = [], ?array $orderBy = null, ?int $limit = null, ?int $offset = null, bool $withTotal = false): Collection
    {
        $query      = $this->getQueryBuilder($criteria);
        $totalQuery = $withTotal ? clone $query : null;

        $this->applySorts($query, $orderBy ?? [], $criteria);
        is_numeric($limit) && $limit > 0 && $query->setLimit($limit, $offset ?? 0);

        return $this->prepareCollection($this->query($query), $totalQuery);
    }

    // Change

    /**
     * Создает новый ряд в таблице
     *
     * @param \Odnavi\Orm\Entity\AbstractEntity $entity
     *
     * @return bool
     */
    public function create(AbstractEntity $entity): bool
    {
        $data = [];

        $modifiedColumns = array_keys(UnitOfWork::computeChangeSet($entity, $this->table->currentValues($entity)));

        foreach ($this->table->getColumns() as $column) {
            // Не отправляем неизмененные поля, кроме обязательных
            if (!in_array($column->getPropertyName(), $modifiedColumns) && !$column->isRequired()) {
                continue;
            }

            $value = $column->getValue($entity);
            $name  = $column->getName();

            isset($value) && $data[$name] = $value;
        }

        $result = $this->db->insert($this->table->getName(), $data);
        if ($result === false) {
            return false;
        }   

        // Получаем id созданной записи
        if ($lastId = $this->db->lastInsertId()) {
            $primarySetter = 'set'. ucfirst($this->table->getPrimaryKey());
            $entity->{$primarySetter}($lastId);
        }

        $this->table->flushValue($entity);

        $id = $this->getPrimaryValue($entity);
        $id !== null && IdentityMap::getInstance()->put($this->entityClass, $id, $entity);

        return true;
    }

    /**
     * Обновляет ряд в таблице
     *
     * @param \Odnavi\Orm\Entity\AbstractEntity $entity
     *
     * @return bool
     */
    public function update(AbstractEntity $entity): bool
    {
        $data  = [];
        $where = [];

        $modifiedColumns = array_keys(UnitOfWork::computeChangeSet($entity, $this->table->currentValues($entity)));
        if (!$modifiedColumns) {
            return true;
        }

        foreach ($this->table->getColumns() as $column) {
            if (!in_array($column->getPropertyName(), $modifiedColumns) && !$column->isPrimary()) {
                continue;
            }

            $value = $column->getValue($entity);
            $name  = $column->getName();

            $column->isPrimary()
                ? $where[$name] = $value
                : $data[$name] = $value;
        }

        if (!$data) {
            return true;
        }

        if (!$where) {
            return false;
        }

        $result = $this->db->update($this->table->getName(), $data, $where);
        if ($result === false) {
            return false;
        }

        $this->table->flushValue($entity);
        return true;
    }

    public function updateBy(array $criteria, array $data): int
    {
        $alias = $this->getAlias();
        $query = $this
            ->getQueryBuilder($criteria)
            ->removeSelect()
            ->removeFrom()
            ->addUpdate($this->table->getName(), $alias);

        foreach ($data as $column => $value) {
            $query->addSet($column, $value);
        }

        $args  = $query->getArguments();
        $query = $query->getQueryString();

        $affected = $this->db
            ->prepare($query, $args)
            ->execute();

        return $affected === false ? 0 : $affected;
    }

    /**
     * Удаляет ряд из таблицы
     *
     * @param \Odnavi\Orm\Entity\AbstractEntity $entity
     *
     * @return bool
     */
    public function delete(AbstractEntity $entity): bool
    {
        $where = [];
        foreach ($this->table->getColumns() as $column) {
            if (!$column->isPrimary()) {
                continue;
            }

            $value = $column->getValue($entity);
            $name  = $column->getName();
            $where[$name] = $value;
            break;
        }

        if (!$where) {
            return false;
        }

        $result = $this->db->delete($this->table->getName(), $where);
        if ($result === false) {
            return false;
        }

        $id = $this->getPrimaryValue($entity);
        $id !== null && IdentityMap::getInstance()->remove($this->entityClass, $id);

        UnitOfWork::detach($entity);

        return true;
    }

    /**
     * Записывает переданные данные, при необходимости создавая новый ряд в таблице.
     *
     * @param array $data
     *
     * @return \Odnavi\Orm\Entity\AbstractEntity
     */
    public function saveData(array $data): AbstractEntity
    {
        $primaryKey = $this->table->getPrimaryKey();
        try {
            $entity = $this->find($data[$primaryKey], false);
            $method = 'update';
        } catch (EntityNotFoundException $e) {
            /** @var \Odnavi\Orm\Entity\AbstractEntity $entity */
            $entity = new $this->entityClass();
            $method = 'create';
        }

        $this->{$method}($entity->fromArray($data));
        return $entity;
    }

    // Other

    /**
     * Получает конструктор sql запроса
     *
     * @param array $criteria
     *
     * @return \Odnavi\Orm\Service\QueryBuilder
     */
    public function getQueryBuilder(array $criteria = []): QueryBuilder
    {
        $alias = $this->getAlias();

        $query = (new QueryBuilder())
            ->addSelect("$alias.*")
            ->addFrom($this->table->getName(), $alias);

        $this->applyFilter($query, $criteria);

        return $query;
    }

    /**
     * Добавляет фильтрацию по наличию колонки
     *
     * @param \Odnavi\Orm\Service\QueryBuilder $query
     * @param array $criteria
     */
    public function applyFilter(QueryBuilder $query, array $criteria): void
    {
        foreach ($this->prepareCriteria($criteria) as $name => $value) {
           $this->setCriterion($query, $name, $value);
        }
    }

    /**
     * Добавляет сортировки
     *
     * @param \Odnavi\Orm\Service\QueryBuilder $query
     * @param array $orderBy
     * @param array $criteria
     */
    public function applySorts(QueryBuilder $query, array $orderBy, array $criteria = []): void
    {
        foreach ($orderBy as $field => $direction) {
            $this->applySort($query, $field, $direction, $criteria);
        }
    }

    /**
     * Добавляет сортировку по наличию колонки
     *
     * @param \Odnavi\Orm\Service\QueryBuilder $query
     * @param string $field
     * @param string $direction
     * @param array $criteria
     */
    public function applySort(QueryBuilder $query, string $field, string $direction = 'ASC', array $criteria = []): void
    {
        $columnsName = array_map(fn (Column $column) => $column->getName(), $this->table->getColumns());
        in_array($field, $columnsName) && $query->addOrderBy($field, $direction);
    }

    /**
     * Выполняет кастомный запрос
     *
     * @param \Odnavi\Orm\Service\QueryBuilder $query
     *
     * @return array
     */
    public function query(QueryBuilder $query): array
    {
        Profiling::get()->start("orm query $this->entityNameLog");

        $args  = $query->getArguments();
        $query = $query->getQueryString();

        $data = $this->db
            ->prepare($query, $args)
            ->fetchAll();

        Profiling::get()->stop();
        return $data ?: [];
    }

    public function queryRow(QueryBuilder $query): ?array
    {
        Profiling::get()->start("orm query_row $this->entityNameLog");
        $args  = $query->getArguments();
        $query = $query->getQueryString();

        $item = $this->db
            ->prepare($query, $args)
            ->fetch();

        Profiling::get()->stop();
        return $item ?: null;
    }

    public function queryTotal(QueryBuilder $query): int
    {
        Profiling::get()->start("orm query_total $this->entityNameLog");
        $alias      = $this->getAlias();
        $primaryKey = $this->table->getPrimaryKey();

        $query
            ->removeSelect()
            ->removeGroupBy()
            ->removeOrderBy()
            ->removeLimit()
            ->addSelect("COUNT($alias.$primaryKey)");

        $args  = $query->getArguments();
        $query = $query->getQueryString();

        $total = $this->db
            ->prepare($query, $args)
            ->fetchOne();

        Profiling::get()->stop();
        return $total;
    }

    /**
     * Получает последнюю ошибку
     *
     * @return string
     */
    final public function getLastError(): string
    {
        return $this->db->lastError();
    }

    /**
     * Добавляет в конструктор условия и аргументы для подготовки запроса
     *
     * @param \Odnavi\Orm\Service\QueryBuilder $query
     * @param string $name
     * @param string|string[]|int|int[] $value
     * @param bool $negative
     */
    final public function setCriterion(QueryBuilder $query, string $name, $value, bool $negative = false): void
    {
        $alias  = $this->getAlias();
        $column = "$alias.$name";

        // Условие для набора значений: IN / NOT IN
        if (is_array($value)) {
            $operator = $negative ? 'NOT IN' : 'IN';

            $whereIn = [];
            foreach (array_unique($value) as $criterionValue) {
                if (is_bool($criterionValue)) {
                    $criterionValue = (int)$criterionValue;
                }

                if (is_string($criterionValue) || is_numeric($criterionValue)) {
                    $whereIn[]      = '?';
                    $query->setArgument($criterionValue);
                }
            }

            $query->addWhere("$column $operator (" . implode(',', $whereIn) . ')');
            return;
        }

        $operator = $negative ? '!=' : '=';

        if (is_bool($value)) {
            $value = (int)$value;
        }

        if (is_string($value) || is_numeric($value)) {
            $query->addWhere("$column $operator ?");
            $query->setArgument($value);
        }
    }

    final public function getAlias(string $tableName = ''): string
    {
        if (!empty($this->alias) && !$tableName) {
            return $this->alias;
        }

        $words = explode('_', $tableName ?: $this->table->getName());
        $alias = 't_';
        foreach ($words as $word) {
            $alias .= substr($word, 0, 1);
        }

        !$tableName && $this->alias = $alias;
        return $alias;
    }

    final public function getEntityClass(): string
    {
        return $this->entityClass;
    }

    /** Возвращает значение первичного ключа сущности или null, если оно не задано. */
    public function getPrimaryValue(AbstractEntity $entity): int|string|null
    {
        foreach ($this->table->getColumns() as $column) {
            if ($column->isPrimary()) {
                $value = $column->getValue($entity);
                return is_int($value) || is_string($value) ? $value : null;
            }
        }

        return null;
    }

    protected function prepareCriteria(array $oldCriteria, array $customNames = [], array $map = []): array
    {
        !$this->columnNames && $this->columnNames = array_map(fn (Column $column) => $column->getName(), $this->table->getColumns());
        $allowedKeys = array_merge($this->columnNames, $customNames);

        $criteria = [];
        foreach ($oldCriteria as $key => $value) {
            $key = $map[$key] ?? $key;
            in_array($key, $allowedKeys) && $criteria[$key] = $value;
        }

        // Сортируем
        uksort($criteria, fn($a, $b) => array_search($a, $allowedKeys) <=> array_search($b, $allowedKeys));

        return $criteria;
    }

    protected function prepareCollection(array $data, ?QueryBuilder $totalQuery = null): Collection
    {
        $collection = new Collection($this->entityClass);

        Profiling::get()->start("orm prepare_collection $this->entityNameLog");
        foreach ($data as $item) {
            try {
                $collection[] = $this->hydrator->hydrate($this->table, $item);
            } catch (Exception) {
                // Пропускаем строки, которые не удалось гидрировать в сущность.
            }
        }

        Profiling::get()->stop();
        $totalQuery && $collection->setTotal($this->queryTotal($totalQuery));
        return $collection;
    }

    protected function prepareItem(array $item): AbstractEntity
    {
        Profiling::get()->start("orm prepare_item $this->entityNameLog");
        $entity = $this->hydrator->hydrate($this->table, $item);
        Profiling::get()->stop();

        return $entity;
    }
}