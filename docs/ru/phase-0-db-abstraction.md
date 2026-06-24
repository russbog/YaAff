# Фаза 0: Абстракция БД, слой сущностей и миграции

Фаза 0 — это фундамент, на котором строятся все последующие возможности. Она
вводит три вещи, не меняя поведение во время выполнения:

1. **Абстракцию драйвера БД**, чтобы трекер больше не был жёстко привязан к
   SQLite (MySQL/MariaDB можно добавить позже одним изолированным классом).
2. **Универсальный репозиторий сущностей** — готовый CRUD для новых
   первоклассных сущностей (источники, офферы, сети, лендинги, …) без
   шаблонного кода под каждую сущность.
3. **Систему версионируемых миграций**, чтобы добавлять новые таблицы на
   существующие установки без потери данных пользователя.

SQLite остаётся драйвером по умолчанию. Обработка запросов, статистика и
постбэки не меняются.

## 1. Абстракция драйвера БД

Весь доступ к БД теперь идёт через интерфейс `DbDriver`
(`db/drivers/DbDriver.php`). Код приложения (`Db`, `EntityRepository`,
`Migrator`) никогда не обращается к расширению `SQLite3` напрямую. Реализация
по умолчанию — `SqliteDriver` (`db/drivers/SqliteDriver.php`): он сохраняет
исходную стратегию двух хэндлов (read-only + read-write) и настройки WAL/PRAGMA,
поэтому производительность не меняется.

Добавление другого бэкенда позже — изолированная задача: написать один класс,
реализующий `DbDriver`, и больше ничего не менять.

### Основные методы

| Метод | Назначение |
| --- | --- |
| `select($sql, $params)` | Выполнить SELECT, вернуть все строки. |
| `selectOne($sql, $params)` | Выполнить SELECT, вернуть первую строку (или `[]`). |
| `execute($sql, $params)` | Выполнить INSERT/UPDATE/DELETE/DDL. |
| `insert($sql, $params)` | Выполнить INSERT, вернуть новый id. |
| `affectedRows()` | Число изменённых строк последней записи. |
| `lastInsertId()` | Последний автоинкрементный id. |
| `exec($sql)` | Выполнить «сырой» SQL (например, файл схемы). |
| `beginTransaction()` / `commit()` / `rollback()` | Транзакции. |
| `tableColumns($table)` | Имена колонок таблицы (или `[]`). |

### Диалект-хелперы

SQL, который различается между БД, формируется методами-хелперами, а не
хардкодится, поэтому каждый бэкенд отдаёт свой диалект:

| Хелпер | Вывод для SQLite |
| --- | --- |
| `jsonExtract($col, $key)` | `json_extract(col, '$.key')` |
| `jsonExtractReal($col, $key)` | `CAST(json_extract(...) AS REAL)` |
| `dateGroup($col, $tz)` | `strftime('%Y-%m-%d', datetime(col, 'unixepoch', tz))` |
| `insertIgnoreInto()` | `INSERT OR IGNORE INTO` |
| `caseInsensitiveCollation()` | `COLLATE NOCASE` |
| `greatest($a, $b)` | `MAX(a, b)` |

### Привязка параметров

Параметры используют константы типов `DbDriver` (`INT`, `FLOAT`, `TEXT`,
`BLOB`, `NULL`). Поддерживаются три формы (определяются автоматически):

```php
// 1. Позиционные пары -> плейсхолдеры "?" по порядку
$driver->select('SELECT * FROM t WHERE a = ? AND b = ?', [
    [$a, DbDriver::INT],
    [$b, DbDriver::TEXT],
]);

// 2. Именованная карта -> плейсхолдеры ":name"
$driver->select('SELECT * FROM t WHERE a = :a', [
    ':a' => [$a, DbDriver::INT],
]);

// 3. Устаревшая форma «значение => тип» (для обратной совместимости)
$driver->select('SELECT * FROM t WHERE a = ?', [$a => DbDriver::INT]);
```

## 2. Слой сущностей

Новые сущности следуют единому соглашению о таблице, чтобы один репозиторий мог
управлять всеми:

```
id          INTEGER PRIMARY KEY AUTOINCREMENT
name        TEXT NOT NULL
group_id    INTEGER NULL          -- необязательная группа/папка
settings    TEXT NOT NULL '{}'    -- бессхемный JSON-набор настроек
created_at  INTEGER NOT NULL      -- unix epoch
updated_at  INTEGER NOT NULL      -- unix epoch
```

Вся специфичная для сущности конфигурация хранится в бессхемном JSON-наборе
`settings`. Это делает каждую сущность **универсальной**: добавление нового
настраиваемого поля никогда не требует изменения схемы и не привязано жёстко к
конкретному офферу/сети/источнику.

- `entities/Entity.php` — базовый класс с `id`, `name`, `group_id`,
  `settings` (декодированный массив), `created_at`, `updated_at`, а также
  хелперами `get()/set()` для набора настроек.
- `entities/EntityRepository.php` — универсальный CRUD: `find`, `findByName`,
  `findAll`, `count`, `save` (вставка или обновление), `delete`.

Репозиторий валидирует идентификаторы таблиц и колонок и привязывает все
значения, поэтому защищён от SQL-инъекций. `created_at` устанавливается один
раз при вставке и не перезаписывается при обновлении; `updated_at` обновляется
при каждом сохранении.

## 3. Система миграций

`db/Migrator.php` применяет версионируемые обратимые изменения схемы и
записывает их в таблицу `schema_migrations`, поэтому обновления приходят без
потери данных: применяются только новые версии, каждая ровно один раз, по
возрастанию.

Файлы миграций лежат в `db/migrations/` и называются
`NNN_краткое_описание.php`. Каждый файл **возвращает** экземпляр `Migration`:

```php
<?php
// db/migrations/001_create_networks.php
return new class implements Migration {
    public function up(DbDriver $db): void {
        $db->exec(
            "CREATE TABLE IF NOT EXISTS networks (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                group_id INTEGER,
                settings TEXT NOT NULL DEFAULT '{}',
                created_at INTEGER NOT NULL,
                updated_at INTEGER NOT NULL
            )"
        );
    }
    public function down(DbDriver $db): void {
        $db->exec("DROP TABLE IF EXISTS networks");
    }
};
```

Запуск миграций из CLI:

```bash
php db/migrate.php           # применить все ожидающие миграции
php db/migrate.php status    # показать применённые / ожидающие версии
php db/migrate.php rollback  # откатить последнюю миграцию
```

> Примечание: Фаза 0 поставляет только **каркас** миграций — самих миграций
> сущностей ещё нет. Первые добавит Фаза 1 (networks, offers, sources, …).

## Пример: добавить новую сущность (Network)

1. Создайте миграцию `db/migrations/001_create_networks.php` (см. выше) и
   выполните `php db/migrate.php`.
2. Используйте универсальный репозиторий — отдельный класс не обязателен:

```php
$repo = new EntityRepository($db->driver(), 'networks');

$network = new Entity();
$network->name = 'My CPA Network';
$network->settings = [
    'postback_url' => 'https://net.example/pb?cid={clickid}&payout={payout}',
    'status_map'   => ['1' => 'sale', '2' => 'lead', '3' => 'rejected'],
    'currency'     => 'USD',
];
$repo->save($network);              // INSERT, назначает id + временные метки

$found = $repo->findByName('My CPA Network');
$found->set('currency', 'EUR');
$repo->save($found);               // UPDATE, обновляет updated_at

$all = $repo->findAll([], 'name'); // список, отсортированный по имени
$repo->delete($found->id);
```

Для более богатого поведения наследуйте `Entity` (например,
`class Network extends Entity`) и передайте подкласс в репозиторий:
`new EntityRepository($driver, 'networks', Network::class)`.

## Обратная совместимость и тестирование

- Публичный API `Db` не изменился; существующие вызовы продолжают работать.
- Полные пути запроса, статистики и постбэков проверены end-to-end на реальной
  базе SQLite.
- Новое покрытие PHPUnit: `tests/SqliteDriverTest.php`,
  `tests/EntityRepositoryTest.php`, `tests/MigratorTest.php`.
```
