<?php

declare(strict_types=1);

namespace TgRemainder\Services;

use Throwable;
use TgRemainder\Logger\LogFacade as Log;
use Vtiful\Kernel\Excel;

/**
 * Парсит .xlsx с напоминаниями в унифицированную структуру.
 *
 * Ожидаемые колонки (регистр/порядок не важен):
 *  - date        (обязательно)  — 'ДД.ММ.ГГГГ' или несколько через запятую
 *  - event       (обязательно)  — текст напоминания
 *  - contacts    (обязательно)  — chat_id / -100... / username(ы)
 *  - time        (опционально)  — 'HH:MM', 'HH.MM', 'HH', также 'HH.M' и 'H:M'
 *  - repeat_days (опционально)  — целые дни или 'Nm'/'Nм' для месячного повтора
 *
 * Поведение:
 *  - fail-fast: при первой ошибке возвращается одна запись об ошибке;
 *  - даты нормализуются в 'YYYY-MM-DD', время — в 'HH:MM';
 *  - итог: ['reminders' => [...], 'errors' => [...] ].
 */
final class ExcelReminderParser
{
    /**
     * @return array{
     *   reminders:array<int,array<string,mixed>>,
     *   errors:array<int,string>
     * }
     */
    public function parse(string $filePath, string $originalFileName): array
    {
        $defaultTime = (string) ($_ENV['DEFAULT_TIME'] ?? '12:00');
        $reminders   = [];

        try {
            $excel = new Excel(['path' => dirname($filePath)]);
            $rows  = $excel
                ->openFile(basename($filePath))
                ->openSheet()
                ->getSheetData();
        } catch (Throwable $e) {
            return $this->error("❌ Ошибка чтения файла {$originalFileName}: " . $e->getMessage());
        }

        if (empty($rows)) {
            return $this->error('❌ Пустой файл');
        }

        $headers = $this->normalizeHeaders(array_shift($rows));
        foreach (['date', 'event', 'contacts'] as $field) {
            if (!isset($headers[$field])) {
                return $this->error("❌ Отсутствует обязательный столбец '{$field}'");
            }
        }

        $repeatDaysCol = $headers['repeat_days'] ?? null;

        foreach ($rows as $i => $row) {
            $rowNum = $i + 2;
            if ($this->isRowEmpty($row)) {
                break;
            }

            $event       = trim((string) ($row[$headers['event']] ?? ''));
            $dateRaw     = trim((string) ($row[$headers['date']] ?? ''));
            $contactsRaw = trim((string) ($row[$headers['contacts']] ?? ''));
            $timeRaw     = $headers['time'] ?? null
                ? trim((string) ($row[$headers['time']] ?? ''))
                : '';
            $repeatRaw   = $repeatDaysCol
                ? trim((string) ($row[$repeatDaysCol] ?? ''))
                : '';

            if ($event === '' || $dateRaw === '' || $contactsRaw === '') {
                return $this->error("❌ Строка {$rowNum}: отсутствует обязательное значение");
            }

            $repeatDays = $this->parseRepeatDays($repeatRaw);
            if ($repeatDays === false) {
                return $this->error("❌ Строка {$rowNum}: repeat_days должно быть числом или вида '1м'");
            }

            $dates = $this->parseDates($dateRaw);
            if ($dates === []) {
                return $this->error("❌ Строка {$rowNum}: неверный формат даты");
            }

            $times = $this->parseTimes($timeRaw, $defaultTime);
            if ($times === []) {
                return $this->error("❌ Строка {$rowNum}: неверный формат времени");
            }

            $contacts = $this->parseContacts($contactsRaw);
            if ($contacts === []) {
                return $this->error("❌ Строка {$rowNum}: не удалось распознать контакты");
            }

            $reminders[] = [
                'dates'       => $this->combineDatesTimes($dates, $times),
                'text'        => $event,
                'targets'     => $contacts,
                'repeat_days' => $repeatDays,
                'source_file' => $originalFileName,
            ];
        }

        return empty($reminders)
            ? $this->error('⚠️ Не удалось сформировать ни одного напоминания')
            : ['reminders' => $reminders, 'errors' => []];
    }

    /**
     * @return array{reminders:array<never>,errors:array<int,string>}
     */
    private function error(string $msg): array
    {
        $this->logPlain($msg);
        return ['reminders' => [], 'errors' => [$msg]];
    }

    private function logPlain(string $msg): void
    {
        $msg = trim($msg);
        if ($msg === '') {
            return;
        }

        // Убираем только управляющие символы (кроме \t \n \r),
        // сохраняя UTF-8 (кириллицу и т.п.)
        $plain = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $msg);

        Log::error($plain === null ? $msg : $plain);
    }

    /**
     * @param array<int,mixed> $row
     */
    private function isRowEmpty(array $row): bool
    {
        return !array_filter(array_map(static fn ($v) => trim((string) $v), $row));
    }

    /**
     * @param array<int,mixed> $raw
     * @return array<string,int>
     */
    private function normalizeHeaders(array $raw): array
    {
        $headers = [];
        foreach ($raw as $index => $value) {
            $key = strtolower(trim((string) $value));
            if ($key !== '') {
                $headers[$key] = (int) $index;
            }
        }

        return $headers;
    }

    /**
     * @return array<int,string>
     */
    private function parseDates(string $raw): array
    {
        $parts  = array_map('trim', explode(',', $raw));
        $result = [];

        foreach ($parts as $part) {
            if (!preg_match('/^(\d{2})\.(\d{2})\.(\d{4})$/', $part, $m)) {
                return [];
            }

            $d  = (int) $m[1];
            $mo = (int) $m[2];
            $y  = (int) $m[3];

            if (!checkdate($mo, $d, $y)) {
                return [];
            }

            $result[] = sprintf('%04d-%02d-%02d', $y, $mo, $d);
        }

        return $result;
    }

    /**
     * @return array<int,string>
     */
    private function parseTimes(string $raw, string $default): array
    {
        if ($raw === '') {
            $raw = $default;
        }

        $parts  = array_map('trim', explode(',', $raw));
        $result = [];

        foreach ($parts as $time) {
            if ($time === '') {
                return [];
            }

            if (preg_match('/^(\d{1,2})([:.])(\d{2})$/', $time, $m)) {
                $h  = (int) $m[1];
                $mi = (int) $m[3];

                if ($h < 0 || $h > 23 || $mi < 0 || $mi > 59) {
                    return [];
                }

                $result[] = sprintf('%02d:%02d', $h, $mi);
                continue;
            }

            if (preg_match('/^(\d{1,2})([:.])(\d)$/', $time, $m)) {
                $h   = (int) $m[1];
                $sep = $m[2];
                $d   = (int) $m[3];

                if ($h < 0 || $h > 23) {
                    return [];
                }

                $mi = ($sep === ':') ? $d : $d * 10;
                if ($mi < 0 || $mi > 59) {
                    return [];
                }

                $result[] = sprintf('%02d:%02d', $h, $mi);
                continue;
            }

            if (is_numeric($time)) {
                $s = (string) $time;

                if (preg_match('/^(\d{1,2})\.(\d{1,2})$/', $s, $m)) {
                    $h  = (int) $m[1];
                    $mm = $m[2];
                    $mi = (int) (strlen($mm) === 1 ? ((int) $mm) * 10 : (int) $mm);

                    if ($h < 0 || $h > 23 || $mi < 0 || $mi > 59) {
                        return [];
                    }

                    $result[] = sprintf('%02d:%02d', $h, $mi);
                    continue;
                }

                if (preg_match('/^\d{1,2}$/', $s)) {
                    $h = (int) $s;

                    if ($h < 0 || $h > 23) {
                        return [];
                    }

                    $result[] = sprintf('%02d:00', $h);
                    continue;
                }

                return [];
            }

            return [];
        }

        return $result;
    }

    /**
     * contacts: chat_id (включая -100...), или username (с @ или без).
     *
     * @return array<int,string>
     */
    private function parseContacts(string $raw): array
    {
        $parts  = array_map('trim', preg_split('/[\n\r;,]+/', $raw) ?: []);
        $result = [];

        foreach ($parts as $contact) {
            if ($contact === '') {
                return [];
            }

            // chat_id: допускаем отрицательные и длинные (группы -100...)
            if (preg_match('/^-?\d{5,20}$/', $contact)) {
                $result[] = $contact;
                continue;
            }

            // username: @name или name
            if (preg_match('/^@?([a-zA-Z0-9_]{5,32})$/', $contact, $m)) {
                $result[] = $m[1];
                continue;
            }

            return [];
        }

        return array_values(array_unique($result));
    }

    /**
     * @return int|array{type:'month',interval:int}|false|null
     */
    private function parseRepeatDays(string $raw): int|array|false|null
    {
        if ($raw === '') {
            return null;
        }

        $raw = trim(mb_strtolower($raw));

        if (preg_match('/^(\d+)\s*[mм]$/u', $raw, $m)) {
            $n = (int) ($m[1] ?? 1);
            return [
                'type'     => 'month',
                'interval' => max(1, $n),
            ];
        }

        if (ctype_digit($raw)) {
            $n = (int) $raw;
            return $n >= 1 ? $n : false; // 0 и меньше — ошибка
        }

        return false;
    }

    /**
     * @param array<int,string> $dates
     * @param array<int,string> $times
     * @return array<int,string>
     */
    private function combineDatesTimes(array $dates, array $times): array
    {
        $result = [];

        foreach ($dates as $d) {
            foreach ($times as $t) {
                $result[] = "{$d} {$t}";
            }
        }

        return $result;
    }
}