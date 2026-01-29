<?php
declare(strict_types=1);

namespace TgRemainder\Bootstrap;

/**
 * TinySos — минимальный аварийный логгер до Bootstrap::init().
 *
 * Возможности:
 * - Отлавливает фатальные ошибки в shutdown (E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR).
 * - Отлавливает необработанные исключения и обычные ошибки (с учётом текущего error_reporting() и оператора @).
 * - Поддерживает сэмплирование (запись части событий по вероятности) и ротацию по размеру файла.
 *
 * После успешной инициализации приложения TinySos необходимо отключить: TinySos::uninstall().
 */
final class TinySos
{
    /** Путь к лог-файлу (null => временная директория). */
    private static ?string $file = null;

    /** Признак активного режима. */
    private static bool $active = false;

    /** Вероятность записи строки лога (0.0..1.0). */
    private static float $sample = 1.0;

    /** Максимальный размер лог-файла в байтах перед ротацией. */
    private static int $maxBytes = 5 * 1024 * 1024; // 5 MB

    /**
     * Установка обработчиков и активация аварийного логирования.
     * Создаёт директорию под лог при необходимости.
     */
    public static function install(string $file): void
    {
        self::$file   = $file;
        self::$active = true;

        $dir = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        // Фатальные ошибки (ловятся только в shutdown)
        register_shutdown_function(
            static function (): void {
                if (!self::$active) {
                    return;
                }
                $e = error_get_last();
                $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];

                if ($e && in_array(($e['type'] ?? 0), $fatalTypes, true)) {
                    self::write('FATAL ' . json_encode($e, JSON_UNESCAPED_UNICODE));
                }
            }
        );

        // Необработанные исключения
        set_exception_handler(
            static function (\Throwable $e): void {
                if (!self::$active) {
                    return;
                }
                self::write('EXC ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
            }
        );

        // Обычные ошибки PHP
        set_error_handler(
            static function (int $no, string $str, string $file, int $line): bool {
                if (!self::$active) {
                    return false;
                }
                // Учитываем текущие настройки error_reporting() и оператор подавления @
                if (!(error_reporting() & $no)) {
                    return false;
                }

                self::write("ERR #{$no} {$str} @ {$file}:{$line}");

                // Обработано TinySos: дальше не передаём (на ранней фазе достаточно)
                return true;
            }
        );
    }

    /**
     * Отключение аварийного логирования и возврат стандартных обработчиков.
     */
    public static function uninstall(): void
    {
        self::$active = false;
        restore_error_handler();
        restore_exception_handler();
    }

    /**
     * Установить вероятность записи строки (0.0..1.0).
     * Полезно для снижения объёма лога под нагрузкой.
     */
    public static function setSample(float $p): void
    {
        self::$sample = max(0.0, min(1.0, $p));
    }

    /**
     * Установить порог размера лог-файла в байтах, после которого выполняется ротация.
     */
    public static function setMaxBytes(int $bytes): void
    {
        self::$maxBytes = max(1024, $bytes);
    }

    /**
     * Записать строку в лог с учётом сэмплирования и ротации.
     */
    public static function write(string $msg): void
    {
        // Сэмплирование
        if (self::$sample < 1.0) {
            try {
                $rnd = random_int(1, 1_000_000) / 1_000_000;
                if ($rnd > self::$sample) {
                    return;
                }
            } catch (\Throwable) {
                // Игнорируем сбой random_int
            }
        }

        $file = self::$file ?? (sys_get_temp_dir() . '/tiny_sos.log');

        // Ротация при превышении порога размера
        self::rotateIfNeeded($file);

        $line = date('c') . ' ' . $msg . PHP_EOL;
        if (@file_put_contents($file, $line, FILE_APPEND) === false) {
            // Фолбэк в системный лог
            @error_log('[TinySos] ' . $msg);
        }
    }

    /**
     * Проверить размер файла и выполнить ротацию при необходимости.
     * Текущий файл переименовывается с отпечатком времени.
     */
    private static function rotateIfNeeded(string $file): void
    {
        try {
            if (is_file($file) && filesize($file) > self::$maxBytes) {
                @rename($file, $file . '.' . date('Ymd_His') . '.1');
            }
        } catch (\Throwable) {
            // Игнорируем ошибки ротации
        }
    }
}