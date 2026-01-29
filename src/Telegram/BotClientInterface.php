<?php

declare(strict_types=1);

namespace TgRemainder\Telegram;

interface BotClientInterface
{
    /**
     * @param array<string,mixed> $params
     */
    public function sendMessage(int $chatId, string $text, array $params = []): bool;

    /**
     * @return array{http_code:int, error_code?:int, description?:string, transport?:string}|null
     */
    public function getLastError(): ?array;
}