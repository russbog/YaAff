# Фаза 11 — REST API + OpenAPI

Универсальный REST API с токен-авторизацией: полный CRUD по всем первоклассным
сущностям (offers, landings, sources, networks, domains, integrations, rules,
channels, users, roles). Использует тот же слой хранения, что и админка, поэтому
поведение идентично.

## Архитектура

Логика CRUD вынесена в одно место и переиспользуется админ-эндпоинтом и REST API:

```
admin/entityapi.php ─┐
                     ├─> EntityService ─> EntityRepository ─> DbDriver
api/rest.php ────────┘        (приведение типов по схеме, хеширование
                              паролей, скрытие секретов, резолв групп)
```

- **`entities/EntityService.php`** — CRUD на основе схемы. `list/get/save/delete`.
  Приводит присланные значения по типу поля из `admin/entityschemas.php`, хеширует
  поля `password`, скрывает секреты при чтении, резолвит имена групп. При неверном
  вводе бросает `EntityServiceException` (несёт HTTP-статус).
- **`api/RestApi.php`** — чистый диспетчер. Сопоставляет метод+путь с вызовом
  `EntityService` и проверяет права через `AccessControl`. Без транспортной
  логики → полностью покрывается юнит-тестами.
- **`api/ApiAuth.php`** — резолвит bearer-токен в контекст прав.
- **`api/rest.php`** — тонкая HTTP-обвязка (разбор пути/тела, вывод JSON).
- **`api/OpenApiBuilder.php` / `api/openapi.php`** — генерирует документ OpenAPI
  3.0 из схем сущностей (всегда синхронно, без ручного дубля).
- **`admin/api.php`** — Swagger UI внутри админки по сгенерированной спецификации.

## Авторизация

Каждый запрос требует `Authorization: Bearer <token>` (или fallback `?token=`).
Два источника токена:

1. **Мастер-токен** — `apiToken` в `settings.php`. Если задан, даёт полный
   супер-админ доступ (права `["*"]`), повторяя модель легаси-логина по одному
   паролю для машинного доступа. Пусто — отключено.
2. **Токен пользователя** — поле `api_token` сущности User (Фаза 10). Запрос
   наследует эффективные права пользователя (роль ∪ доп. права).

Неавторизованные запросы получают `401`.

## Маршруты

Базовый путь `/api/rest.php`. Путь через `PATH_INFO`; также поддерживается
fallback `?type=&id=`.

| Метод  | Путь              | Действие        | Право            |
|--------|-------------------|-----------------|------------------|
| GET    | `/<type>`         | список          | `<type>.view`    |
| GET    | `/<type>/<id>`    | один            | `<type>.view`    |
| POST   | `/<type>`         | создать         | `<type>.manage`  |
| PUT    | `/<type>/<id>`    | заменить        | `<type>.manage`  |
| PATCH  | `/<type>/<id>`    | частично изменить| `<type>.manage` |
| DELETE | `/<type>/<id>`    | удалить         | `<type>.manage`  |

PATCH применяет переданные поля к существующей сущности; PUT/POST принимают
полный набор полей (отсутствующие берут значения по умолчанию из схемы).

### Ответы

- Успех: `{"ok": true, ...}` (`items`, `item`, `id` или `deleted`).
- Ошибка: `{"ok": false, "error": "..."}` со статусом `400/401/403/404/405/422`.
- Создание возвращает `201`.
- Поля `password` доступны только на запись: не возвращаются, показываются как `********`.

## Примеры

```bash
# Список офферов
curl -H "Authorization: Bearer $TOKEN" https://host/api/rest.php/offers

# Создать оффер
curl -X POST -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -d '{"name":"My Offer","payout":"12.5","currency":"USD"}' \
  https://host/api/rest.php/offers

# Изменить одно поле
curl -X PATCH -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -d '{"payout":"15"}' https://host/api/rest.php/offers/3

# Удалить
curl -X DELETE -H "Authorization: Bearer $TOKEN" https://host/api/rest.php/offers/3

# Спецификация OpenAPI
curl https://host/api/openapi.php
```

## Примечания

- API управляется данными: добавление поля в схему автоматически расширяет API и
  документ OpenAPI — без изменений кода.
- Сбои внешних систем не влияют на API; все ошибки локальные и детерминированные.
- Страница Swagger UI (`admin/api.php`) загружает спецификацию из `api/openapi.php`.
