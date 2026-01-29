<?php
declare(strict_types=1);

namespace TgRemainder\Logger;

use Psr\Log\InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

/**
 * Простой файловый логгер: пишет в разные файлы в зависимости от уровня.
 */
final class FileLogger implements LoggerInterface
{
    use InterpolatesMessage;

    private readonly string $dir;

    /** @var array<string,string> */
    private readonly array $filesByLevel;

    private readonly string $format;

    /**
     * @param array<string,string> $filesByLevel
     */
    public function __construct(
        string $dir,
        array $filesByLevel = [
            LogLevel::EMERGENCY => 'app.log',
            LogLevel::ALERT     => 'app.log',
            LogLevel::CRITICAL  => 'app.log',
            LogLevel::ERROR     => 'error.log',
            LogLevel::WARNING   => 'app.log',
            LogLevel::NOTICE    => 'app.log',
            LogLevel::INFO      => 'app.log',
            LogLevel::DEBUG     => 'debug.log',
        ],
        string $format = "[%datetime%] %level% %message%\n",
    ) {
        $this->dir          = $dir;
        $this->filesByLevel = $filesByLevel;
        $this->format       = $format;
    }

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
        if (!isset($this->filesByLevel[$level])) {
            throw new InvalidArgumentException("Unknown level: {$level}");
        }

        $msg = $this->interpolate((string) $message, $context);

        $line = strtr($this->format, [
            '%datetime%' => date('Y-m-d H:i:s'),
            '%level%'    => strtoupper((string) $level),
            '%message%'  => $msg,
        ]);

        $dir = rtrim($this->dir, DIRECTORY_SEPARATOR);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $path = $dir . DIRECTORY_SEPARATOR . $this->filesByLevel[$level];

        @file_put_contents($path, $line, FILE_APPEND | LOCK_EX);
    }
}