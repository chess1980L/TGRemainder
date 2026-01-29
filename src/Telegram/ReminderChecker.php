<?php

declare(strict_types=1);

namespace TgRemainder\Telegram;

use DateTimeImmutable;
use Throwable;
use TgRemainder\Logger\LogFacade as Log;
use TgRemainder\Repositories\EventRepositoryInterface;
use TgRemainder\Utils\DateTimeNormalizer;

/**
 * Периодическая отправка напоминаний.
 *
 * Поддержка "окна":
 * - если cron запускается реже, чем раз в минуту, можно выставить ENV:
 *   REMINDER_LOOKBACK_MINUTES=15 (например)
 * Тогда будут обработаны напоминания за последние N минут (включительно).
 *
 * Важно:
 * - один reminder (event_times.id) может быть привязан к нескольким контактам
 *   => выборка вернет несколько строк с одним id, но разными chat_id.
 * - перенос/удаление reminder делаем строго один раз на id, иначе "умножение" повторов.
 */
final class ReminderChecker
{
    private readonly EventRepositoryInterface $repo;
    private readonly BotClientInterface $bot;

    public function __construct(EventRepositoryInterface $repo, BotClientInterface $bot)
    {
        $this->repo = $repo;
        $this->bot  = $bot;
    }
    /**
     * @param string|null $dateTime Явное время (parseable) или null для now.
     * @return int Кол-во успешно отправленных сообщений.
     */
    public function sendDueReminders(?string $dateTime = null): int
    {
        $nowMinute = $dateTime !== null
            ? DateTimeNormalizer::toMinuteKey($dateTime)
            : DateTimeNormalizer::nowMinute();

        $lookback = $this->getLookbackMinutes();

        $t0 = microtime(true);
        Log::debug('RC: start minute={minute} lookback={lb}', [
            'minute' => $nowMinute,
            'lb'     => $lookback,
            'file'   => 'cron.log',
        ]);

        $fromMinute = $this->subtractMinutesFromMinuteKey($nowMinute, $lookback - 1);

        $rows = $lookback <= 1
            ? $this->repo->getDueRemindersAtExact($nowMinute)
            : $this->repo->getDueRemindersInWindow($fromMinute, $nowMinute);

        $totalRows = count($rows);

        // Группируем по reminderId (event_times.id), чтобы перенос/удаление не "умножались"
        $grouped = $this->groupByReminderId($rows);

        Log::debug('RC: rows={rows} reminders={rem}', [
            'rows' => $totalRows,
            'rem'  => count($grouped),
            'file' => 'cron.log',
        ]);

        $sentMessages = 0;

        foreach ($grouped as $reminderId => $r) {
            $text          = $r['text'];
            $repeatDaysRaw = $r['repeat_days'];
            $contacts      = $r['contacts'];

            if ($text === '' || $contacts === []) {
                Log::warning('RC: skip invalid grouped reminder', [
                    'reminder_id' => $reminderId,
                    'contacts'    => count($contacts),
                    'text_len'    => strlen($text),
                    'file'        => 'cron.log',
                ]);
                continue;
            }

            $sentAny = false;
            $lastErr = null;

            foreach ($contacts as $chatId) {
                $ok = false;
                try {
                    $ok = $this->bot->sendMessage($chatId, "🔔 Напоминание:\n{$text}");
                } catch (Throwable $e) {
                    Log::error('RC: send threw: {e}', [
                        'e'           => $e,
                        'reminder_id' => $reminderId,
                        'chat_id'     => $chatId,
                        'text_len'    => strlen($text),
                        'file'        => 'cron.log',
                    ]);
                    $ok = false;
                }

                if ($ok) {
                    $sentAny = true;
                    $sentMessages++;
                    Log::debug('RC: sent', [
                        'reminder_id' => $reminderId,
                        'chat_id'     => $chatId,
                        'text_len'    => strlen($text),
                        'file'        => 'cron.log',
                    ]);
                    continue;
                }

                $lastErr = $this->bot->getLastError();

                Log::warning('RC: send failed', [
                    'reminder_id' => $reminderId,
                    'chat_id'     => $chatId,
                    'err'         => $lastErr,
                    'file'        => 'cron.log',
                ]);
            }

            // Если вообще никто не получил — переносим, чтобы не потерялось
            if (!$sentAny) {
                $this->onSendFail($reminderId, $nowMinute, $lastErr);
                continue;
            }

            // Хотя бы один получил:
            // - repeat => переносим ОДИН раз
            // - одноразовое => удаляем ОДИН раз (иначе при lookback будут повторы)
            try {
                $this->finalizeAfterSend($reminderId, $repeatDaysRaw);
            } catch (Throwable $e) {
                Log::error('RC: finalize failed: {e}', [
                    'e'           => $e,
                    'reminder_id' => $reminderId,
                    'file'        => 'cron.log',
                ]);
            }
        }

        Log::debug('RC: done sent_msgs={sent} reminders={rem} rows={rows} in {sec}s', [
            'sent' => $sentMessages,
            'rem'  => count($grouped),
            'rows' => $totalRows,
            'sec'  => round(microtime(true) - $t0, 3),
            'file' => 'cron.log',
        ]);

        return $sentMessages;
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @return array<int,array{ text:string, repeat_days:string|null, contacts:int[] }>
     */
    private function groupByReminderId(array $rows): array
    {
        $grouped = [];

        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }

            if (!isset($grouped[$id])) {
                $grouped[$id] = [
                    'text'       => (string) ($row['text'] ?? ''),
                    'repeat_days' => $row['repeat_days'] ?? null,
                    'contacts'   => [],
                ];
            }

            $chatId = (int) ($row['chat_id'] ?? 0);
            if ($chatId !== 0) {
                $grouped[$id]['contacts'][] = $chatId;
            }
        }

        // unique contacts per reminder
        foreach ($grouped as $id => $g) {
            $grouped[$id]['contacts'] = array_values(array_unique($g['contacts']));
        }

        return $grouped;
    }

    private function getLookbackMinutes(): int
    {
        $raw = getenv('REMINDER_LOOKBACK_MINUTES');
        $n = is_string($raw) ? (int) trim($raw) : 1;

        // 1..1440 (сутки) — чтобы не сделать случайно огромный диапазон
        if ($n < 1) {
            $n = 1;
        } elseif ($n > 1440) {
            $n = 1440;
        }

        return $n;
    }

    /**
     * Фиксация после отправки:
     * - repeat => переносим на след. дату/месяц
     * - одноразовые => удаляем event_times (иначе lookback будет дублировать)
     */
    private function finalizeAfterSend(int $reminderId, mixed $repeatDaysRaw): void
    {
        if ($repeatDaysRaw !== null && $repeatDaysRaw !== '') {
            if (is_numeric($repeatDaysRaw)) {
                $days = (int) $repeatDaysRaw;
                $days = max(1, $days);

                $this->repo->postponeReminder($reminderId, $days);

                Log::debug('RC: postponed by days', [
                    'reminder_id' => $reminderId,
                    'days'        => $days,
                    'file'        => 'cron.log',
                ]);
                return;
            }

            if (preg_match('/^(\d+)\s*[mм]$/iu', (string) $repeatDaysRaw, $m)) {
                $months = (int) $m[1];
                $months = max(1, $months);

                $this->repo->postponeReminderByMonth($reminderId, $months);

                Log::debug('RC: postponed by months', [
                    'reminder_id' => $reminderId,
                    'months'      => $months,
                    'file'        => 'cron.log',
                ]);
                return;
            }

            Log::warning('RC: unknown repeat format (treat as one-time)', [
                'reminder_id' => $reminderId,
                'repeat_raw'  => (string) $repeatDaysRaw,
                'file'        => 'cron.log',
            ]);

            $this->repo->deleteReminder($reminderId);

            Log::debug('RC: deleted reminder after send due to unknown repeat', [
                'reminder_id' => $reminderId,
                'file'        => 'cron.log',
            ]);

            return;
        }

        $this->repo->deleteReminder($reminderId);

        Log::debug('RC: deleted one-time reminder after send', [
            'reminder_id' => $reminderId,
            'file'        => 'cron.log',
        ]);
    }

    /**
     * Простая стратегия, чтобы напоминание не терялось:
     * - 403/blocked: перенос на +1 день
     * - 429/timeout/прочее: перенос на +1 минуту
     *
     * @param array{http_code:int, error_code?:int, description?:string, transport?:string}|null $err
     */
    private function onSendFail(int $reminderId, string $nowMinute, ?array $err): void
    {
        $errorCode = (int) ($err['error_code'] ?? 0);
        $desc      = strtolower((string) ($err['description'] ?? ''));

        $isForbidden = $errorCode === 403
            || str_contains($desc, 'forbidden')
            || str_contains($desc, 'blocked');

        $isRateLimitOrTimeout = $errorCode === 429
            || str_contains($desc, 'too many requests')
            || str_contains($desc, 'timeout')
            || str_contains($desc, 'timed out');

        try {
            if ($isForbidden) {
                $this->repo->postponeReminder($reminderId, 1);

                Log::warning('RC: rescheduled (+1 day) after forbidden/blocked', [
                    'reminder_id' => $reminderId,
                    'file'        => 'cron.log',
                ]);

                return;
            }

            $minutes = 1;
            $reason  = $isRateLimitOrTimeout ? 'rate_limit/timeout' : 'other';

            $nextKey = $this->addMinutesToMinuteKey($nowMinute, $minutes);
            $this->repo->rescheduleReminderAt($reminderId, $nextKey);

            Log::warning('RC: rescheduled (+{min} min) after send fail ({reason})', [
                'reminder_id' => $reminderId,
                'min'         => $minutes,
                'next'        => $nextKey,
                'reason'      => $reason,
                'file'        => 'cron.log',
            ]);
        } catch (Throwable $e) {
            Log::error('RC: reschedule failed: {e}', [
                'e'           => $e,
                'reminder_id' => $reminderId,
                'file'        => 'cron.log',
            ]);
        }
    }

    /**
     * Аккуратно прибавляет минуты к minuteKey.
     */
    private function addMinutesToMinuteKey(string $minuteKey, int $minutes): string
    {
        $minutes = max(1, $minutes);

        try {
            $dt = new DateTimeImmutable($minuteKey);
        } catch (Throwable) {
            $dt = new DateTimeImmutable('now');
        }

        $dt2 = $dt->modify('+' . $minutes . ' minutes');

        return DateTimeNormalizer::toMinuteKey($dt2->format('c'));
    }

    /**
     * minuteKey - N minutes (если N<=0, вернет исходный).
     */
    private function subtractMinutesFromMinuteKey(string $minuteKey, int $minutes): string
    {
        $minutes = max(0, $minutes);

        if ($minutes === 0) {
            return $minuteKey;
        }

        try {
            $dt = new DateTimeImmutable($minuteKey);
        } catch (Throwable) {
            $dt = new DateTimeImmutable('now');
        }

        $dt2 = $dt->modify('-' . $minutes . ' minutes');

        return DateTimeNormalizer::toMinuteKey($dt2->format('c'));
    }
}