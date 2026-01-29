<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use TgRemainder\Repositories\EventRepositoryInterface;
use TgRemainder\Repositories\SQLiteEventRepository;
use TgRemainder\Telegram\BotClientInterface;
use TgRemainder\Telegram\ReminderChecker;

/**
 *
 * Реальный тест ReminderChecker на SQLite (in-memory).
 * Проверяет:
 *  - перенос ежемесячных событий,
 *  - перенос событий с повтором через N дней,
 *  - соответствие минутной точности.
 */

#[CoversClass(ReminderChecker::class)]
final class ReminderCheckerRealTest extends TestCase
{
    private PDO $pdo;
    private EventRepositoryInterface $repo;
    private DummyBot $bot;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $this->pdo->exec("
            CREATE TABLE events (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                text TEXT NOT NULL,
                repeat_days TEXT NULL
            );
        ");
        $this->pdo->exec("
            CREATE TABLE event_times (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                event_id INTEGER NOT NULL,
                remind_at TEXT NOT NULL
            );
        ");
        $this->pdo->exec("
            CREATE TABLE contacts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                telegram_id TEXT,
                username TEXT
            );
        ");
        $this->pdo->exec("
            CREATE TABLE event_contacts (
                event_id INTEGER NOT NULL,
                contact_id INTEGER NOT NULL
            );
        ");

        $this->repo = new SQLiteEventRepository($this->pdo);
        $this->bot  = new DummyBot();
    }

    public function testSendMonthlyReminder(): void
    {
        $reminders = [[
            'dates'       => ['2025-08-06 14:30'],
            'text'        => 'Платеж по кредиту',
            'targets'     => ['123456789'],
            'repeat_days' => ['type' => 'month', 'interval' => 1],
            'source_file' => 'file.xlsx',
        ]];

        $this->repo->saveEvents($reminders);

        $checker = new ReminderChecker($this->repo, $this->bot);
        $sent    = $checker->sendDueReminders('2025-08-06 14:30');

        $this->assertSame(1, $sent);
        $this->assertCount(1, $this->bot->sent);

        $times = $this->fetchAllTimes();
        $this->assertContains('2025-09-06 14:30', $times);
    }

    public function testSendDailyReminder(): void
    {
        $reminders = [[
            'dates'       => ['2025-08-06 09:00'],
            'text'        => 'Полить цветы',
            'targets'     => ['987654321'],
            'repeat_days' => 2,
            'source_file' => 'file.xlsx',
        ]];

        $this->repo->saveEvents($reminders);

        $checker = new ReminderChecker($this->repo, $this->bot);
        $sent    = $checker->sendDueReminders('2025-08-06 09:00');

        $this->assertSame(1, $sent);

        $times = $this->fetchAllTimes();
        $this->assertContains('2025-08-08 09:00', $times);
    }

    /**
     * @return array<int, string>
     */
    private function fetchAllTimes(): array
    {
        /** @var array<int,string> $rows */
        $rows = $this->pdo
            ->query("SELECT remind_at FROM event_times")
            ->fetchAll(PDO::FETCH_COLUMN);

        return $rows;
    }
}

/**
 * Простой тестовый бот, собирающий отправленные сообщения.
 *
 * @internal
 */
final class DummyBot implements BotClientInterface
{
    /** @var array<int, array{chatId:int, text:string}> */
    public array $sent = [];

    /** @var array{http_code:int, error_code?:int, description?:string, transport?:string}|null */
    private ?array $lastError = null;

    /**
     * @param array<string,mixed> $params
     */
    public function sendMessage(int $chatId, string $text, array $params = []): bool
    {
        $this->sent[] = ['chatId' => $chatId, 'text' => $text];
        $this->lastError = null;
        return true;
    }

    public function getLastError(): ?array
    {
        return $this->lastError;
    }
}