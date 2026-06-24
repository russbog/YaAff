# Фаза 6: Бот-защита — авто-обновляемые IP/UA блэклисты

Фаза 6 добавляет офлайн авто-обновляемые блэклисты IP/User-Agent и офлайн-детект
datacenter/proxy/VPN поверх существующего JS бот-детекта и сигнала бота из
DeviceDetector. Сопоставление выполняется полностью из локальных кэш-файлов,
поэтому **сетевые вызовы на каждый клик не нужны**.

## Компоненты

### `bots/FeedParser.php` (чистый)
Нормализует тело любого построчного фида в чистый список:
- `parseIp(string $raw): array` — извлекает IPv4/IPv6-адреса и CIDR, убирает
  комментарии `#`/`;`, пустые строки и мусор, дедуплицирует.
- `parseUa(string $raw): array` — токены UA в нижнем регистре, без дублей.

### `bots/BlacklistStore.php`
Офлайн-кэш и матчер. Каждый фид кэшируется как `<feed>.ip` или `<feed>.ua` в
`bases/blacklists/`. Файлы каждого типа лениво агрегируются.
- `matchesIp(string $ip): bool` — совпадение по CIDR/точному IP через
  `IpUtils::checkIp`.
- `matchesUa(string $ua): bool` — подстрочное совпадение без учёта регистра.
- `writeFeed(name, type, lines)` — атомарная запись (temp + rename).

### `bots/BlacklistUpdater.php`
Скачивает настроенные фиды и обновляет кэш. HTTP-фетчер инъектируемый
(юнит-тестируемо). **Сбой не фатален**: при недоступности фида предыдущий кэш
остаётся нетронутым, поэтому блэклисты никогда не обнуляются. Дефолтный фетчер
использует curl с `CONNECT_TIMEOUT=5`, `TOTAL_TIMEOUT=60` (контекст cron, вне
пути запроса).

## Конфигурация — `bases/blacklists/feeds.json`

Управляется данными. Каждый фид: `name`, `type` (`ip`|`ua`), `tag` (свободная
метка, напр. `datacenter`/`abuse`/`bot`), `url`, `enabled`.

```json
{
  "feeds": [
    { "name": "firehol_level1", "type": "ip", "tag": "datacenter",
      "url": "https://.../firehol_level1.netset", "enabled": true }
  ]
}
```

Добавление/удаление фида — только изменение конфигурации, без правок кода.

Небольшой встроенный список UA-токенов поставляется в
`bases/blacklists/builtin.ua`, чтобы распространённые автоматические user-agent
помечались «из коробки».

## Обновление

- CLI / cron: `php bases/update_blacklists.php [путь/к/feeds.json]`
  ```cron
  0 * * * * php /path/to/bases/update_blacklists.php >> /var/log/blacklists.log 2>&1
  ```
- Админка: страница **Bot Protection** (`admin/blacklists.php`) показывает статус
  фидов и кнопку **Update now**. JSON-действия: `?action=status`,
  `?action=update`.

## Интеграция с фильтрацией

- `core.php` `get_click_params()` ставит `bot=1`, когда DeviceDetector сообщает о
  боте **или** UA совпадает с токеном блэклиста — усиливая существующий фильтр
  `bot`.
- `core.php` `is_proxy_or_vpn()` сначала проверяет офлайн IP-блэклист (диапазоны
  datacenter/proxy/VPN) перед более медленными внешними сервисами, поэтому
  существующий фильтр `VPN&Tor` теперь работает быстро и офлайн.

Обе интеграции аддитивны: при пустом кэше поведение не меняется (обратная
совместимость).

## Тесты

- `tests/FeedParserTest.php` — парсинг IP/UA, комментарии, дедуп, IPv6.
- `tests/BlacklistStoreTest.php` — совпадения CIDR/точное/UA, агрегация
  нескольких фидов, перезагрузка кэша, санитизация пути.
- `tests/BlacklistUpdaterTest.php` — запись, пропуск выключенных, graceful
  fallback при сбое, неверная конфигурация, обработка пустого парсинга, загрузка
  конфигурации фидов.
