-- encoding: utf8mb4
SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- Таблица миграций
CREATE TABLE IF NOT EXISTS schema_migrations (
                                                 version    VARCHAR(50) PRIMARY KEY COMMENT 'Версия миграции',
    applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Когда применена'
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Миграции схемы';

-- Контакты
CREATE TABLE IF NOT EXISTS contacts (
                                        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'ID контакта',
                                        telegram_id BIGINT NOT NULL COMMENT 'Telegram chat_id (может быть отрицательным для групп/каналов)',
                                        username VARCHAR(32) NULL COMMENT 'Юзернейм (без @)',
    first_name VARCHAR(255) NULL COMMENT 'Имя',
    last_name VARCHAR(255) NULL COMMENT 'Фамилия',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Дата создания',
    PRIMARY KEY (id),
    UNIQUE KEY uq_contacts_telegram_id (telegram_id),
    UNIQUE KEY uq_contacts_username (username)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Контакты';

-- События/напоминания
CREATE TABLE IF NOT EXISTS events (
                                      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'ID события',
                                      text TEXT NOT NULL COMMENT 'Текст напоминания',
                                      repeat_days VARCHAR(32) NULL COMMENT 'Повторы (строка/число/тип:интервал в виде строки)',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Дата создания',
    PRIMARY KEY (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='События';

-- Временные точки напоминаний
CREATE TABLE IF NOT EXISTS event_times (
                                           id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'ID записи времени',
                                           event_id BIGINT UNSIGNED NOT NULL COMMENT 'ID события',
                                           remind_at DATETIME NOT NULL COMMENT 'Время напоминания (точность до минуты, секунды :00)',
                                           PRIMARY KEY (id),
    UNIQUE KEY uq_event_time (event_id, remind_at),
    KEY idx_remind_at (remind_at),
    CONSTRAINT fk_event_times_event
    FOREIGN KEY (event_id) REFERENCES events(id)
    ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Времена напоминаний';

-- Связь событий и контактов (многие-ко-многим)
CREATE TABLE IF NOT EXISTS event_contacts (
                                              event_id BIGINT UNSIGNED NOT NULL COMMENT 'ID события',
                                              contact_id BIGINT UNSIGNED NOT NULL COMMENT 'ID контакта',
                                              PRIMARY KEY (event_id, contact_id),
    KEY idx_event_contacts_contact (contact_id),
    CONSTRAINT fk_event_contacts_event
    FOREIGN KEY (event_id) REFERENCES events(id)
    ON DELETE CASCADE,
    CONSTRAINT fk_event_contacts_contact
    FOREIGN KEY (contact_id) REFERENCES contacts(id)
    ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Связь событий и контактов';

-- отметка миграции (если ты реально используешь schema_migrations)
INSERT INTO schema_migrations(version) VALUES ('001_init')
    ON DUPLICATE KEY UPDATE applied_at = applied_at;