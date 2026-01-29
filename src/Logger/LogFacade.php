<?php
declare(strict_types=1);

namespace TgRemainder\Logger;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Статический фасад к PSR-3 логгеру.
 *
 * Держит один экземпляр LoggerInterface, заданный на старте приложения.
 * Можно безопасно вызывать из любого места: LogFacade::info('msg');
 */
final class LogFacade
{
    private static ?LoggerInterface $logger = null;

    /**
     * Зарегистрировать реальный логгер (делаем один раз в bootstrap).
     */
    public static function set(LoggerInterface $logger): void
    {
        self::$logger = $logger;
    }

    /**
     * Текущий логгер (по умолчанию NullLogger, чтобы вызовы не падали до bootstrap).
     */
    private static function get(): LoggerInterface
    {
        return self::$logger ??= new NullLogger();
    }

    // PSR-3 уровни (явные методы для автодополнения)
    public static function emergency(string $message, array $context = []): void
    {
        self::get()->emergency($message, $context);
    }

    public static function alert(string $message, array $context = []): void
    {
        self::get()->alert($message, $context);
    }

    public static function critical(string $message, array $context = []): void
    {
        self::get()->critical($message, $context);
    }

    public static function error(string $message, array $context = []): void
    {
        self::get()->error($message, $context);
    }

    public static function warning(string $message, array $context = []): void
    {
        self::get()->warning($message, $context);
    }

    public static function notice(string $message, array $context = []): void
    {
        self::get()->notice($message, $context);
    }

    public static function info(string $message, array $context = []): void
    {
        self::get()->info($message, $context);
    }

    public static function debug(string $message, array $context = []): void
    {
        self::get()->debug($message, $context);
    }

    /**
     * Универсальный метод с явным указанием уровня.
     */
    public static function log(string $level, string $message, array $context = []): void
    {
        self::get()->log($level, $message, $context);
    }

    /**
     * Удобный «префикс/контекст-скоуп» для группировки логов.
     *
     * Пример:
     *   LogFacade::scope('payment', ['rid' => '...'])->info('ok', ['order' => 123]);
     *
     * @param array<string,mixed> $baseContext
     */
    public static function scope(string $prefix, array $baseContext = []): ScopedLogger
    {
        return new ScopedLogger(self::get(), $prefix, $baseContext);
    }
}

/**
 * Небольшой помощник для префикса и общего контекста.
 */
final class ScopedLogger
{
    private LoggerInterface $inner;
    private string $prefix;

    /** @var array<string,mixed> */
    private array $baseContext;

    /**
     * @param array<string,mixed> $baseContext
     */
    public function __construct(LoggerInterface $inner, string $prefix, array $baseContext = [])
    {
        $this->inner       = $inner;
        $this->prefix      = $prefix;
        $this->baseContext = $baseContext;
    }

    /**
     * @param array<string,mixed> $context
     */
    public function log(string $level, string $message, array $context = []): void
    {
        $this->inner->log(
            $level,
            '[' . $this->prefix . '] ' . $message,
            $this->baseContext + $context
        );
    }

    public function emergency(string $message, array $context = []): void
    {
        $this->log('emergency', $message, $context);
    }

    public function alert(string $message, array $context = []): void
    {
        $this->log('alert', $message, $context);
    }

    public function critical(string $message, array $context = []): void
    {
        $this->log('critical', $message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->log('error', $message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->log('warning', $message, $context);
    }

    public function notice(string $message, array $context = []): void
    {
        $this->log('notice', $message, $context);
    }

    public function info(string $message, array $context = []): void
    {
        $this->log('info', $message, $context);
    }

    public function debug(string $message, array $context = []): void
    {
        $this->log('debug', $message, $context);
    }
}