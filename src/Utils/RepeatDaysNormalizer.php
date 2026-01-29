<?php

declare(strict_types=1);

namespace TgRemainder\Utils;

/**
 * Единая нормализация repeat_days для хранения:
 *  - null/'' => null
 *  - int / "7" => 7 (только >= 1)
 *  - ['type'=>'month','interval'=>N] => "Nm" (N >= 1)
 *  - "1m"/"1м" => "1m"
 *  - прочее => null (повторы выключаем безопасно)
 */
final class RepeatDaysNormalizer
{
    public static function toStorage(mixed $val): int|string|null
    {
        if ($val === null) {
            return null;
        }

        if (is_int($val)) {
            return $val >= 1 ? $val : null;
        }

        if (is_string($val)) {
            $s = trim($val);
            if ($s === '') {
                return null;
            }

            if (ctype_digit($s)) {
                $n = (int) $s;
                return $n >= 1 ? $n : null;
            }

            if (preg_match('/^(\d+)\s*[mм]$/iu', $s, $m)) {
                $n = (int) ($m[1] ?? 1);
                $n = max(1, $n);
                return $n . 'm';
            }

            return null;
        }

        if (is_array($val) && (($val['type'] ?? null) === 'month')) {
            $n = (int) ($val['interval'] ?? 1);
            $n = max(1, $n);
            return $n . 'm';
        }

        return null;
    }
}