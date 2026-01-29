<?php
declare(strict_types=1);

namespace TgRemainder\Logger;

use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

/**
 * Декоратор над любым PSR-3 логгером.
 *
 * Если в контексте есть ['file' => 'xxx.log'], пишет строку в logs/xxx.log.
 * Иначе делегирует во внутренний логгер.
 */
final class FileByContextDecorator implements LoggerInterface
{
    use InterpolatesMessage;

    private readonly LoggerInterface $inner;
    private readonly string $dir;
    private readonly string $format;

    /**
     * @param LoggerInterface $inner  Базовый логгер для делегации
     * @param string          $dir    Директория для логов
     * @param string          $format Формат строки лога
     */
    public function __construct(
        LoggerInterface $inner,
        string $dir,
        string $format = "[%datetime%] %level% %message%\n",
    ) {
        $this->inner  = $inner;
        $this->dir    = $dir;
        $this->format = $format;
    }

    // PSR-3 уровни
    public function emergency($message, array $context = []): void
    {
        $this->log(LogLevel::EMERGENCY, $message, $context);
    }

    public function alert($message, array $context = []): void
    {
        $this->log(LogLevel::ALERT, $message, $context);
    }

    public function critical($message, array $context = []): void
    {
        $this->log(LogLevel::CRITICAL, $message, $context);
    }

    public function error($message, array $context = []): void
    {
        $this->log(LogLevel::ERROR, $message, $context);
    }

    public function warning($message, array $context = []): void
    {
        $this->log(LogLevel::WARNING, $message, $context);
    }

    public function notice($message, array $context = []): void
    {
        $this->log(LogLevel::NOTICE, $message, $context);
    }

    public function info($message, array $context = []): void
    {
        $this->log(LogLevel::INFO, $message, $context);
    }

    public function debug($message, array $context = []): void
    {
        $this->log(LogLevel::DEBUG, $message, $context);
    }

    /**
     * @param mixed               $level
     * @param string|\Stringable  $message
     * @param array<string,mixed> $context
     */
    public function log($level, $message, array $context = []): void
    {
        // Явный файл — пишем напрямую и выходим
        if (isset($context['file']) && is_string($context['file']) && $context['file'] !== '') {
            $filename = $this->sanitizeFilename($context['file']);
            unset($context['file']);

            $line = strtr($this->format, [
                '%datetime%' => date('Y-m-d H:i:s'),
                '%level%'    => strtoupper((string) $level),
                '%message%'  => $this->interpolate((string) $message, $context),
            ]);

            $dir = rtrim($this->dir, DIRECTORY_SEPARATOR);
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }

            @file_put_contents(
                $dir . DIRECTORY_SEPARATOR . $filename,
                $line,
                FILE_APPEND | LOCK_EX
            );
            return;
        }

        // Иначе — делегируем во внутренний логгер
        $this->inner->log($level, $message, $context);
    }

    private function sanitizeFilename(string $f): string
    {
        $f = basename($f); // защита от ../
        $f = preg_replace('/[^A-Za-z0-9._-]/', '_', $f) ?? $f;

        if ($f === '') {
            $f = 'app.log';
        }
        if (!str_ends_with($f, '.log')) {
            $f .= '.log';
        }

        return $f;
    }
}