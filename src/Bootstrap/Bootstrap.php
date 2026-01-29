<?php
declare(strict_types=1);

namespace TgRemainder\Bootstrap;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use TgRemainder\Logger\FileByContextDecorator;
use TgRemainder\Logger\FileLogger;
use TgRemainder\Logger\LevelFilterDecorator;
use TgRemainder\Logger\LogFacade;

/**
 * Единая инициализация приложения:
 *  - загрузка .env (по умолчанию с override)
 *  - системные лимиты/таймзона
 *  - файловый логгер + выбор файла по контексту
 *  - фильтр уровней из .env
 *  - регистрация во фасаде LogFacade
 */
final class Bootstrap
{
    /**
     * @param array{
     *   root?:string,
     *   env?:string,
     *   env_override?:bool   // по умолчанию true
     * } $opts
     */
    public static function init(array $opts = []): LoggerInterface
    {
        $root        = $opts['root'] ?? dirname(__DIR__, 2);
        $envPath     = $opts['env']  ?? ($root . '/.env');
        $envOverride = array_key_exists('env_override', $opts)
            ? (bool) $opts['env_override']
            : true;

        // 1) .env
        Env::load($envPath, $envOverride);

        // Удобный геттер env
        $env = static function (string $key, string $default = '') {
            $v = getenv($key);
            if ($v === false) {
                $v = $_ENV[$key] ?? $default;
            }
            return is_string($v) ? $v : (string) $v;
        };

        // 2) лимиты/сеттинги процесса
        @ini_set('memory_limit',                 $env('APP_MEMORY_LIMIT', '64M'));
        @ini_set('default_socket_timeout',       $env('APP_SOCKET_TIMEOUT', '5'));
        @ini_set('mysqlnd.net_read_timeout',     '5');
        @ini_set('mysqlnd.net_cmd_buffer_size',  '4096');
        @ini_set('mysqlnd.net_read_buffer_size', '32768');

        // 3) таймзона
        date_default_timezone_set($env('APP_TIMEZONE', 'Europe/Moscow'));

        // 4) логи
        $logDir = $env('LOG_DIR', $root . '/logs');
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0775, true);
        }
        @umask(0002);

        // Базовый логгер -> декоратор явного файла -> фильтр по уровню
        $base    = is_dir($logDir) ? new FileLogger($logDir) : new NullLogger();
        $context = new FileByContextDecorator($base, $logDir);

        $minLevel = strtolower(trim($env('LOG_MIN_LEVEL', 'error')));
        $allowed  = [
            'debug','info','notice','warning','error','critical','alert','emergency',
        ];
        if (!in_array($minLevel, $allowed, true)) {
            $minLevel = 'error';
        }

        $logger = new LevelFilterDecorator($context, $minLevel);

        // Регистрируем во фасаде
        LogFacade::set($logger);

        // Смок-метка в DEBUG (в проде срежется порогом), уводим в отдельный файл
        LogFacade::debug('OK: logger initialized', ['file' => 'bootstrap.log']);

        return $logger;
    }
}
