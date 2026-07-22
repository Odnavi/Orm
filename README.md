# odnavi/orm

Атрибутная ORM для PHP 8.2+: сущности размечаются атрибутами (`#[Table]`,
`#[Column]`, `#[Entity]`, `#[JoinColumn]`), репозитории дают типовой набор
CRUD-операций и построитель запросов, а `EntityManager` поверх них — identity
map и unit of work для батчевого сохранения в одной транзакции. Пакет не
привязан к конкретному драйверу БД: соединение приходит через контракт
`Odnavi\Core\Contract\Db`, за которым может быть PDO, Doctrine DBAL или wpdb.

## Содержание

- [Установка](#установка)
- [Быстрый старт](#быстрый-старт)
- [Разметка сущности атрибутами](#разметка-сущности-атрибутами)
  - [`#[Table]`](#table)
  - [`#[Column]`](#column)
  - [`#[Entity]` — связи](#entity--связи)
  - [`#[JoinColumn]` — денормализованные колонки](#joincolumn--денормализованные-колонки)
- [Подключение к БД](#подключение-к-бд)
- [Репозитории](#репозитории)
  - [EntityRepository](#entityrepository)
  - [Свой репозиторий](#свой-репозиторий)
  - [RepositoryFactory](#repositoryfactory)
- [QueryBuilder](#querybuilder)
- [Коллекции](#коллекции)
- [EntityManager: persist/remove/flush](#entitymanager-persistremoveflush)
- [Identity map и отслеживание изменений](#identity-map-и-отслеживание-изменений)
- [Схема / DDL](#схема--ddl)
- [Обработка ошибок](#обработка-ошибок)
- [Интеграция с роутингом](#интеграция-с-роутингом)

## Установка

```bash
composer require odnavi/orm
```

Пакет зависит от `odnavi/core` (контракт `Db`, реестры, рефлексия, утилиты) —
он подтягивается автоматически. Для конкретного драйвера БД поставь либо
`ext-pdo` (сырой PDO), либо `doctrine/dbal`.

## Быстрый старт

```php
use Odnavi\Orm\Attribute\{Column, Entity, Table};
use Odnavi\Orm\Entity\AbstractEntity;

#[Table(name: 'products')]
class ProductEntity extends AbstractEntity
{
    #[Column(Column::PRIMARY | Column::AUTO_GENERATE)]
    protected int    $id;
    #[Column(Column::REQUIRED, length: 120)]
    protected string $name;
    #[Column(Column::REQUIRED)]
    protected float  $price;
    #[Column(Column::UNIQUE, length: 40)]
    protected string $sku;
}
```

```php
use Odnavi\Orm\Service\RepositoryFactory;

$repo = RepositoryFactory::get(ProductEntity::class);

$product = new ProductEntity();
$product->setName('Клавиатура')->setPrice(49.9)->setSku('KB-001');
$repo->create($product);

$found = $repo->find($product->getId());
```

Геттеры/сеттеры (`getName()`, `setPrice()`, ...) — магические: `AbstractEntity`
резолвит их через `__call` по имени защищённого свойства, приводя типы
(`DateTime`, backed enum, скаляры) под капотом. Объявлять их вручную не нужно,
разве что для описания в `@method`-докблоке или переопределения поведения (как
`getPassword()`/`getUri()` в примерах ниже).

## Разметка сущности атрибутами

### `#[Table]`

Ставится на класс сущности, наследующий `Odnavi\Orm\Entity\AbstractEntity`.

```php
#[Table(name: 'accounts', indexes: [['user_id']])]
class AccountEntity extends AbstractEntity { /* ... */ }
```

| Параметр  | Тип     | Назначение                                             |
|-----------|---------|---------------------------------------------------------|
| `name`    | `string`| имя таблицы в БД                                        |
| `indexes` | `array` | описания индексов (произвольная форма, для схемы/DDL)   |

Метаданные (колонки, join-колонки, первичный ключ) собираются лениво из
атрибутов свойств при первом обращении и кэшируются на процесс через
`Odnavi\Orm\Service\Metadata\TableFactory` (плюс разделяемый кэш приложения,
если он внедрён — ключ инвалидируется по mtime файла сущности).

### `#[Column]`

Ставится на защищённое свойство сущности.

```php
#[Column(Column::PRIMARY | Column::AUTO_GENERATE)]
protected int $id;

#[Column(Column::REQUIRED, length: 4, default: 'USD')]
protected string $currency;

#[Column(type: 'datetime')]
protected DateTime $createdAt;
```

| Параметр  | Тип                    | Назначение                                                                 |
|-----------|------------------------|------------------------------------------------------------------------------|
| `flags`   | `int`                  | битовая маска: `Column::PRIMARY`, `::AUTO_GENERATE`, `::REQUIRED`, `::UNIQUE`, `::INDEXED` |
| `type`    | `string`                | тип колонки для DDL; если не задан — выводится из PHP-типа свойства (`int`/`string`/`bool`/`float`, `DateTime`/`DateTimeImmutable` → `datetime`, backed enum → его backing type) |
| `name`    | `string`               | имя колонки в БД; по умолчанию — `snake_case` имени свойства                 |
| `length`  | `?int`                 | длина (используется для `varchar`/`tinyint` при генерации DDL)               |
| `default` | `int\|null\|string`    | значение по умолчанию для DDL                                                |
| `comment` | `string`               | комментарий колонки для DDL                                                  |

Тип свойства выводится автоматически, поэтому `type` в атрибуте обычно нужен
только для `datetime`/`date` (свойство типа `DateTime` без уточнения станет
`datetime`) или когда SQL-тип должен отличаться от типа PHP-свойства.

### `#[Entity]` — связи

Связь «многие к одному»/«один к одному» по внешнему ключу. Ставится на
свойство, типизированное классом связанной сущности.

```php
#[Column]
protected ?int $bankId;

#[Entity(foreignKey: 'bankId', class: BankEntity::class)]
protected ?BankEntity $bank;
```

| Параметр      | Тип      | Назначение                                                  |
|---------------|----------|--------------------------------------------------------------|
| `foreignKey`  | `string` | имя свойства текущей сущности, хранящего значение внешнего ключа |
| `class`       | `string` | FQCN связанной сущности                                      |

Связь не подгружается автоматически при каждом обращении. Для одной сущности
`AbstractEntity::preloadRelations()` (вызывается вручную) догружает все
`#[Entity]`-свойства через `Odnavi\Orm\Service\Hydration\RelationPreloader`.
Для коллекции `Collection::toArray()` неявно делает то же самое пачкой на весь
набор. В обоих случаях N+1 решается одинаково: id связанных сущностей всех
элементов собираются и запрашиваются одним `findAll(['id' => [...]])` на
каждый тип связи, а не отдельным запросом на каждую сущность.

### `#[JoinColumn]` — денормализованные колонки

Колонка, значение которой приходит из соединённой (`JOIN`) таблицы прямо в
результате запроса, а не отдельным запросом связи.

```php
#[JoinColumn(
    name: 'bank_name',
    targetTable: 'banks',
    targetColumn: 'name',
    refTargetColumn: 'id',
    refColumn: 'bank_id',
)]
protected ?string $bankName = null;
```

| Параметр          | Тип       | Назначение                                                        |
|-------------------|-----------|----------------------------------------------------------------------|
| `name`            | `string`  | имя колонки/алиаса в результирующем наборе строки                  |
| `targetTable`     | `string`  | соединяемая таблица                                                  |
| `targetColumn`    | `string`  | колонка в соединяемой таблице                                       |
| `refTargetColumn` | `string`  | колонка соединяемой таблицы, по которой идёт связь                  |
| `refColumn`       | `?string` | колонка текущей таблицы, по которой идёт связь (`ON`)                |

`EntityHydrator` заполняет такое свойство значением `$data[$name]`, если оно
присутствует в строке результата — то есть запрос должен сам добавить нужный
`JOIN` и `SELECT` с этим алиасом (`QueryBuilder::addJoin()`/`addSelect()`).
В отличие от `#[Entity]`, это не отдельный запрос и не объект связанной
сущности, а плоское значение.

## Подключение к БД

Пакет не создаёт соединение сам — приложение регистрирует уже готовый драйвер
через `Odnavi\Core\DbRegistry` при старте. `Odnavi\Core\Service\DbFactory`
сам определяет тип драйвера и оборачивает его в соответствующий адаптер
(`PdoDb`, `DbalDb`, `WpdbDb`), удовлетворяющий контракту `Odnavi\Core\Contract\Db`,
от которого зависят репозитории и `EntityManager`:

```php
use Odnavi\Core\DbRegistry;

$pdo = new PDO('mysql:host=localhost;dbname=app', 'user', 'pass');
DbRegistry::set($pdo); // PDO, Doctrine\DBAL\Connection или wpdb — определяется автоматически
```

После этого `RepositoryFactory::get(...)` и `EntityManager::getInstance()`
берут соединение из `DbRegistry::get()` самостоятельно.

## Репозитории

### EntityRepository

Базовый репозиторий даёт типовые операции без написания SQL:

```php
$repo = RepositoryFactory::get(ProductEntity::class);

$repo->find(1);                                   // по первичному ключу, с локальным кэшем на процесс
$repo->findOneBy(['sku' => 'KB-001']);             // бросает EntityNotFoundException, если не найдено
$repo->findAll(['price' => [10, 20]]);             // Collection; критерий-массив -> IN (...)
$repo->findAll([], ['price' => 'DESC'], 20, 0, true); // orderBy, limit, offset, withTotal

$repo->create($product);   // INSERT только по изменённым/обязательным колонкам
$repo->update($product);   // UPDATE только по изменённым колонкам (diff по снимку)
$repo->delete($product);   // DELETE по первичному ключу

$repo->saveData(['id' => 1, 'name' => 'Новое имя']); // upsert по данным массива
$repo->updateBy(['sku' => 'KB-001'], ['price' => 39.9]); // UPDATE по критериям без загрузки сущности
```

`create()`/`update()` отправляют в БД только реально изменённые колонки — diff
вычисляется через `UnitOfWork::computeChangeSet()` относительно снимка
значений, зафиксированного при последней гидратации/сохранении сущности
(кроме обязательных колонок, которые уходят всегда).

### Свой репозиторий

По конвенции неймспейсов `Entity\` → `Repository\` можно завести выделенный
репозиторий с собственными методами — он наследует все базовые:

```php
namespace Money\Repository\Accounts;

use Money\Entity\Accounts\AccountEntity;
use Odnavi\Orm\Repository\EntityRepository;

class AccountRepository extends EntityRepository
{
    protected string $entityClass = AccountEntity::class;

    public function aggregateBalances(int $userId): array
    {
        $alias = $this->getAlias();
        $query = $this
            ->getQueryBuilder(['user_id' => $userId])
            ->removeSelect()
            ->addSelect("$alias.currency")
            ->addSelect("SUM($alias.balance)", 'balance')
            ->addGroupBy("$alias.currency");

        return $this->query($query);
    }
}
```

### RepositoryFactory

```php
use Odnavi\Orm\Service\RepositoryFactory;

$repo = RepositoryFactory::get(AccountEntity::class);
```

Резолвит выделенный репозиторий по конвенции неймспейсов (`Money\Entity\Accounts\AccountEntity`
→ `Money\Repository\Accounts\AccountRepository`); если такого класса нет —
отдаёт общий `EntityRepository`, сконструированный с `$entityClass` из
аргумента. Результат кэшируется на процесс.

## QueryBuilder

Собирает SQL с позиционными плейсхолдерами `?`; `EntityRepository::getQueryBuilder()`
уже создаёт билдер с `SELECT alias.* FROM table alias`, дальше его можно
дополнять:

```php
use Odnavi\Orm\Service\QueryBuilder;

$query = $repo
    ->getQueryBuilder(['status' => 'active'])
    ->addLeftJoin('categories', 'c', 'ON c.id = t_p.category_id')
    ->addSelect('c.name', 'category_name')
    ->addWhere('t_p.price > ?')
    ->setArgument(10)
    ->addOrderBy('t_p.price', QueryBuilder::DIRECTION_DESC)
    ->setLimit(20);

$rows = $repo->query($query);
```

Для значений, которые нельзя передать литералом (арифметика, ссылка на другую
колонку), — `QueryBuilder::raw()`:

```php
$query->addSet('balance', QueryBuilder::raw('balance + ?', [$amount]));
```

## Коллекции

`findAll()` возвращает `Odnavi\Orm\Entity\Collection` — реализует `Countable`,
`IteratorAggregate`, `ArrayAccess`:

```php
$products = $repo->findAll(['status' => 'active']);

foreach ($products as $product) { /* ... */ }

$names = $products->pluck(fn($p) => $p->getName());          // ['Клавиатура', ...]
$byId  = $products->pluck(null, fn($p) => $p->getId());       // [1 => Entity, ...]
$products->walk(fn($p) => $p->applyDiscount());
$products->toArray();                                          // подгружает #[Entity]-связи одним запросом на тип и сериализует
```

## EntityManager: persist/remove/flush

`EntityManager` — единица работы уровня приложения: копит запланированные
`persist()`/`remove()` и применяет их одной транзакцией во `flush()`. Он
аддитивен к немедленным `EntityRepository::create()/update()/delete()` —
существующий код, вызывающий репозиторий напрямую, продолжает работать как
есть.

```php
use Odnavi\Orm\Service\EntityManager;

$em = EntityManager::getInstance(); // синглтон на процесс/запрос

$order = new OrderEntity();
$order->setProductId($product->getId())->setQuantity(2);

$em->persist($order);
$em->persist($product->decreaseStock(2));
$em->remove($oldDraft);

$em->flush(); // одна транзакция: insert/update по persist(), затем delete по remove()
```

Во `flush()` для каждой сущности из очереди `persist` решается insert это или
update — по наличию значения первичного ключа (`EntityRepository::getPrimaryValue()`).
Вся очередь выполняется в `DbRegistry::get()->transactional()`: при ошибке
любой операции транзакция откатывается, очереди сохраняются (не теряются) и
исключение пробрасывается вызывающему коду. При успехе очереди очищаются.
`clear()` сбрасывает очереди без применения — нужно в долгоживущих процессах
(воркер, крон) между итерациями и в тестах.

## Identity map и отслеживание изменений

- **Identity map** (`Odnavi\Orm\Service\IdentityMap`) — один объект сущности на
  пару (класс, первичный ключ) в пределах запроса. `EntityHydrator` при
  гидратации строки сначала смотрит в карту: если сущность с этим id уже
  загружена, возвращается тот же объект, а из свежих данных обновляются
  только «чистые» (несохранённые в памяти изменения не затираются). `create()`
  добавляет сущность в карту после успешной вставки, `delete()` убирает.
- **Unit of work** (`Odnavi\Orm\Service\UnitOfWork`) — минимальный трекер
  изменений: снимок «чистых» значений хранится на самой сущности
  (`AbstractEntity::ormSnapshot()`/`ormOriginal()`), фиксируется после
  конструирования, гидратации и сохранения (`Table::flushValue()`).
  `UnitOfWork::computeChangeSet()` сравнивает текущие значения со снимком и
  отдаёт только изменённые свойства — на этом построена частичная запись в
  `create()`/`update()`.

Оба реестра — синглтоны на процесс (= на HTTP-запрос в классической
модели). В долгоживущих процессах (воркер, крон) и между тест-кейсами их
нужно сбрасывать вручную: `IdentityMap::getInstance()->clear()`.

## Схема / DDL

`Column::getColumnDefinition()` строит SQL-описание одной колонки через
`Odnavi\Orm\Service\Schema\ColumnDefinitionBuilder` и `MysqlTypeMapper`
(реализация `TypeMapper`, тип колонки → SQL-тип MySQL: `string` → `varchar(N)`/`text`,
`bool` → `tinyint(1)`, `int` → `int unsigned`, и т.д.):

```php
use Odnavi\Orm\Service\Metadata\TableFactory;

$table = TableFactory::get(ProductEntity::class);

$columns = array_map(fn($c) => '  ' . $c->getColumnDefinition(), $table->getColumns());

$ddl = sprintf(
    "CREATE TABLE %s (\n%s\n)",
    $table->getName(),
    implode(",\n", $columns)
);
```

Пример колонки `#[Column(Column::REQUIRED, length: 4, default: 'USD')]` со
свойством `string` даст `currency varchar(4) NOT NULL DEFAULT 'USD'`.

## Обработка ошибок

`Odnavi\Orm\Exception\EntityNotFoundException` (`extends RuntimeException`)
бросается `EntityRepository::find()`/`findOneBy()`, когда запись не найдена:

```php
use Odnavi\Orm\Exception\EntityNotFoundException;

try {
    $product = $repo->find($id);
} catch (EntityNotFoundException $e) {
    // 404 на уровне приложения
}
```

## Интеграция с роутингом

`Odnavi\Orm\Adapter\RepositoryAdapter` оборачивает `EntityRepository` в
контракт `Odnavi\Core\Contract\Repository`, которым пользуется слой роутинга
(`odnavi/routing`) для авто-CRUD операций — напрямую реализовать интерфейс
`EntityRepository` не может, так как его `create/update/delete` типизированы
конкретным `AbstractEntity`, а контракт требует `Core\Contract\Entity`
(сузить параметр при реализации интерфейса запрещает язык).
