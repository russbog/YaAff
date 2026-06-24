# Матрица паритета с Keitaro

Соответствие возможностей трекера Keitaro этому форку (YaAff). Обозначения статуса:

- **✓** реализовано
- **~** частично / иной подход
- **✗** сознательно вне области задачи

Каждая строка указывает на файл(ы)/фазу с реализацией. Фазы — это документы по
архитектуре в этой папке (`phase-0` … `phase-12`).

## 1. Кампании и потоки

| Возможность | Статус | Где |
|---|---|---|
| Кампании как первоклассные объекты | ✓ | таблица `campaigns`, `admin/index.php`, `admin/campsettings.php` |
| Несколько потоков на кампанию | ✓ | flow-JSON в настройках кампании, движок `main/` |
| Типы потоков: regular / forced / default (fallback) | ✓ | `FlowSelector` (Фаза 2) |
| Веса / доли потоков | ✓ | взвешенное распределение `FlowSelector` (Фаза 2) |
| A/B-сплит и взвешенное распределение | ✓ | `abtest/`, equal / weighted / Thompson |
| Включение/выключение кампании и потока | ✓ | флаг `enabled` (Фаза 8 сделала `pause_campaign` рабочим) |
| Логика white/black | ✓ | настройки `white`/`black`, `tds/`, `htmlprocessing/` |

## 2. Первоклассные сущности

| Возможность | Статус | Где |
|---|---|---|
| Источники трафика | ✓ | `entities/Source.php`, `admin/sources.php` (Фаза 1) |
| Офферы | ✓ | `entities/Offer.php`, `admin/offers.php` (Фаза 1) |
| Лендинги | ✓ | `entities/Landing.php`, `admin/landings.php` (Фаза 1) |
| Партнёрские сети | ✓ | `entities/Network.php`, `admin/networks.php` (Фаза 1) |
| Группы / папки | ✓ | `entities/Group.php`, `group_id` у каждой сущности (Фаза 1) |
| Шаблоны источников/сетей | ✓ | `templates/sources/*`, `templates/networks/*` (Фаза 1) |
| Офферы/лендинги внутри шагов потока | ✓ | `FlowEntityResolver` (Фаза 1) |

## 3. Режимы маршрутизации / редиректа

| Возможность | Статус | Где |
|---|---|---|
| Редирект 301/302 | ✓ | `RedirectStrategy` (Фаза 2) |
| Meta-refresh / JS-редирект | ✓ | `RedirectStrategy` (Фаза 2) |
| Curl / reverse-proxy (direct load) | ✓ | `directload/`, `RedirectStrategy` |
| iframe | ✓ | `RedirectStrategy` (Фаза 2) |
| Form / double-meta / удалённая выборка | ✓ | 12 режимов `RedirectStrategy` (Фаза 2) |
| Без редиректа (показ контента) | ✓ | `RedirectStrategy` (Фаза 2) |
| Action / показ HTML | ✓ | `actions.php`, `htmlprocessing/` |

## 4. Фильтры / триггеры

| Возможность | Статус | Где |
|---|---|---|
| GEO страна | ✓ | MaxMind, `bases/`, `FilterFunctions` |
| Регион / город | ✓ | GeoLite2 City (Фаза 2) |
| Устройство / ОС / браузер | ✓ | DeviceDetector, фильтры |
| ISP / тип соединения | ✓ | фильтры (Фаза 2) |
| Язык | ✓ | фильтры |
| Поисковик / ключевое слово | ✓ | `bases/searchengines.json`, фильтры (Фаза 2) |
| Реферер / сайт | ✓ | фильтры (Фаза 2) |
| Расписание (timetable) | ✓ | фильтры (Фаза 2) |
| Диапазон дат | ✓ | фильтр `date_between` (Фаза 2) |
| Лимит кликов / кап | ✓ | фильтр `click_limit` (Фаза 2) |
| Уникальность | ✓ | фильтр `uniqueness` (Фаза 2) |
| Боты | ✓ | фильтр ботов + блэклисты Фазы 6 |
| Маски и regex по IP/UA/параметрам | ✓ | фильтры с маской/regex (Фаза 2) |
| Группы условий AND/OR | ✓ | конструктор фильтров (`admin/js/filters.js`) |

## 5. Конверсии и Conversion API (CAPI)

| Возможность | Статус | Где |
|---|---|---|
| Конверсии как сущность | ✓ | таблица `conversions`, `admin/conversions.php` (Фаза 3) |
| S2S-постбэки (входящие) | ✓ | `api/postback.php`, маппинг статусов |
| Аудит постбэков | ✓ | `postback_log` (Фаза 3) |
| Пиксель конверсий | ✓ | `api/pixel.php` (Фаза 3) |
| Отправка в Conversion API (FB CAPI / Google / TikTok) | ✓ | `Integration` + `ConversionApiSender`, `templates/integrations/*` (Фаза 3) |
| Маппинг статусов (lead/sale/rejected…) | ✓ | обработка постбэков и конверсий |
| Дедуп по настраиваемому ключу | ✓ | clickid / tid / clickid_tid (Фаза 3) |
| Импорт конверсий из CSV | ✓ | `admin/conversions.php` (Фаза 3) |

## 6. Токены / макросы

| Возможность | Статус | Где |
|---|---|---|
| Единая токен-система во всех слоях | ✓ | `TokenRegistry` (Фаза 4) |
| Sub-ID (subN), кастомные параметры (c.*) | ✓ | `TokenRegistry` |
| Токены в лендингах, URL, постбэках, CAPI | ✓ | `MacrosProcessor`, `ConversionApiSender` делегируют в реестр (Фаза 4) |
| Динамические токены (дата/random и т.п.) | ✓ | динамические резолверы `TokenRegistry` (Фаза 4) |

## 7. Домены

| Возможность | Статус | Где |
|---|---|---|
| Домены как сущность | ✓ | `entities/Domain.php`, `admin/domains.php` (Фаза 5) |
| Несколько доменов на кампанию | ✓ | пул доменов (Фаза 5) |
| Wildcard / алиасы доменов | ✓ | `DomainMatcher` (Фаза 5) |
| Авто-SSL | ✓ | существующий acme + install |
| Cloudflare API (DNS, проверка токена) | ✓ | CF-клиент, `admin/cloudflare.php` (Фаза 5) |

## 8. Бот-защита

| Возможность | Статус | Где |
|---|---|---|
| JS-детект ботов | ✓ | существующий JS-челлендж |
| IP/UA блэклисты | ✓ | `bots/` (Фаза 6) |
| Авто-обновляемые фиды | ✓ | `bases/blacklists/feeds.json`, cron `bases/update_blacklists.php` (Фаза 6) |
| Детект datacenter / proxy / VPN (офлайн) | ✓ | офлайн-проверки `bots/` до медленных сервисов (Фаза 6) |

## 9. Отчётность / статистика

| Возможность | Статус | Где |
|---|---|---|
| Real-time дашборд | ~ | дашборд на polling + KPI-карточки (`admin/dashboard.php`, Фаза 7); не websocket |
| Графики | ✓ | canvas-графики без сторонних библиотек (Фаза 7) |
| Кастомные группировки/колонки отчётов | ✓ | `reports/ReportAggregator`, кастомные таблицы статистики |
| Лог кликов / конверсий | ✓ | `clicks`, `click_event_log`, `admin/conversions.php` |
| Экспорт отчётов по расписанию (CSV/JSON) | ✓ | `bin/export_report.php`, `ReportExporter` (Фаза 7) |
| Топ стран / потоков | ✓ | `DashboardQuery` (Фаза 7) |

## 10. Автоматизация: правила + планировщик

| Возможность | Статус | Где |
|---|---|---|
| Правила как сущность (расписание/условия/действия) | ✓ | `entities/Rule.php`, `admin/rules.php` (Фаза 8) |
| Расписание (interval/cron/macro) | ✓ | `rules/Schedule` (Фаза 8) |
| Условия по метрикам (AND/OR, операторы) | ✓ | `RuleEvaluator` (Фаза 8) |
| Действия (pause/resume, set weight, export, refresh, notify, log) | ✓ | `RuleActionExecutor` (Фаза 8/9) |
| Cron-раннер + аудит | ✓ | `bin/run_rules.php`, `rule_log` (Фаза 8) |

## 11. Уведомления

| Возможность | Статус | Где |
|---|---|---|
| Каналы Telegram / webhook / email | ✓ | `entities/Channel.php`, `ChannelSender` (Фаза 9) |
| Шаблоны сообщений с токенами | ✓ | `MessageRenderer` (Фаза 9) |
| Диспетчеризация по событиям + аудит (без секретов) | ✓ | `Notifier`, `notification_log` (Фаза 9) |
| Запуск из правил (действие `notify`) | ✓ | связка в `bin/run_rules.php` (Фаза 9) |

## 12. Мультипользовательский режим и доступ

| Возможность | Статус | Где |
|---|---|---|
| Несколько пользователей | ✓ | `entities/User.php`, `admin/users.php` (Фаза 10) |
| Роли | ✓ | `entities/Role.php`, `admin/roles.php` (Фаза 10) |
| Гранулярные права (`type.view`/`type.manage`, маски) | ✓ | `auth/AccessControl` (Фаза 10) |
| Хеширование паролей bcrypt | ✓ | `Authenticator` (Фаза 10) |
| Совместимый режим одного пароля | ✓ | `auth/Auth` (Фаза 10) |

## 13. REST API

| Возможность | Статус | Где |
|---|---|---|
| CRUD по всем сущностям | ✓ | `api/RestApi.php`, `EntityService` (Фаза 11) |
| Авторизация по bearer-токену (мастер + по пользователю) | ✓ | `api/ApiAuth.php` (Фаза 11) |
| Проверка прав | ✓ | `AccessControl` в `RestApi` (Фаза 11) |
| Спецификация OpenAPI 3.0 + Swagger UI | ✓ | `api/OpenApiBuilder`, `admin/api.php` (Фаза 11) |

## 14. База данных / инфраструктура / данные

| Возможность | Статус | Где |
|---|---|---|
| Абстракция драйверов | ✓ | `db/drivers/DbDriver` (Фаза 0) |
| Бэкенд SQLite | ✓ | `SqliteDriver` (Фаза 0) |
| Бэкенд MySQL / MariaDB | ✓ | `MysqlDriver` + `SqlDialect` (Фаза 12) |
| Миграции | ✓ | `db/Migrator`, `db/migrations/*` (Фаза 0) |
| Бэкап / restore (переносимый) | ✓ | `data/BackupManager`, `admin/data.php` (Фаза 12) |
| Хранение / прунинг данных | ✓ | `data/RetentionManager`, `bin/run_retention.php` (Фаза 12) |
| Репликация / кластеризация | ✗ | вне области (решается на уровне БД/инфраструктуры) |
| Тюнинг запросов под MySQL | ✗ | вне области (приоритет — корректность, не оптимизация под бэкенд) |

## 15. Вне области / примечания

- **Встроенный WYSIWYG-редактор лендингов** — ✗ лендинги управляются как сущности/файлы, не редактируются в приложении.
- **Real-time статистика через websocket** — ~ дашборд опрашивает по polling; функционально эквивалентно для поддерживаемых объёмов.
- **Распределённые/кластерные развёртывания** — ✗ фокус на одном узле; путь масштабирования — бэкенд MySQL.
