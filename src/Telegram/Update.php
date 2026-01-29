<?php
declare(strict_types=1);

namespace TgRemainder\Telegram;

/**
 * Обёртка над входящим Telegram Update.
 *
 * Назначение:
 * - безопасный доступ (без NOTICE при строгом error_handler)
 * - геттеры/проверки без побочных эффектов
 * - стабильные сигнатуры для кода выше (Router/AdminHandler)
 *
 * Контракты:
 * - Методы не бросают исключений; при отсутствии полей — дефолты.
 * - Chat ID — int; 0 ⇒ «нет чата/не применимо».
 * - Строки нормализуются через trim(); пустая строка ⇒ null.
 *
 * @psalm-type TUpdate = array<string,mixed>
 */
final class Update
{
    /** @var array<string,mixed> Сырые данные апдейта. */
    private readonly array $data;

    /**
     * @param array<string,mixed> $data Сырые данные апдейта
     */
    public function __construct(array $data)
    {
        $this->data = $data;
    }

    /**
     * Идентификатор чата.
     * 0 — «чата нет» (служебный апдейт).
     */
    public function getChatId(): int
    {
        $id = $this->data['message']['chat']['id'] ?? null;
        return is_int($id) ? $id : (is_numeric($id) ? (int) $id : 0);
    }

    /**
     * Текст сообщения (trim) или null.
     */
    public function getText(): ?string
    {
        $text = $this->data['message']['text'] ?? null;
        if (!is_string($text)) {
            return null;
        }

        $text = trim($text);
        return $text === '' ? null : $text;
    }

    /**
     * Проверка, что сообщение от администратора.
     *
     * @param int[] $adminIds Список разрешённых ID
     */
    public function isFromAdmin(array $adminIds): bool
    {
        if ($adminIds === []) {
            return false;
        }

        $fromId = $this->data['message']['from']['id'] ?? null;
        $fromId = is_int($fromId) ? $fromId : (is_numeric($fromId) ? (int) $fromId : 0);

        return $fromId !== 0 && in_array($fromId, $adminIds, true);
    }

    /**
     * Массив документа из сообщения или null.
     *
     * @return array<string,mixed>|null
     */
    public function getDocument(): ?array
    {
        $doc = $this->data['message']['document'] ?? null;
        return is_array($doc) ? $doc : null;
    }

    /**
     * Подпись к сообщению/документу (trim) или null.
     */
    public function getCaption(): ?string
    {
        $caption = $this->data['message']['caption'] ?? null;
        if (!is_string($caption)) {
            return null;
        }

        $caption = trim($caption);
        return $caption === '' ? null : $caption;
    }

    /**
     * Данные об отправителе. Пустой массив если раздел отсутствует.
     *
     * @return array<string,mixed>
     */
    public function getUser(): array
    {
        $user = $this->data['message']['from'] ?? [];
        return is_array($user) ? $user : [];
    }

    /**
     * Является ли входящий текст командой бота.
     * Предпочитаем entity type=bot_command c offset=0; иначе — prefix '/'.
     */
    public function isCommand(): bool
    {
        $entities = $this->data['message']['entities'] ?? null;

        if (is_array($entities)) {
            foreach ($entities as $e) {
                if (is_array($e)
                    && ($e['type'] ?? null) === 'bot_command'
                    && (int) ($e['offset'] ?? -1) === 0
                ) {
                    return true;
                }
            }
        }

        $text = $this->getText();
        return $text !== null && str_starts_with($text, '/');
    }
}