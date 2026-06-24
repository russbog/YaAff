# Фаза 12 — драйвер MySQL/MariaDB + бэкап / restore / retention

Второй бэкенд БД (MySQL/MariaDB), реализованный через абстракцию `DbDriver` из
Фазы 0, плюс эксплуатационные инструменты для данных: переносимый логический
бэкап/restore и настраиваемое хранение/прунинг данных.

## Выбор бэкенда

`settings.php`:

```php
// "sqlite" (по умолчанию) или "mysql"
"dbDriver" => "sqlite",

// используется только при dbDriver = "mysql"
"mysql" => [
    "host"     => "127.0.0.1",
    "port"     => 3306,
    "database" => "yaaff",
    "username" => "yaaff",
    "password" => "",
    "socket"   => "",   // опц. unix-сокет; при заданном перекрывает host/port
],

// хранение данных: удалять строки кликов/логов старше N дней (0 = выключено)
"retentionDays" => 0,
```

`db/db.php` создаёт нужный драйвер по `dbDriver`. Код приложения (`Db`,
`EntityRepository`, `Migrator`, отчёты, …) никогда не обращается к конкретному
драйверу — только к интерфейсу `DbDriver`, поэтому смена бэкенда — это один
переключатель в конфиге.

## Единый источник истины для схемы

Схема существует **один раз**, написана в диалекте SQLite (`db/db.sql` +
`db/migrations/*`). Вместо параллельной схемы под MySQL драйвер прогоняет каждый
запрос через `SqlDialect` — stateful-транслятор:

| SQLite                         | MySQL                                  |
|--------------------------------|----------------------------------------|
| `INTEGER`                      | `BIGINT`                               |
| `NUMERIC` / `DECIMAL`          | `DECIMAL(20,6)`                         |
| `REAL` / `FLOAT`               | `DOUBLE`                               |
| `TEXT` (обычный)               | `LONGTEXT` (хранит большой JSON)       |
| `TEXT` (в индексе/ограничении) | `VARCHAR(191)`                         |
| `AUTOINCREMENT`                | `AUTO_INCREMENT`                       |
| `INSERT OR IGNORE`             | `INSERT IGNORE`                        |
| `COLLATE NOCASE`               | (убирается; collation utf8mb4)         |
| `PRAGMA …` / `BEGIN`/`COMMIT`  | (убирается; транзакциями рулит драйвер)|
| —                              | `ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`|

MySQL не умеет индексировать `TEXT`/`BLOB` без префикса и не допускает на них
внешние ключи. `SqlDialect` решает это в два прохода: сначала сканирует весь
батч, чтобы узнать все колонки в индексах/ограничениях, затем такие `TEXT`-
колонки выдаёт как `VARCHAR(191)`, остальные — как `LONGTEXT`. Если таблица и её
индекс создаются **разными** запросами (миграции), колонка — изначально
`LONGTEXT` — расширяется обратно через `ALTER TABLE … MODIFY` перед созданием
индекса.

```
SqliteDriver ─┐
              ├─ реализуют DbDriver
MysqlDriver ──┘   └─ весь SQL идёт через SqlDialect -> PDO
```

`MysqlDriver` на PDO: ленивое подключение, `utf8mb4`, режим исключений,
привязка параметров из констант типов `DbDriver` в `PDO::PARAM_*`, поддержка как
позиционных (`?`), так и именованных (`:name`) плейсхолдеров.

## Бэкап и restore

`data/BackupManager.php` создаёт **переносимый JSON-архив** колонок и строк всех
таблиц. Так как он логический (не бинарный дамп), бэкап с SQLite
восстанавливается в MySQL и наоборот. Сама схема воспроизводится миграциями;
restore лишь заменяет данные строк в таблицах, существующих и в архиве, и в
целевой БД.

- `backup($exclude=[])` / `backupJson()` — снимок всех таблиц.
- `restore($archive)` / `restoreJson($json)` — замена совпадающих таблиц в одной
  транзакции с отключённой проверкой внешних ключей (порядок таблиц не мешает
  загрузке). Возвращает число вставленных строк по таблицам.

UI: **admin → Data**. Скачать бэкап, загрузить для restore (с подтверждением),
посмотреть число строк по таблицам и активный драйвер.

## Хранение / прунинг

`data/RetentionManager.php` удаляет строки старше `retentionDays` из объёмных
append-only таблиц, независимо от драйвера. Отсутствующие в конкретной установке
таблицы/колонки пропускаются — корректно при развитии схемы:

| таблица            | колонка времени |
|--------------------|-----------------|
| `clicks`           | `time`          |
| `blocked`          | `time`          |
| `trafficback`      | `time`          |
| `click_event_log`  | `time`          |
| `click_steps`      | `time`          |
| `postback_log`     | `time`          |
| `rule_log`         | `ran_at`        |
| `notification_log` | `created_at`    |

Запуск из cron (ежедневно):

```
17 4 * * * php /path/to/bin/run_retention.php >> /var/log/yatds-retention.log 2>&1
```

Разовый запуск: `php bin/run_retention.php --days=30`. Также доступно из
**admin → Data → Prune**.

## Тесты

- `tests/SqlDialectTest.php` — чистые тесты транслятора (типы, AUTOINCREMENT,
  размеры TEXT, ALTER для индексов, `INSERT OR IGNORE`, удаление PRAGMA, …).
- `tests/BackupManagerTest.php` — round-trip бэкап/restore на SQLite.
- `tests/RetentionManagerTest.php` — прунинг по таблицам, выключенный режим,
  отсутствующие таблицы.
- `tests/MysqlDriverTest.php` — интеграционные тесты на живом сервере
  MySQL/MariaDB; **автоматически пропускаются**, если сервера/`pdo_mysql` нет,
  поэтому набор остаётся «зелёным» на машинах только с SQLite.
