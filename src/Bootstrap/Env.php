<?php
declare(strict_types=1);

namespace TgRemainder\Bootstrap;

/**
 * Минимальный .env-лоадер без зависимостей.
 * Читает пары KEY=VALUE и публикует их в putenv(), $_ENV и $_SERVER.
 */
final class Env
{
    /**
     * @param string|null $path     Путь к .env (по умолчанию ROOT/.env)
     * @param bool        $override Перезаписывать уже заданные переменные
     */
    public static function load(?string $path = null, bool $override = false): void
    {
        $root = dirname(__DIR__, 2);
        $file = $path ?? ($root . '/.env');

        if (!is_file($file) || !is_readable($file)) {
            return;
        }

        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return;
        }

        foreach ($lines as $raw) {
            $line = trim($raw);

            // пропускаем пустые и комментарии
            if ($line === '' || $line[0] === '#' || $line[0] === ';') {
                continue;
            }
            if (!str_contains($line, '=')) {
                continue;
            }

            // парсим KEY=VALUE, допускаем пустое значение
            [$key, $value] = array_pad(explode('=', $line, 2), 2, '');
            $key   = trim($key);
            $value = trim($value);

            if ($key === '' || !preg_match('/^[A-Za-z0-9_]+$/', $key)) {
                continue;
            }

            // снятие внешних кавычек; в "..." обрабатываем \n \r \t \" \\
            if (($value[0] ?? '') === '"' && str_ends_with($value, '"')) {
                $value = substr($value, 1, -1);
                $value = str_replace(['\\n','\\r','\\t','\\"','\\\\'], ["\n","\r","\t",'"','\\'], $value);
            } elseif (($value[0] ?? '') === "'" && str_ends_with($value, "'")) {
                $value = substr($value, 1, -1);
            }

            // подстановки ${VAR} из уже известных значений
            $value = preg_replace_callback(
                '/\$\{([A-Za-z0-9_]+)\}/',
                static function (array $m): string {
                    $k = $m[1];
                    $v = getenv($k);
                    if ($v === false && isset($_ENV[$k])) {
                        $v = $_ENV[$k];
                    }
                    return ($v === false || $v === null) ? '' : (string) $v;
                },
                $value
            ) ?? $value;

            // если уже задано и не разрешён override — пропускаем
            $already = getenv($key) !== false || isset($_ENV[$key]) || isset($_SERVER[$key]);
            if ($already && !$override) {
                continue;
            }

            // публикуем во все каналы: процесс, $_ENV, $_SERVER
            putenv($key . '=' . $value);
            $_ENV[$key]    = $value;
            $_SERVER[$key] = $value;
        }
    }
}