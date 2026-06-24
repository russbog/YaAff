# Фаза 3: Конверсии + Conversion API

## Обзор

Фаза 3 добавляет отдельную подсистему **конверсий**, которая работает рядом (не заменяя) с существующим механизмом статусов кликов. Один клик теперь может порождать несколько конверсий, каждая привязана к внешнему ID транзакции (`tid`). Дедупликация выполняется по настраиваемому ключу для каждой кампании. Все входящие и исходящие постбэки записываются в структурированный журнал аудита.

Обобщённый слой **Conversion API** отправляет данные о конверсиях во внешние платформы (Facebook CAPI, Google, TikTok или любой кастомный HTTP-эндпоинт) через шаблоны-данные — без хардкода интеграций.

## Архитектура

```
postback.php / pixel.php
        │
        ▼
conversion_handler.php
  ├─ проверка дедупа (таблица conversions)
  ├─ add_conversion()
  ├─ update_status() ← обратная совместимость
  ├─ log_postback("in", ...)
  ├─ process_s2s_postbacks() → log_postback("out", ...)
  └─ fire_conversion_integrations()
         └─ ConversionApiSender::send()
              └─ log_postback("out", ...)
```

## 3.1 Таблица конверсий + дедуп + журнал постбэков

### Таблицы

- **conversions** — `id, campaign_id, clickid, tid, status, payout, revenue, currency, time, source, dedup_key, raw`
- **postback_log** — `id, time, direction(in|out), clickid, status, payout, currency, target, http_code, message`

### Стратегия дедупликации

Настраивается для каждой кампании в настройках постбэков (`dedup_key`):
- `clickid_tid` (по умолчанию) — дедуп по `clickid|tid`
- `clickid` — одна конверсия на клик
- `tid` — одна конверсия на внешнюю транзакцию

### Методы БД

- `Db::add_conversion()` — вставка с dedup_key
- `Db::conversion_exists()` — быстрый поиск по campaign + dedup_key
- `Db::log_postback()` — структурированная запись аудита
- `Db::get_conversions()` — список для админки
- `Db::get_postback_log()` — список с фильтром по направлению

## 3.2 JS-пиксель

**`api/pixel.php`** — трекинг конверсий с лендинга/thank-you страницы.

- Всегда возвращает прозрачный GIF 1x1 (или JSON при `format=json`)
- Параметры: `clickid` (обязательный), `status` (по умолчанию Lead), `payout`, `currency`, `tid`
- Использует тот же конвейер `register_conversion()`, что и postback.php
- URL пикселя показан в настройках кампании

Пример:
```html
<img src="/api/pixel.php?clickid=CLK123&status=lead&payout=5" width="1" height="1" />
```

## 3.3 Conversion API (обобщённые HTTP-шаблоны)

### Сущность Integration

Первоклассная сущность (`entities/Integration.php`) по образцу Фазы 1. Ключи настроек:

| Ключ | Тип | Описание |
|------|-----|----------|
| type | string | ID пресета (generic/fb_capi/google/tiktok) |
| method | string | HTTP-метод (GET/POST) |
| url | string | URL эндпоинта с плейсхолдерами {token} |
| headers | object | Заголовок → шаблон значения |
| body | string | Шаблон тела запроса |
| content_type | string | Заголовок Content-Type (по умолчанию application/json) |
| statuses | array | Статусы, запускающие интеграцию (пусто = все) |
| enabled | bool | Главный переключатель вкл/выкл |

### ConversionApiSender

Чистый рендеринг шаблонов + отправка через curl:

- `buildTokens(...)` — плоская карта токенов из данных клика
- `renderTemplate($template, $tokens)` — замена плейсхолдеров `{token}`
- `buildRequest($integration, $tokens)` — формирование method/url/headers/body
- `send($integration, $tokens)` — выполнение с CONNECT_TIMEOUT=2с, TOTAL_TIMEOUT=3с

### Доступные токены

| Токен | Источник |
|-------|----------|
| {clickid}, {ip}, {country}, {ua} | Колонки клика |
| {c.NAME} | Параметры клика (кастомные) |
| {status}, {payout}, {revenue}, {currency}, {time} | Поля конверсии |
| {tid}, {sub1}...{subN} | Параметры запроса |

### Пресеты-шаблоны

JSON-файлы в `templates/integrations/`:
- **generic.json** — отправная точка для любой платформы
- **fb_capi.json** — Facebook Conversions API
- **google.json** — Google Measurement Protocol (GA4)
- **tiktok.json** — TikTok Events API

## 3.4 Массовый импорт CSV

Админка (`admin/conversions.php` → вкладка «Bulk Import») принимает CSV-файлы:

**Обязательные колонки:** `clickid`, `status`
**Опциональные колонки:** `payout`, `currency`, `revenue`, `tid`/`transaction_id`

Каждая строка валидируется (clickid существует, status маппится на внутренний статус), дедуплицируется и обрабатывается через стандартный конвейер конверсий.

## Админка

- **Страница конверсий** (`admin/conversions.php`) — три вкладки: список конверсий, журнал постбэков, массовый импорт
- **Страница интеграций** (`admin/integrations.php`) — CRUD для интеграций Conversion API с пресетами
- **Настройки кампании** — новая секция «Conversion Settings»: выбор ключа дедупа, чекбоксы интеграций, URL пикселя

## Обратная совместимость

- `api/postback.php` принимает те же параметры (clickid, status, payout, currency)
- Статус клика по-прежнему обновляется через `update_status()` для существующей статистики
- Исходящие S2S-постбэки по-прежнему срабатывают на конверсию
- Никаких изменений в слое статистики/отчётов
