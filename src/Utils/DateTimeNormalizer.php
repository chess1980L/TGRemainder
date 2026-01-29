<?php

declare(strict_types=1);

namespace TgRemainder\Utils;

use DateTimeImmutable;
use InvalidArgumentException;
use Throwable;

final class DateTimeNormalizer
{
    /**
     * Приводит любой вход ('YYYY-MM-DD HH:MM', 'YYYY-MM-DD HH:MM:SS' или parseable)
     * к ключу минуты: 'YYYY-MM-DD HH:MM'.
     *
     * @throws InvalidArgumentException если строка не распарсилась
     */
    public static function toMinuteKey(string $dt): string
    {
        $dt = trim($dt);

        if (preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}/', $dt)) {
            $key = substr($dt, 0, 16);
            $key = str_replace('T', ' ', $key);

            $check = DateTimeImmutable::createFromFormat('Y-m-d H:i', $key);
            if ($check === false) {
                throw new InvalidArgumentException("Некорректная дата/время: '{$dt}'");
            }

            return $key;
        }

        try {
            $ts = new DateTimeImmutable($dt);
        } catch (Throwable $e) {
            throw new InvalidArgumentException("Некорректная дата/время: '{$dt}'", 0, $e);
        }

        return $ts->format('Y-m-d H:i');
    }

    /**
     * Текущая минута: 'YYYY-MM-DD HH:MM'
     */
    public static function nowMinute(): string
    {
        return (new DateTimeImmutable())->format('Y-m-d H:i');
    }

    /**
     * В SQLite храним минуту без секунд.
     */
    public static function toStorageSQLite(string $dt): string
    {
        return self::toMinuteKey($dt);
    }

    /**
     * В MySQL храним 'YYYY-MM-DD HH:MM:00' (секунды фиксируем),
     * чтобы выборки по '=' работали стабильно.
     */
    public static function toStorageMySQL(string $dt): string
    {
        return self::toMinuteKey($dt) . ':00';
    }
}