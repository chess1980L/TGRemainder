<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use TgRemainder\Services\ExcelReminderParser;

final class ExcelReminderParserIntegrationTest extends TestCase
{
    private string $fixturesDir;

    protected function setUp(): void
    {
        if (!class_exists(\Vtiful\Kernel\Excel::class)) {
            $this->markTestSkipped('xlswriter extension not installed, skipping Excel integration tests.');
        }

        $this->fixturesDir = __DIR__ . '/fixtures';
        if (!is_dir($this->fixturesDir)) {
            mkdir($this->fixturesDir, 0777, true);
        }

        // Важно: парсер читает $_ENV, putenv() сам по себе может не заполнить $_ENV.
        $_ENV['APP_TIMEZONE']  = 'Europe/Moscow';
        $_ENV['DEFAULT_TIME']  = '12:00';

        // На всякий случай дублируем и в окружение процесса.
        putenv('APP_TIMEZONE=Europe/Moscow');
        putenv('DEFAULT_TIME=12:00');

        date_default_timezone_set($_ENV['APP_TIMEZONE']);
    }

    public function testParseRealExcel_ValidOnly(): void
    {
        $xlsxPath = $this->fixturesDir . '/sample_valid.xlsx';
        @unlink($xlsxPath);

        $excel = new \Vtiful\Kernel\Excel(['path' => $this->fixturesDir]);
        $file  = $excel->fileName(basename($xlsxPath));
        $file->header(['date', 'event', 'contacts', 'time', 'repeat_days']);

        $file->data([['06.08.2025', 'Платеж по кредиту', '123456789', '14:30', '1м']]);
        $file->data([['06.08.2025, 07.08.2025', 'Полить цветы', '987654321, @user_name', '9, 14.30', '2']]);

        $file->output();

        $parser = new ExcelReminderParser();
        $result = $parser->parse($xlsxPath, basename($xlsxPath));

        $this->assertArrayHasKey('reminders', $result);
        $this->assertArrayHasKey('errors', $result);
        $this->assertSame([], $result['errors'], 'Не должно быть ошибок на валидном файле');

        $reminders = $result['reminders'];
        $this->assertCount(2, $reminders, 'Должно быть 2 валидных напоминания');

        $r1 = $reminders[0];
        $this->assertSame('Платеж по кредиту', $r1['text']);
        $this->assertSame(['123456789'], $r1['targets']);
        $this->assertSame(['2025-08-06 14:30'], $r1['dates']);
        $this->assertSame(['type' => 'month', 'interval' => 1], $r1['repeat_days']);

        $r2 = $reminders[1];
        $this->assertSame('Полить цветы', $r2['text']);
        $this->assertEqualsCanonicalizing(['987654321', 'user_name'], $r2['targets']);

        $expectedDates = [
            '2025-08-06 09:00',
            '2025-08-06 14:30',
            '2025-08-07 09:00',
            '2025-08-07 14:30',
        ];
        $this->assertEqualsCanonicalizing($expectedDates, $r2['dates']);
        $this->assertSame(2, $r2['repeat_days']);
    }

    public function testParseRealExcel_InvalidStopsImmediately(): void
    {
        $xlsxPath = $this->fixturesDir . '/sample_invalid.xlsx';
        @unlink($xlsxPath);

        $excel = new \Vtiful\Kernel\Excel(['path' => $this->fixturesDir]);
        $file  = $excel->fileName(basename($xlsxPath));
        $file->header(['date', 'event', 'contacts', 'time', 'repeat_days']);

        $file->data([['08.08.2025', 'Невалидное время', '123456789', '09:70', '']]);
        $file->data([['06.08.2025', 'Платеж', '123456789', '14:30', '']]);

        $file->output();

        $parser = new ExcelReminderParser();
        $result = $parser->parse($xlsxPath, basename($xlsxPath));

        $this->assertArrayHasKey('reminders', $result);
        $this->assertArrayHasKey('errors', $result);

        $this->assertSame([], $result['reminders'], 'При первой ошибке reminders очищаются (fail-fast).');
        $this->assertNotEmpty($result['errors'], 'Ожидаем ошибку из-за неверного времени 09:70');
    }
}