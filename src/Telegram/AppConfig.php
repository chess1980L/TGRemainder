<?php
declare(strict_types=1);

namespace TgRemainder\Telegram;

/**
 * Утилиты доступа к конфигам из ENV.
 */
final class AppConfig
{
    /**
     * Универсальный геттер строки из окружения.
     * Порядок: getenv() → $_ENV → $_SERVER → $default.
     */
    public static function get(string $key, string $default = ''): string
    {
        $v = getenv($key);
        if ($v === false) {
            if (array_key_exists($key, $_ENV)) {
                $v = $_ENV[$key];
            } elseif (array_key_exists($key, $_SERVER)) {
                $v = $_SERVER[$key];
            } else {
                $v = $default;
            }
        }

        return is_string($v) ? trim($v) : (string) $v;
    }

    /** Токен Telegram-бота. */
    public static function getTelegramToken(): string
    {
        return self::get('TELEGRAM_BOT_TOKEN');
    }

    /** Секрет для заголовка X-Telegram-Bot-Api-Secret-Token. */
    public static function getTelegramWebhookSecret(): string
    {
        return self::get('TG_WEBHOOK_SECRET');
    }

    /**
     * ADMIN_IDS="1,2,3" → [1,2,3].
     *
     * @return int[]
     */
    public static function getAdminIds(): array
    {
        $raw = self::get('ADMIN_IDS', '');
        $ids = array_map('trim', explode(',', $raw));
        $ids = array_filter($ids, static fn ($v) => $v !== '');

        return array_map('intval', $ids);
    }

    /**
     * Конфиг PDO.
     *
     * @return array{dsn:string,user:string,pass:string}
     */
    public static function getDbConfig(): array
    {
        return [
            'dsn'  => self::get('DB_DSN'),
            'user' => self::get('DB_USER'),
            'pass' => self::get('DB_PASS'),
        ];
    }

    /** Таймаут установления соединения (сек). */
    public static function getHttpConnectTimeout(): int
    {
        return (int) (float) self::get('HTTP_CONNECT_TIMEOUT', '2');
    }

    /** Общий таймаут запросов (сек). */
    public static function getHttpTimeout(): int
    {
        return (int) (float) self::get('HTTP_TIMEOUT', '3');
    }
}