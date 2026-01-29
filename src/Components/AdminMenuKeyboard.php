<?php

declare(strict_types=1);

namespace TgRemainder\Components;

/**
 * Разметка клавиатуры для админ-меню.
 */
final class AdminMenuKeyboard
{
    public const BTN_HELP = '📘 Справка';
    public const BTN_EXPORT = '📤 Экспорт в Excel';
    public const BTN_TEMPLATE = '📄 Получить шаблон';
    public const BTN_CLEANUP_OLD = '🧹 Очистить старые данные';

    /**
     * ReplyKeyboardMarkup как массив, готовый к json_encode().
     *
     * @return array{
     *   keyboard: array<array<array{text:string}>>,
     *   resize_keyboard: bool,
     *   one_time_keyboard: bool
     * }
     */
    public static function get(): array
    {
        return [
            'keyboard' => [
                [['text' => self::BTN_HELP], ['text' => self::BTN_EXPORT]],
                [['text' => self::BTN_TEMPLATE], ['text' => self::BTN_CLEANUP_OLD]],
            ],
            'resize_keyboard' => true,
            'one_time_keyboard' => false,
        ];
    }
}