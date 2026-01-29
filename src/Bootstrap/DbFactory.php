<?php

declare(strict_types=1);

namespace TgRemainder\Bootstrap;

use PDO;

/**
 * Фабрика PDO для БД проекта.
 */
final class DbFactory
{
    /**
     * Создаёт PDO с безопасными и экономными настройками.
     *
     * @param array{dsn:string,user:string,pass:string} $config
     */
    public static function create(array $config): PDO
    {
        return new PDO(
            $config['dsn'],
            $config['user'],
            $config['pass'],
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_TIMEOUT            => 5,
                PDO::ATTR_PERSISTENT         => false,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]
        );
    }
}