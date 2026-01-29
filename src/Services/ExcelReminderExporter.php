<?php

declare(strict_types=1);

namespace TgRemainder\Services;

use DateTimeImmutable;
use Throwable;
use Vtiful\Kernel\Excel;
use TgRemainder\Logger\LogFacade as Log;
use TgRemainder\Repositories\EventRepositoryInterface;

final class ExcelReminderExporter
{
    private EventRepositoryInterface $repo;

    public function __construct(EventRepositoryInterface $repo)
    {
        $this->repo = $repo;
    }

    /**
     * @return string Путь к файлу или текст ошибки (если файл не создан).
     */
    public function export(): string
    {
        try {
            $events = $this->repo->getAllForExport();
        } catch (Throwable $e) {
            Log::error('Excel export: failed to read events: {e}', ['e' => $e]);
            return '❌ Не удалось получить данные для экспорта.';
        }

        if ($events === []) {
            return '⚠️ Нет данных для экспорта.';
        }

        $exportDir = dirname(__DIR__, 2) . '/storage/exports';
        if (!is_dir($exportDir) && !@mkdir($exportDir, 0775, true) && !is_dir($exportDir)) {
            Log::error('Excel export: cannot create export directory', ['dir' => $exportDir]);
            return '❌ Не удалось подготовить директорию для экспорта.';
        }

        $filename = 'export_' . date('Ymd_His') . '.xlsx';

        $headers = ['date', 'time', 'event', 'contacts', 'repeat_days'];
        $rows    = [];

        foreach ($events as $event) {
            $dates = [];
            $times = [];

            foreach (($event['remind_at'] ?? []) as $datetime) {
                $datetime = trim((string) $datetime);
                if ($datetime === '') {
                    continue;
                }

                try {
                    $dt = new DateTimeImmutable($datetime);
                } catch (Throwable) {
                    continue;
                }

                $dates[] = $dt->format('d.m.Y');
                $times[] = $dt->format('H.i');
            }

            $dates = array_values(array_unique($dates));
            $times = array_values(array_unique($times));
            sort($times);

            $contacts = (array) ($event['contacts'] ?? []);
            $contacts = array_values(array_unique(array_map('strval', $contacts)));

            $repeat = $event['repeat_days'] ?? '';
            $repeat = is_string($repeat) || is_int($repeat) ? (string) $repeat : '';

            // Важно: числовой массив строго в порядке $headers
            $rows[] = [
                implode(', ', $dates),
                implode(', ', $times),
                (string) ($event['text'] ?? ''),
                implode(', ', $contacts),
                $repeat,
            ];
        }

        try {
            $excel = new Excel(['path' => $exportDir]);
            $file  = $excel->fileName($filename, 'Sheet1');

            $file->header($headers);
            $file->data($rows);

            $filepath = $file->output();
            return $filepath ?: '❌ Не удалось создать Excel-файл.';
        } catch (Throwable $e) {
            Log::error('Excel export: xlswriter failed: {e}', ['e' => $e]);
            return '❌ Не удалось создать Excel-файл.';
        }
    }
}