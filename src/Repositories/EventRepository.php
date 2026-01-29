<?php

declare(strict_types=1);

namespace TgRemainder\Repositories;

use PDO;
use RuntimeException;
use Throwable;
use TgRemainder\Db\TransactionManager;
use TgRemainder\Logger\LogFacade as Log;
use TgRemainder\Utils\DateTimeNormalizer;
use TgRemainder\Utils\RepeatDaysNormalizer;

/**
 * Репозиторий событий (MySQL).
 */
final class EventRepository implements EventRepositoryInterface
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * @inheritDoc
     *
     * @param array<int, array{
     *   text: string,
     *   dates: array<int, string>,
     *   targets: array<int, string|int>,
     *   repeat_days?: string|int|null|array{type:string,interval:int}
     * }> $reminders
     */
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
            'INSERT IGNORE INTO contacts (telegram_id) VALUES (:telegram_id)'
        );
        $getContactId = $this->db->prepare(
            'SELECT id FROM contacts WHERE telegram_id = :telegram_id'
        );
        $insertEventContact = $this->db->prepare(
            'INSERT IGNORE INTO event_contacts (event_id, contact_id) VALUES (:event_id, :contact_id)'
        );

        $subscriberRepo = new SubscriberRepository($this->db);

        foreach ($reminders as $reminder) {
            try {
                $telegramIds = $subscriberRepo->resolveTargets($reminder['targets'] ?? []);
                if ($telegramIds === []) {
                    Log::warning('saveEvents: no targets resolved, skip reminder', [
                        'text_len' => strlen((string) ($reminder['text'] ?? '')),
                    ]);
                    continue;
                }

                TransactionManager::run(
                    $this->db,
                    function () use (
                        $reminder,
                        $telegramIds,
                        $insertEvent,
                        $insertTime,
                        $insertContact,
                        $getContactId,
                        $insertEventContact
                    ): void {
                        $repeatValue = RepeatDaysNormalizer::toStorage($reminder['repeat_days'] ?? null);

                        $insertEvent->execute([
                            'text'        => $reminder['text'],
                            'repeat_days' => $repeatValue,
                        ]);

                        $eventId = (int) $this->db->lastInsertId();
                        if ($eventId <= 0) {
                            throw new RuntimeException('events: lastInsertId() вернул 0');
                        }

                        foreach ($reminder['dates'] as $dt) {
                            $insertTime->execute([
                                'event_id'  => $eventId,
                                'remind_at' => DateTimeNormalizer::toStorageMySQL($dt),
                            ]);
                        }

                        $linkedAny = false;

                        foreach ($telegramIds as $telegramId) {
                            if ($telegramId === 0) {
                                continue;
                            }

                            $insertContact->execute(['telegram_id' => $telegramId]);

                            $getContactId->execute(['telegram_id' => $telegramId]);
                            $contactId = $getContactId->fetchColumn();
                            if (!$contactId) {
                                Log::error('saveEvents: contact_id не получен', [
                                    'telegram_id' => $telegramId,
                                ]);
                                continue;
                            }

                            $insertEventContact->execute([
                                'event_id'   => $eventId,
                                'contact_id' => (int) $contactId,
                            ]);

                            $linkedAny = true;
                        }

                        // ВАЖНО: не оставляем "висячие" events/event_times без связей
                        if (!$linkedAny) {
                            throw new RuntimeException('saveEvents: event created but no contacts linked');
                        }
                    },
                    retries: 1
                );

                $count++;
            } catch (Throwable $e) {
                Log::error('saveEvents: {e}', ['e' => $e]);
            }
        }

        return $count;
    }

    /**
     * @inheritDoc
     *
     * @param array<int, array{
     *   text: string,
     *   dates: array<int, string>,
     *   targets: array<int, string|int>,
     *   repeat_days?: string|int|null|array{type:string,interval:int}
     * }> $reminders
     */
    public function replaceAll(array $reminders): int
    {
        try {
            return TransactionManager::run(
                $this->db,
                function () use ($reminders): int {
                    $this->db->exec('DELETE FROM event_contacts');
                    $this->db->exec('DELETE FROM event_times');
                    $this->db->exec('DELETE FROM events');

                    // saveEvents внутри одной транзакции — вложенные операции уйдут в SAVEPOINT
                    return $this->saveEvents($reminders);
                },
                retries: 1
            );
        } catch (Throwable $e) {
            Log::error('replaceAll: {e}', ['e' => $e]);
            return 0;
        }
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
HAVING MAX(et.remind_at) < DATE_FORMAT(NOW(), '%Y-%m-%d %H:%i:00')
SQL
            );
            $stmt->execute();

            /** @var array<int,int> $eventIds */
            $eventIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

            if (empty($eventIds)) {
                return 0;
            }

            $eventIdsStr = implode(',', array_map('intval', $eventIds));

            TransactionManager::run(
                $this->db,
                function () use ($eventIdsStr): void {
                    $this->db->exec("DELETE FROM event_contacts WHERE event_id IN ({$eventIdsStr})");
                    $this->db->exec("DELETE FROM event_times   WHERE event_id IN ({$eventIdsStr})");
                    $this->db->exec("DELETE FROM events        WHERE id       IN ({$eventIdsStr})");
                },
                retries: 1
            );

            return count($eventIds);
        } catch (Throwable $e) {
            Log::error('deletePastEvents: {e}', ['e' => $e]);
            return 0;
        }
    }

    /** @inheritDoc */
    public function postponeReminder(int $reminderId, int $days): void
    {
        $stmt = $this->db->prepare(
            'UPDATE event_times
               SET remind_at = DATE_ADD(remind_at, INTERVAL :days DAY)
             WHERE id = :id'
        );
        $stmt->execute(['days' => $days, 'id' => $reminderId]);
    }

    /** @inheritDoc */
    public function postponeReminderByMonth(int $reminderId, int $months): void
    {
        $stmt = $this->db->prepare(
            'UPDATE event_times
               SET remind_at = DATE_ADD(remind_at, INTERVAL :months MONTH)
             WHERE id = :id'
        );
        $stmt->execute(['months' => $months, 'id' => $reminderId]);
    }

    /** @inheritDoc */
    public function rescheduleReminderAt(int $reminderId, string $datetime): void
    {
        $dt = DateTimeNormalizer::toStorageMySQL($datetime);

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
            TransactionManager::run(
                $this->db,
                function () use ($reminderId): void {
                    // 1) Узнаём event_id по reminder (event_times.id)
                    $stmt = $this->db->prepare('SELECT event_id FROM event_times WHERE id = :id');
                    $stmt->execute(['id' => $reminderId]);

                    $eventId = (int) ($stmt->fetchColumn() ?: 0);
                    if ($eventId <= 0) {
                        return; // уже удалено или некорректный id
                    }

                    // 2) Удаляем конкретное время
                    $del = $this->db->prepare('DELETE FROM event_times WHERE id = :id');
                    $del->execute(['id' => $reminderId]);

                    // 3) Если остались другие времена — всё
                    $leftStmt = $this->db->prepare('SELECT COUNT(*) FROM event_times WHERE event_id = :event_id');
                    $leftStmt->execute(['event_id' => $eventId]);

                    $left = (int) $leftStmt->fetchColumn();
                    if ($left > 0) {
                        return;
                    }

                    // 4) Если это одноразовое событие — чистим связи и событие
                    $repStmt = $this->db->prepare('SELECT repeat_days FROM events WHERE id = :id');
                    $repStmt->execute(['id' => $eventId]);

                    $repeat = $repStmt->fetchColumn();
                    if ($repeat !== null && $repeat !== '') {
                        return; // повторяющееся не трогаем
                    }

                    $this->db->prepare('DELETE FROM event_contacts WHERE event_id = :event_id')
                        ->execute(['event_id' => $eventId]);

                    $this->db->prepare('DELETE FROM events WHERE id = :event_id')
                        ->execute(['event_id' => $eventId]);
                },
                retries: 1
            );
        } catch (Throwable $e) {
            Log::error('deleteReminder: {e}', [
                'e'           => $e,
                'reminder_id' => $reminderId,
            ]);
        }
    }

    /**
     * @inheritDoc
     *
     * @return array<int, array{
     *   id: int,
     *   remind_at: string,
     *   text: string,
     *   repeat_days: string|null,
     *   chat_id: int
     * }>
     */
    public function getDueRemindersAtExact(string $datetime): array
    {
        $dt = DateTimeNormalizer::toStorageMySQL($datetime);

        $sql = /** @lang SQL */ <<<'SQL'
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
WHERE et.remind_at = :dt
SQL;

        $stmt = $this->db->prepare($sql);
        $stmt->execute(['dt' => $dt]);

        /** @var array<int, array<string, mixed>> $rows */
        $rows = (array) $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $rows;
    }

    /**
     * @inheritDoc
     *
     * @return array<int, array{
     *   id: int,
     *   remind_at: string,
     *   text: string,
     *   repeat_days: string|null,
     *   chat_id: int
     * }>
     */
    public function getDueRemindersInWindow(string $fromMinute, string $toMinute): array
    {
        $from = DateTimeNormalizer::toStorageMySQL($fromMinute);
        $to   = DateTimeNormalizer::toStorageMySQL($toMinute);

        $sql = /** @lang SQL */ <<<'SQL'
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
SQL;

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'from' => $from,
            'to'   => $to,
        ]);

        /** @var array<int, array<string, mixed>> $rows */
        $rows = (array) $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $rows;
    }

    /**
     * @inheritDoc
     *
     * @return array<int, array{
     *   text: string,
     *   repeat_days: string|null,
     *   remind_at: array<int, string>,
     *   contacts: array<int, string|int>
     * }>
     */
    public function getAllForExport(): array
    {
        $sql = /** @lang SQL */ <<<'SQL'
SELECT
    e.id AS event_id,
    e.text,
    e.repeat_days,
    DATE_FORMAT(et.remind_at, '%Y-%m-%d %H:%i') AS remind_at,
    c.telegram_id,
    c.username
FROM events e
JOIN event_times    et ON et.event_id = e.id
JOIN event_contacts ec ON ec.event_id = e.id
JOIN contacts       c  ON c.id = ec.contact_id
ORDER BY e.id, et.remind_at
SQL;

        $stmt = $this->db->prepare($sql);
        $stmt->execute();

        /** @var array<int, array<string, mixed>> $rows */
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

        /** @var array<int, array{
         *   text: string,
         *   repeat_days: string|null,
         *   remind_at: array<int, string>,
         *   contacts: array<int, string|int>
         * }> $grouped
         */
        return $grouped;
    }
}