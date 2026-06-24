[English version](README.en.md)

# YaAff

YaAff — профессиональная система управления affiliate-трафиком и конверсиями, основанная на форке YellowTDS. Проект сохраняет совместимость с runtime-подходами YellowTDS/YellowCloaker, но развивает их в сторону полноценного Keitaro-like TDS: кампании, flows, postbacks, отчёты, сущности, API, роли, интеграции и production-инструменты в одной админ-панели.

> YaAff является форком YellowTDS. Упоминания `YellowCloaker` в PHP Connect user-agent/client API оставлены для обратной совместимости существующих интеграций.

## Что умеет YaAff

### Traffic distribution

- Кампании с доменами, trafficback и отдельными настройками статистики.
- White/black ветки, multi-step funnels и flows.
- Распределение трафика: equal, weighted и Thompson Sampling.
- Redirect-стратегии: HTTP 301/302/307, 404, curl/proxy, remote, iframe/meta/script сценарии.
- Правила и фильтры по IP/гео/ASN/языку/user-agent/устройствам/реферерам и пользовательским параметрам.
- JS Connect, PHP Connect, runtime wrappers: `index.php`, `js/index.php`, `phpconnect.php`, `postback.php`, `updateparams.php`, `send.php`, `next.php`.

### Affiliate operations

- Сущности кампаний: sources, networks, offers, landings, domains, integrations.
- S2S postbacks и conversion processing.
- Lead-form отправка через `send.php` с безопасной обработкой upstream errors без утечки POST/debug данных.
- Conversion API integrations для отправки событий во внешние сети.
- Макросы и параметризация URL для офферов, лендингов и постбэков.

### Analytics and control

- Dashboard, campaign statistics, click logs, conversions и custom table columns.
- Экспорт отчётов и агрегаторы статистики.
- Timezone-aware date picker и per-campaign timezone настройки.
- REST API и OpenAPI endpoint для автоматизации.
- RBAC: пользователи, роли и permissions.
- Bot protection через blacklist feeds, offline matching и scheduled refresh.
- Rules scheduler, notifications, retention и backup utilities.

### Modern admin UI

- Новый YaAff branding вместо старых YellowTDS/YellowCloaker логотипов.
- Современная тёмная панель управления с glassmorphism-карточками, обновлённой навигацией, кнопками, формами и таблицами.
- Новый login screen и SVG favicon.

## Быстрый старт

### Автоустановка на VPS

Для чистого Debian/Ubuntu VPS можно использовать автоустановщик из этого репозитория:

```bash
curl -fsSL https://raw.githubusercontent.com/russbog/YaAff/multipleconfigs/install.sh | sudo bash
```

Скрипт спросит домен, проверит DNS-привязку к VPS, поставит nginx/PHP/HTTPS, C-расширение MaxMind и предложит скачать GeoLite2 базы. Если ключ MaxMind не указан, установщик автоматически попробует скачать бесплатные DB-IP Lite Country/ASN базы. DB-IP Lite распространяется по CC BY 4.0, поэтому YaAff показывает attribution `IP Geolocation by DB-IP` в админке. Если базы отсутствуют, битые или IP не найден, GeoIP поля сохраняются как `Unknown`, а маршрутизация трафика продолжает работать.

Чтобы добавить к уже установленному инстансу несколько новых доменов:

```bash
curl -fsSL https://raw.githubusercontent.com/russbog/YaAff/multipleconfigs/install.sh | sudo bash -s -- --add-domain
```

Домены можно вводить через запятую: `tds1.example.com,tds2.example.com`.

### Ручная установка

1. Разверните содержимое репозитория на хостинге с PHP 8.2+.
2. Выполните `composer install` для dev/test зависимостей при необходимости.
3. Откройте `settings.php` и задайте как минимум:
   - `adminPassword`
   - `dbConnection`
   - `debug` (`false` для production)
   - `adminDomain` при необходимости
   - `adminIp` при необходимости
4. Убедитесь, что PHP может писать в:
   - `db/`
   - `logs/`
   - `caching/`
5. Откройте `/admin/` и создайте кампанию.

## Основные точки входа

- `index.php` — основной runtime entry point.
- `js/index.php` — JS Connect.
- `phpconnect.php` — PHP Connect API compatibility endpoint.
- `postback.php` — входящие S2S постбэки.
- `updateparams.php` — обновление click parameters.
- `send.php` — отправка lead forms во внешние affiliate endpoints.
- `next.php` — переходы по шагам воронки.
- `api/rest.php` и `api/openapi.php` — REST API и OpenAPI schema.
- `admin/` — админ-панель YaAff.

## Разработка и проверки

```bash
composer install
php ./vendor/bin/phpunit --colors=never
find . -name '*.php' -not -path './vendor/*' -not -path './thankyou/vendor/*' -print0 | xargs -0 -n1 php -l
php -S 127.0.0.1:8090 -t "$PWD"
```

Для проверки реального failure-path в `send.php` временно установите `"debug" => false` в `settings.php`, затем верните исходное значение.

## Документация

Историческая документация YellowTDS/YellowCloaker сохранена в `docs/` и постепенно актуализируется под YaAff:

- [Русская документация](docs/ru/index.md)
- [English documentation](docs/en/index.md)
