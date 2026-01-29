<?php

declare(strict_types=1);

namespace TgRemainder\Repositories;

/**
 * Контракт репозитория событий и напоминаний.
 */
interface EventRepositoryInterface
{
    /**
     * @param array<int,array{
     *   text:string,
     *   dates:array<int,string>,
     *   targets:array<int,string|int>,
     *   repeat_days?:string|int|null|array{type:string,interval:int}
     * }> $reminders
     */
    public function saveEvents(array $reminders): int;

    /**
     * @param array<int,array{
     *   text:string,
     *   dates:array<int,string>,
     *   targets:array<int,string|int>,
     *   repeat_days?:string|int|null|array{type:string,interval:int}
     * }> $reminders
     */
    public function replaceAll(array $reminders): int;

    public function deletePastEvents(): int;

    public function postponeReminder(int $reminderId, int $days): void;

    public function postponeReminderByMonth(int $reminderId, int $months): void;

    /**
     * Перенести конкретное напоминание (строку event_times) на конкретную минуту.
     *
     * @param string $datetime Любой parseable или 'YYYY-MM-DD HH:MM'
     */
    public function rescheduleReminderAt(int $reminderId, string $datetime): void;

    public function deleteReminder(int $reminderId): void;

    /**
     * Напоминания строго на конкретную минуту.
     *
     * @return array<int,array{
     *   id:int,
     *   remind_at:string,
     *   text:string,
     *   repeat_days:string|null,
     *   chat_id:int
     * }>
     */
    public function getDueRemindersAtExact(string $datetime): array;

    /**
     * Напоминания за окно (включительно): от $fromMinute до $toMinute.
     * Формат аргументов: 'YYYY-MM-DD HH:MM' или любой parseable.
     *
     * Важно: результат может содержать несколько строк на один reminder (id),
     * потому что один reminder может быть привязан к нескольким chat_id.
     *
     * @return array<int,array{
     *   id:int,
     *   remind_at:string,
     *   text:string,
     *   repeat_days:string|null,
     *   chat_id:int
     * }>
     */
    public function getDueRemindersInWindow(string $fromMinute, string $toMinute): array;

    /**
     * @return array<int,array{
     *   text:string,
     *   repeat_days:string|null,
     *   remind_at:array<int,string>,
     *   contacts:array<int,string|int>
     * }>
     */
    public function getAllForExport(): array;
}