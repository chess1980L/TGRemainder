<?php
declare(strict_types=1);

namespace TgRemainder\Telegram;

use PDO;

/**
 * Контекст обработки апдейта.
 * Содержит ссылки на бота, апдейт и подключение к БД.
 */
final class CommandContext
{
    public readonly Bot $bot;
    public readonly Update $update;
    public readonly PDO $db;

    public function __construct(Bot $bot, Update $update, PDO $db)
    {
        $this->bot    = $bot;
        $this->update = $update;
        $this->db     = $db;
    }
}
