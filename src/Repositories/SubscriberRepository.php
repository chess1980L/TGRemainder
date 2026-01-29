<?php

declare(strict_types=1);

namespace TgRemainder\Repositories;

use PDO;

/**
 * Репозиторий подписчиков (таблица contacts).
 */
final class SubscriberRepository
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Проверяет наличие подписчика по telegram_id.
     */
    public function exists(int $telegramId): bool
    {
        $stmt = $this->db->prepare(
            'SELECT id FROM contacts WHERE telegram_id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $telegramId]);

        return (bool) $stmt->fetchColumn();
    }

    /**
     * Создаёт подписчика или обновляет его данные (username/имя), если запись уже была.
     * Важно: не затираем существующие значения NULL-ами.
     *
     * @param array{
     *   telegram_id:int,
     *   username?:string|null,
     *   first_name?:string|null,
     *   last_name?:string|null
     * } $subscriber
     *
     * @return bool true — создана новая запись; false — уже существовал (или только обновили/ничего не изменилось).
     */
    public function save(array $subscriber): bool
    {
        $sql = <<<'SQL'
INSERT INTO contacts (telegram_id, username, first_name, last_name)
VALUES (:telegram_id, :username, :first_name, :last_name)
ON DUPLICATE KEY UPDATE
  username   = COALESCE(VALUES(username), contacts.username),
  first_name = COALESCE(VALUES(first_name), contacts.first_name),
  last_name  = COALESCE(VALUES(last_name), contacts.last_name)
SQL;

        $stmt = $this->db->prepare($sql);

        $stmt->execute([
            'telegram_id' => $subscriber['telegram_id'],
            'username'    => $subscriber['username']   ?? null,
            'first_name'  => $subscriber['first_name'] ?? null,
            'last_name'   => $subscriber['last_name']  ?? null,
        ]);

        // MySQL: 1 = вставка, 2 = обновление существующей, 0 = ничего не изменилось
        return $stmt->rowCount() === 1;
    }

    /**
     * Возвращает всех подписчиков (служебно).
     *
     * @return array<int,array<string,mixed>>
     */
    public function getAll(): array
    {
        $stmt = $this->db->query(
            'SELECT * FROM contacts ORDER BY created_at DESC'
        );

        /** @var array<int,array<string,mixed>> $rows */
        $rows = (array) $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $rows;
    }

    /**
     * Возвращает telegram_id по username (без @).
     */
    public function getChatIdByUsername(string $username): ?int
    {
        $username = ltrim(trim($username), '@');
        if ($username === '') {
            return null;
        }

        $stmt = $this->db->prepare(
            'SELECT telegram_id FROM contacts WHERE username = :username LIMIT 1'
        );
        $stmt->execute(['username' => $username]);

        $chatId = $stmt->fetchColumn();

        return $chatId === false ? null : (int) $chatId;
    }

    /**
     * Нормализует массив целей (chat_id или username) в список telegram_id.
     *
     * Поддерживаем:
     * - "1234567"
     * - "-1001234567890" (группы/каналы)
     * - "@username" / "username"
     *
     * @param array<int,string|int> $targets
     * @return array<int,int> уникальные telegram_id
     */
    public function resolveTargets(array $targets): array
    {
        $result = [];

        foreach ($targets as $target) {
            $s = trim((string) $target);
            if ($s === '') {
                continue;
            }

            // chat_id (включая отрицательные)
            if (preg_match('/^-?\d{5,20}$/', $s)) {
                $result[] = (int) $s;
                continue;
            }

            // username (с @ или без)
            if (preg_match('/^@?([a-zA-Z0-9_]{5,32})$/', $s, $m)) {
                $chatId = $this->getChatIdByUsername($m[1]);
                if ($chatId !== null && $chatId !== 0) {
                    $result[] = $chatId;
                }
                continue;
            }
        }

        /** @var array<int,int> $unique */
        $unique = array_values(array_unique($result));

        return $unique;
    }
}