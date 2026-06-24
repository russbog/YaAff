# Документация YellowCloaker

Это основная справка по продукту, админ-панели и runtime-логике проекта.

## Содержание

- [Обзор продукта](overview.md)
- [Установка на VPS](installation.md)
- [Как это работает](how-it-works.md)
- [Вход в админку](admin-login.md)
- [Админ-панель](admin-panel.md)
- [Кампании](campaigns.md)
- [Настройки кампании](campaign-settings.md)
- [White settings](white-settings.md)
- [Black settings и flows](black-settings-and-flows.md)
- [Scripts](scripts.md)
- [Postbacks](postbacks.md)
- [Статистика](statistics.md)
- [Click views](clicks-and-views.md)
- [API и endpoints](api-and-endpoints.md)
- [Тестирование и диагностика](testing-and-diagnostics.md)
- [Troubleshooting и FAQ](troubleshooting-and-faq.md)

## Архитектура (форк)

- [Фаза 0: Абстракция БД, слой сущностей и миграции](phase-0-db-abstraction.md)
- [Фаза 1: Первоклассные сущности (Сети, Источники, Офферы, Лендинги)](phase-1-entities.md)
- [Фаза 2: Режимы трекинга, расширенные фильтры и типы потоков](phase-2-routing-filters-flows.md)
- [Фаза 3: Конверсии и Conversion API](phase-3-conversions-api.md)
- [Фаза 4: Единая токен-система (TokenRegistry)](phase-4-token-system.md)
- [Фаза 5: Домены + Cloudflare + пул доменов](phase-5-domains.md)
- [Фаза 6: Бот-защита — авто-обновляемые IP/UA блэклисты](phase-6-bot-protection.md)

## С чего начать

Если вы впервые открыли проект:

1. Прочитайте [Обзор продукта](overview.md).
2. Если нужен свой VPS, выполните [Установку на VPS](installation.md).
3. Затем [Как это работает](how-it-works.md).
4. После этого [Вход в админку](admin-login.md) и [Админ-панель](admin-panel.md).
5. Для практической настройки переходите в [Настройки кампании](campaign-settings.md).
