<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use TgRemainder\Services\ExcelReminderParser;

/**
 *
 * Юнит-тесты приватных разборщиков парсера (время, даты, контакты и т.д.).
 * Здесь проверяем чистую логику без реального чтения Excel.
 */
#[CoversClass(ExcelReminderParser::class)]
final class ExcelReminderParserTest extends TestCase
{
    /**
     * Проверяет декартово произведение дат и времени.
     */
    public function testCombineDatesTimes(): void
    {
        $parser = new ExcelReminderParser();

        $dates = ['2025-08-06', '2025-08-07'];
        $times = ['09:00', '14:30'];

        $expected = [
            '2025-08-06 09:00',
            '2025-08-06 14:30',
            '2025-08-07 09:00',
            '2025-08-07 14:30',
        ];

        $result = $this->invokePrivate($parser, 'combineDatesTimes', [$dates, $times]);

        $this->assertSame($expected, $result);
    }

    /**
     * Даты: валидные форматы принимаются, ISO и невозможные — отклоняются.
     */
    public function testParseDatesAcceptsValidAndRejectsImpossible(): void
    {
        $parser = new ExcelReminderParser();

        // Валидные (ДД.ММ.ГГГГ)
        $res1 = $this->invokePrivate($parser, 'parseDates', ['06.08.2025']);
        $res2 = $this->invokePrivate($parser, 'parseDates', ['06.08.2025, 07.08.2025']);

        $this->assertSame(['2025-08-06'], $res1);
        $this->assertSame(['2025-08-06', '2025-08-07'], $res2);

        // Невалидные
        $this->assertSame([], $this->invokePrivate($parser, 'parseDates', ['2025-08-06'])); // ISO
        $this->assertSame([], $this->invokePrivate($parser, 'parseDates', ['31.02.2025'])); // невозможная
    }

    /**
     * Время: поддерживаем HH, HH:MM, HH.MM, списки; жесткая валидация минут/часов.
     */
    public function testParseTimesAcceptsValidFormsAndRejectsInvalid(): void
    {
        $parser = new ExcelReminderParser();

        // Допустимые
        $this->assertSame(['09:00'], $this->invokePrivate($parser, 'parseTimes', ['9', '12:00']));                 // HH
        $this->assertSame(['09:30'], $this->invokePrivate($parser, 'parseTimes', ['9:30', '12:00']));              // HH:MM
        $this->assertSame(['09:30'], $this->invokePrivate($parser, 'parseTimes', ['9.30', '12:00']));              // HH.MM
        $this->assertSame(['09:50'], $this->invokePrivate($parser, 'parseTimes', ['9.5', '12:00']));               // HH.M -> десятки минут
        $this->assertSame(['21:40'], $this->invokePrivate($parser, 'parseTimes', ['21.4', '12:00']));              // HH.M
        $this->assertSame(['09:05'], $this->invokePrivate($parser, 'parseTimes', ['9:5', '12:00']));               // HH:M -> паддинг
        $this->assertSame(['09:00', '14:30'], $this->invokePrivate($parser, 'parseTimes', ['9, 14:30', '12:00'])); // список
        $this->assertSame(['12:15'], $this->invokePrivate($parser, 'parseTimes', ['', '12:15']));                  // пусто -> default

        // Неверные
        $this->assertSame([], $this->invokePrivate($parser, 'parseTimes', ['09:70', '12:00'])); // минуты > 59
        $this->assertSame([], $this->invokePrivate($parser, 'parseTimes', ['24:00', '12:00'])); // часы > 23
        $this->assertSame([], $this->invokePrivate($parser, 'parseTimes', ['9.75', '12:00']));  // 75 минут
    }

    /**
     * Контакты: chat_id (цифры) или username; прочее — отклоняется.
     */
    public function testParseContactsValidAndInvalid(): void
    {
        $parser = new ExcelReminderParser();

        $ok = "123456789; @user_name, otherUser\n987654321";
        $parsed = $this->invokePrivate($parser, 'parseContacts', [$ok]);
        sort($parsed); // порядок не важен
        $this->assertSame(['123456789', '987654321', 'otherUser', 'user_name'], $parsed);

        // Невалидные случаи
        $this->assertSame([], $this->invokePrivate($parser, 'parseContacts', ['+79991234567']));
        $this->assertSame([], $this->invokePrivate($parser, 'parseContacts', ['имя c пробелом']));
    }

    /**
     * Повторы: null/число/месячный формат.
     */
    public function testParseRepeatDays(): void
    {
        $parser = new ExcelReminderParser();

        $this->assertSame(3, $this->invokePrivate($parser, 'parseRepeatDays', ['3']));
        $this->assertSame(['type' => 'month', 'interval' => 1], $this->invokePrivate($parser, 'parseRepeatDays', ['1m']));
        $this->assertSame(['type' => 'month', 'interval' => 2], $this->invokePrivate($parser, 'parseRepeatDays', ['2 м']));
        $this->assertNull($this->invokePrivate($parser, 'parseRepeatDays', ['']));
        $this->assertFalse($this->invokePrivate($parser, 'parseRepeatDays', ['weekly']));
    }

    /**
     * Универсальный помощник: вызывает приватный метод объекта.
     *
     * @param object              $object Экземпляр с приватным методом
     * @param non-empty-string    $method Имя метода
     * @param array<int, mixed>   $args   Аргументы
     *
     * @return mixed Результат вызова приватного метода
     */
    private function invokePrivate(object $object, string $method, array $args): mixed
    {
        $ref = new ReflectionClass($object);
        $m   = $ref->getMethod($method);
        $m->setAccessible(true);

        /** @var mixed $result */
        $result = $m->invokeArgs($object, $args);
        return $result;
    }
}