# TgRemainder — Telegram-напоминания из Excel

![PHP](https://img.shields.io/badge/PHP-8.2%2B-blue)
![License](https://img.shields.io/badge/license-MIT-green)

Минималистичный Telegram-бот на PHP 8.2+ для импорта напоминаний из Excel и отправки их в Telegram. Поддерживаются множественные даты/времена, повторы (по дням и месяцам), а также экспорт данных обратно в Excel. Продакшен — MySQL/MariaDB. SQLite используется только в тестах. Логирование — PSR-3 через фасад.

## Оглавление

- [Возможности](#возможности)
- [Требования](#требования)
- [Установка](#установка)
- [Конфигурация (ENV)](#конфигурация-env)
- [Запуск](#запуск)
    - [Webhook](#webhook)
    - [Cron](#cron)
- [Формат Excel и режимы](#формат-excel-и-режимы)
- [Экспорт](#экспорт)
- [Структура проекта](#структура-проекта)
- [База данных](#база-данных)
- [Тесты](#тесты)
- [Код-стайл и логи](#код-стайл-и-логи)
- [Лицензия](#лицензия)

## Возможности

- Импорт `.xlsx` прямо из чата Telegram администраторами
- Точность отправки напоминаний — до минуты
- Повторы: по дням (в т.ч. “через N дней”/дни недели — зависит от заполнения поля) и по месяцам
- Экспорт текущих данных в Excel; временный файл удаляется с сервера сразу после успешной отправки в Telegram
- Безопасный webhook: ранний ACK, проверка секретного заголовка
- Cron-задача с файловой блокировкой для защиты от параллельных запусков
- Единый фасад логирования (PSR-3), текстовые логи без “украшательств”

## Требования

- PHP 8.2+
- Расширения PHP: `ext-json`, `ext-mbstring`, `ext-curl`, `ext-pdo`, `ext-pdo_mysql`, `ext-xlswriter`
- Composer
- MySQL/MariaDB (SQLite — только для тестов)

## Установка

```bash
composer install
cp .env.example .env
# заполните .env (токен бота, секрет вебхука, доступ к БД и т. п.)
```

## Конфигурация (ENV)

Минимальный набор переменных:

```dotenv
TELEGRAM_BOT_TOKEN=...
TG_WEBHOOK_SECRET=...

DB_DSN=mysql:host=127.0.0.1;dbname=tgr;charset=utf8mb4
DB_USER=tgr
DB_PASS=pass

APP_TIMEZONE=Europe/Moscow
HTTP_TIMEOUT=3
HTTP_CONNECT_TIMEOUT=2

LOG_DIR=/path/to/project/logs
LOG_MIN_LEVEL=error

ADMIN_IDS=123456789,987654321
DEFAULT_TIME=12:00
```

`.env.example` включён в репозиторий.

## Запуск

### Webhook

Раздайте `public/webhook.php` по HTTPS и установите webhook у Telegram, передавая секрет: заголовок `X-Telegram-Bot-Api-Secret-Token` должен совпадать с `TG_WEBHOOK_SECRET`.

Пример команды:

```bash
curl -F "url=https://your.domain/public/webhook.php" \
     -F "secret_token=$TG_WEBHOOK_SECRET" \
     "https://api.telegram.org/bot$TELEGRAM_BOT_TOKEN/setWebhook"
```

### Cron

Запускайте `public/cron_send_reminders.php` раз в минуту. Внутри есть файловая блокировка.

Пример crontab:

```bash
* * * * * /usr/bin/php /path/to/project/public/cron_send_reminders.php > /dev/null 2>&1
```

## Формат Excel и режимы

### Колонки (имена колонок важны; регистр и порядок не важны)

- `date` — обязательно, `ДД.ММ.ГГГГ` или список через запятую
- `event` — обязательно, текст напоминания
- `contacts` — обязательно, `chat_id` (только цифры) или `@username`. Списки: через запятую, `;` или перенос строки
- `time` — опционально. Допустимые формы:
    - `H:MM` / `HH:MM`, `H.MM` / `HH.MM`
    - `H:M` (одна цифра минут — дополняется нулём), `H.M` (десятки минут)
    - просто `H` (целый час). Можно список через запятую
    - если пусто — берётся `DEFAULT_TIME` из `.env`
- `repeat_days` — опционально: повторы (дни/месяцы; формат зависит от настроек и поддерживаемых значений)
    - примеры: целое число дней (`3`), месяцы (`1m` / `1м`)

### Режимы импорта

Указываются в подписи (caption) к загружаемому файлу:

- `update` — добавляет события, не удаляя существующие (по умолчанию)
- `replace` — полная замена данных

## Экспорт

Кнопка «Экспорт в Excel» формирует `.xlsx` и отправляет его администратору в Telegram. Временный файл создаётся на диске и удаляется сразу после успешной отправки.

## Структура проекта

- `src/Bootstrap/*` — загрузка .env, фабрика PDO, системные лимиты/таймзона, аварийный SOS-лог до инициализации, раннер EntryKernel
- `src/Logger/*` — файловый логгер, фильтр уровней, выбор файла по контексту, фасад LogFacade (PSR-3)
- `src/Telegram/*` — лёгкий клиент Telegram Bot API (cURL), Update, Router, AdminHandler, ReminderChecker, AppConfig
- `src/Repositories/*` — интерфейс и реализации для MySQL и SQLite
- `src/Services/*` — ExcelReminderParser (fail-fast парсер), ExcelReminderExporter (на ext-xlswriter)
- `storage/` — шаблоны и временные файлы (шаблон Excel)
- `logs/` — логи приложения
- Entry points: `public/webhook.php`, `public/cron_send_reminders.php`

## База данных

- Продакшен: MySQL/MariaDB
- DDL можно положить в `database/migrations/mysql/V001__init.sql` и применить вручную (в проекте нет собственного раннера миграций)
- Тесты: `sqlite::memory:`; схема создаётся в коде тестов
- Основные таблицы: `contacts`, `events`, `event_times`, `event_contacts`
- Точность сравнения времени — до минуты (`YYYY-MM-DD HH:MM`)

## Тесты

- PHPUnit (юнит- и интеграционные)
- Интеграции используют SQLite in-memory и поднимают схему в тесте
- Тесты, требующие ext-xlswriter, автоматически пропускаются, если расширение не установлено

Запуск:

```bash
vendor/bin/phpunit --testdox
```

## Код-стайл и логи

- Код ориентирован на PSR-12
- Логи пишутся через `TgRemainder\Logger\LogFacade`, выбор файла — контекстом (`['file' => 'webhook.log']`, `['file' => 'cron.log']` и т. п.)
- Сообщения пользователю в Telegram могут содержать эмодзи

## Лицензия

MIT