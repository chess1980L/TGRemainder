<?php

declare(strict_types=1);

namespace TgRemainder\Repositories;

use PDO;
use Throwable;
use TgRemainder\Logger\LogFacade as Log;
use TgRemainder\Utils\DateTimeNormalizer;
use TgRemainder\Utils\RepeatDaysNormalizer;

/**
 * Упрощенный репозиторий для SQLite (тесты и локальная работа).
 */
final class SQLiteEventRepository implements EventRepositoryInterface
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /** @inheritDoc */
    public function saveEvents(array $reminders): int
    {
        $count = 0;

        $insertEvent = $this->db->prepare(
            'INSERT INTO events (text, repeat_days) VALUES (:text, :repeat_days)'
        );
        $insertTime = $this->db->prepare(
            'INSERT INTO event_times (event_id, remind_at) VALUES (:event_id, :remind_at)'
        );
        $insertContact = $this->db->prepare(
            'INSERT OR IGNORE INTO contacts (telegram_id) VALUES (:telegram_id)'
        );
        $getContactId = $this->db->prepare(
            'SELECT id FROM contacts WHERE telegram_id = :telegram_id'
        );
        $insertEventContact = $this->db->prepare(
            'INSERT OR IGNORE INTO event_contacts (event_id, contact_id) VALUES (:event_id, :contact_id)'
        );
        $findTelegramIdByUsername = $this->db->prepare(
            'SELECT telegram_id FROM contacts WHERE username = :username'
        );

        foreach ($reminders as $reminder) {
            try {
                $this->db->beginTransaction();

                $repeatValue = RepeatDaysNormalizer::toStorage($reminder['repeat_days'] ?? null);

                $insertEvent->execute([
                    'text'        => $reminder['text'],
                    'repeat_days' => $repeatValue,
                ]);

                $eventId = (int) $this->db->lastInsertId();
                if ($eventId <= 0) {
                    throw new \RuntimeException('SQLite: lastInsertId() вернул 0');
                }

                foreach ($reminder['dates'] as $dt) {
                    $insertTime->execute([
                        'event_id'  => $eventId,
                        'remind_at' => DateTimeNormalizer::toStorageSQLite($dt),
                    ]);
                }

                $linkedAny = false;

                foreach ($reminder['targets'] as $target) {
                    $telegramId = trim((string) $target);

                    // telegram_id (включая отрицательные)
                    if (!preg_match('/^-?\d{5,20}$/', $telegramId)) {
                        // username: с @ или без
                        if (preg_match('/^@?([a-zA-Z0-9_]{5,32})$/', $telegramId, $m)) {
                            $normalized = $m[1];
                            $findTelegramIdByUsername->execute(['username' => $normalized]);
                            $telegramId = (string) ($findTelegramIdByUsername->fetchColumn() ?: '');

                            if ($telegramId === '' || (int) $telegramId === 0) {
                                Log::warning('SQLite saveEvents: username не найден — пропуск', [
                                    'username' => $normalized,
                                ]);
                                continue;
                            }
                        } else {
                            continue;
                        }
                    }

                    if ((int) $telegramId === 0) {
                        continue;
                    }

                    $insertContact->execute(['telegram_id' => (int) $telegramId]);

                    $getContactId->execute(['telegram_id' => (int) $telegramId]);
                    $contactId = $getContactId->fetchColumn();
                    if (!$contactId) {
                        Log::error('SQLite saveEvents: contact_id не получен', [
                            'telegram_id' => (int) $telegramId,
                        ]);
                        continue;
                    }

                    $insertEventContact->execute([
                        'event_id'   => $eventId,
                        'contact_id' => (int) $contactId,
                    ]);

                    $linkedAny = true;
                }

                if (!$linkedAny) {
                    throw new \RuntimeException('SQLite saveEvents: event created but no contacts linked');
                }

                $this->db->commit();
                $count++;

            } catch (Throwable $e) {
                if ($this->db->inTransaction()) {
                    $this->db->rollBack();
                }
                Log::error('SQLite saveEvents: {e}', ['e' => $e]);
            }
        }

        return $count;
    }

    /** @inheritDoc */
    public function replaceAll(array $reminders): int
    {
        // Важно: в SQLite нельзя делать вложенные транзакции,
        // а saveEvents() сам открывает транзакцию на каждый reminder.
        // Поэтому транзакция только на блок DELETE.
        try {
            $this->db->beginTransaction();
            try {
                $this->db->exec('DELETE FROM event_contacts');
                $this->db->exec('DELETE FROM event_times');
                $this->db->exec('DELETE FROM events');
                $this->db->commit();
            } catch (Throwable $e) {
                if ($this->db->inTransaction()) {
                    $this->db->rollBack();
                }
                throw $e;
            }
        } catch (Throwable $e) {
            Log::error('SQLite replaceAll: {e}', ['e' => $e]);
            return 0;
        }

        return $this->saveEvents($reminders);
    }

    /** @inheritDoc */
    public function deletePastEvents(): int
    {
        try {
            $stmt = $this->db->prepare(
            /** @lang SQL */ <<<'SQL'
SELECT e.id
FROM events e
JOIN event_times et ON et.event_id = e.id
WHERE e.repeat_days IS NULL OR e.repeat_days = ''
GROUP BY e.id
HAVING MAX(et.remind_at) < strftime('%Y-%m-%d %H:%M', 'now')
SQL
            );
            $stmt->execute();

            /** @var array<int,int|string> $eventIds */
            $eventIds = (array) $stmt->fetchAll(PDO::FETCH_COLUMN);
            if (empty($eventIds)) {
                return 0;
            }

            $eventIdsStr = implode(',', array_map('intval', $eventIds));

            $this->db->beginTransaction();
            try {
                $this->db->exec("DELETE FROM event_contacts WHERE event_id IN ({$eventIdsStr})");
                $this->db->exec("DELETE FROM event_times   WHERE event_id IN ({$eventIdsStr})");
                $this->db->exec("DELETE FROM events        WHERE id       IN ({$eventIdsStr})");

                $this->db->commit();
            } catch (Throwable $e) {
                if ($this->db->inTransaction()) {
                    $this->db->rollBack();
                }
                throw $e;
            }

            return count($eventIds);
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            Log::error('SQLite deletePastEvents: {e}', ['e' => $e]);
            return 0;
        }
    }

    /** @inheritDoc */
    public function postponeReminder(int $reminderId, int $days): void
    {
        $stmt = $this->db->prepare(
        /** @lang SQL */ <<<'SQL'
UPDATE event_times
   SET remind_at = strftime('%Y-%m-%d %H:%M', datetime(remind_at, :days || ' days'))
 WHERE id = :id
SQL
        );
        $stmt->execute(['days' => $days, 'id' => $reminderId]);
    }

    /** @inheritDoc */
    public function postponeReminderByMonth(int $reminderId, int $months): void
    {
        $stmt = $this->db->prepare(
        /** @lang SQL */ <<<'SQL'
UPDATE event_times
   SET remind_at = strftime('%Y-%m-%d %H:%M', datetime(remind_at, :months || ' months'))
 WHERE id = :id
SQL
        );
        $stmt->execute(['months' => $months, 'id' => $reminderId]);
    }

    /** @inheritDoc */
    public function rescheduleReminderAt(int $reminderId, string $datetime): void
    {
        $dt = DateTimeNormalizer::toStorageSQLite($datetime);

        $stmt = $this->db->prepare(
            'UPDATE event_times
                SET remind_at = :dt
              WHERE id = :id'
        );

        $stmt->execute([
            'dt' => $dt,
            'id' => $reminderId,
        ]);
    }

    /** @inheritDoc */
    public function deleteReminder(int $reminderId): void
    {
        try {
            $this->db->beginTransaction();

            // 1) event_id по reminder
            $stmt = $this->db->prepare('SELECT event_id FROM event_times WHERE id = :id');
            $stmt->execute(['id' => $reminderId]);

            $eventId = (int) ($stmt->fetchColumn() ?: 0);
            if ($eventId <= 0) {
                $this->db->commit();
                return;
            }

            // 2) Удаляем конкретное время
            $del = $this->db->prepare('DELETE FROM event_times WHERE id = :id');
            $del->execute(['id' => $reminderId]);

            // 3) Если это было последнее время — чистим одноразовый event целиком
            $leftStmt = $this->db->prepare('SELECT COUNT(*) FROM event_times WHERE event_id = :event_id');
            $leftStmt->execute(['event_id' => $eventId]);

            $left = (int) $leftStmt->fetchColumn();
            if ($left === 0) {
                $repStmt = $this->db->prepare('SELECT repeat_days FROM events WHERE id = :id');
                $repStmt->execute(['id' => $eventId]);

                $repeat = $repStmt->fetchColumn();
                if ($repeat === null || $repeat === '') {
                    $this->db->prepare('DELETE FROM event_contacts WHERE event_id = :event_id')
                        ->execute(['event_id' => $eventId]);

                    $this->db->prepare('DELETE FROM events WHERE id = :event_id')
                        ->execute(['event_id' => $eventId]);
                }
            }

            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            Log::error('SQLite deleteReminder: {e}', [
                'e'           => $e,
                'reminder_id' => $reminderId,
            ]);
        }
    }

    /** @inheritDoc */
    public function getDueRemindersAtExact(string $datetime): array
    {
        $minute = DateTimeNormalizer::toStorageSQLite($datetime);

        $stmt = $this->db->prepare(
        /** @lang SQL */ <<<'SQL'
SELECT
    et.id,
    et.remind_at,
    e.text,
    e.repeat_days,
    c.telegram_id AS chat_id
FROM event_times et
JOIN events e          ON et.event_id = e.id
JOIN event_contacts ec ON ec.event_id = e.id
JOIN contacts c        ON c.id = ec.contact_id
WHERE et.remind_at = :minute
SQL
        );
        $stmt->execute(['minute' => $minute]);

        /** @var array<int,array<string,mixed>> $rows */
        $rows = (array) $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $rows;
    }

    /** @inheritDoc */
    public function getDueRemindersInWindow(string $fromMinute, string $toMinute): array
    {
        $from = DateTimeNormalizer::toStorageSQLite($fromMinute);
        $to   = DateTimeNormalizer::toStorageSQLite($toMinute);

        $stmt = $this->db->prepare(
        /** @lang SQL */ <<<'SQL'
SELECT
    et.id,
    et.remind_at,
    e.text,
    e.repeat_days,
    c.telegram_id AS chat_id
FROM event_times et
JOIN events e          ON et.event_id = e.id
JOIN event_contacts ec ON ec.event_id = e.id
JOIN contacts c        ON c.id = ec.contact_id
WHERE et.remind_at BETWEEN :from AND :to
ORDER BY et.id
SQL
        );
        $stmt->execute([
            'from' => $from,
            'to'   => $to,
        ]);

        /** @var array<int,array<string,mixed>> $rows */
        $rows = (array) $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $rows;
    }

    /** @inheritDoc */
    public function getAllForExport(): array
    {
        $stmt = $this->db->prepare(
        /** @lang SQL */ <<<'SQL'
SELECT
    e.id AS event_id,
    e.text,
    e.repeat_days,
    et.remind_at,
    c.telegram_id,
    c.username
FROM events e
JOIN event_times    et ON et.event_id = e.id
JOIN event_contacts ec ON ec.event_id = e.id
JOIN contacts       c  ON c.id = ec.contact_id
ORDER BY e.id, et.remind_at
SQL
        );
        $stmt->execute();

        /** @var array<int,array<string,mixed>> $rows */
        $rows = (array) $stmt->fetchAll(PDO::FETCH_ASSOC);
        $grouped = [];

        foreach ($rows as $row) {
            $eventId = (int) $row['event_id'];

            if (!isset($grouped[$eventId])) {
                $grouped[$eventId] = [
                    'text'        => $row['text'],
                    'repeat_days' => $row['repeat_days'],
                    'remind_at'   => [],
                    'contacts'    => [],
                ];
            }

            $grouped[$eventId]['remind_at'][] = $row['remind_at'];
            $grouped[$eventId]['contacts'][]  = $row['username'] ?: $row['telegram_id'];
        }

        /** @var array<int,array{
         *   text:string,
         *   repeat_days:string|null,
         *   remind_at:array<int,string>,
         *   contacts:array<int,string|int>
         * }> $grouped
         */
        return $grouped;
    }
}