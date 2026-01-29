<?php
declare(strict_types=1);

namespace TgRemainder\Logger;

use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

/**
 * Декоратор, отфильтровывающий сообщения ниже заданного минимального уровня.
 *
 * Уровни (возрастание):
 * debug < info < notice < warning < error < critical < alert < emergency
 *
 * Пример:
 *   $logger = new LevelFilterDecorator($logger, 'info'); // debug будет отфильтрован
 */
final class LevelFilterDecorator implements LoggerInterface
{
    private LoggerInterface $inner;
    private int $min;

    /** @var array<string,int> */
    private const MAP = [
        LogLevel::DEBUG     => 100,
        LogLevel::INFO      => 200,
        LogLevel::NOTICE    => 250,
        LogLevel::WARNING   => 300,
        LogLevel::ERROR     => 400,
        LogLevel::CRITICAL  => 500,
        LogLevel::ALERT     => 550,
        LogLevel::EMERGENCY => 600,
    ];

    public function __construct(LoggerInterface $inner, string $minLevel)
    {
        $this->inner = $inner;
        $this->min   = self::MAP[$this->normalize($minLevel)] ?? self::MAP[LogLevel::INFO];
    }

    /**
     * @param mixed               $level
     * @param string|\Stringable  $message
     * @param array<string,mixed> $context
     */
    public function log($level, $message, array $context = []): void
    {
        $lvl = self::MAP[$this->normalize((string) $level)] ?? self::MAP[LogLevel::INFO];
        if ($lvl < $this->min) {
            return;
        }

        $this->inner->log($level, $message, $context);
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

    private function normalize(string $v): string
    {
        $v = strtolower(trim($v));

        return match ($v) {
            'dbg'  => LogLevel::DEBUG,
            'warn' => LogLevel::WARNING,
            default => $v,
        };
    }
}