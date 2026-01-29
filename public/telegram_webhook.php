<?php

declare(strict_types=1);

use TgRemainder\Bootstrap\DbFactory;
use TgRemainder\Bootstrap\EntryKernel;
use TgRemainder\Logger\LogFacade as Log;
use TgRemainder\Telegram\AppConfig;
use TgRemainder\Telegram\Bot;
use TgRemainder\Telegram\CommandContext;
use TgRemainder\Telegram\Router;
use TgRemainder\Telegram\Update;

/**
 * Точка входа webhook Telegram.
 *
 * Задачи:
 * - максимально быстро ответить 200 OK (чтобы Telegram не делал ретраи),
 * - иметь аварийный плейн-лог до автолоада/инициализации,
 * - после раннего ACK спокойно выполнить основную обработку.
 */

$autoload = dirname(__DIR__) . '/vendor/autoload.php';
$sosPath  = dirname(__DIR__) . '/logs/_webhook_sos.log';

/**
 * Резерв памяти: если случится OOM, в shutdown освободим кусок и сможем записать SOS.
 */
$fatalMemReserve = str_repeat('R', 32768);

register_shutdown_function(
    static function () use (&$fatalMemReserve, $sosPath): void {
        $fatalMemReserve = '';

        $e = error_get_last();
        $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];

        if ($e === null || !in_array((int) ($e['type'] ?? 0), $fatalTypes, true)) {
            return;
        }

        @file_put_contents(
            $sosPath,
            date('c') . ' FATAL ' . json_encode($e, JSON_UNESCAPED_UNICODE) . PHP_EOL,
            FILE_APPEND
        );

        // Если заголовки ещё не отправлены — попробуем вернуть 200 OK, чтобы Telegram не ретраил.
        if (!headers_sent()) {
            http_response_code(200);
            header('Content-Type: text/plain; charset=UTF-8');
            echo 'OK';
        }
    }
);

// Если нет composer autoload — бизнес-логика недоступна, но Telegram надо быстро “успокоить”.
if (!is_file($autoload)) {
    if (!headers_sent()) {
        http_response_code(200);
        header('Content-Type: text/plain; charset=UTF-8');
    }

    echo 'OK';
    @file_put_contents($sosPath, date('c') . " missing autoload\n", FILE_APPEND);

    return;
}

require_once $autoload;

final class EarlyAck
{
    private static bool $sent = false;

    /**
     * Отправить "OK" ровно один раз.
     * При FPM/FastCGI стараемся закрыть ответ и продолжить работу “в фоне”.
     */
    public static function sendOnce(): void
    {
        if (self::$sent) {
            return;
        }

        self::$sent = true;

        if (!headers_sent()) {
            http_response_code(200);
            header('Content-Type: text/plain; charset=UTF-8');
        }

        echo 'OK';

        // Идеальный путь для FPM/FastCGI: завершить HTTP-ответ и продолжить выполнение скрипта.
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
            return;
        }

        // Фолбэк для окружений без fastcgi_finish_request().
        @ob_flush();
        @flush();
    }
}

final class TelegramWebhookEntrypoint
{
    public function run(): void
    {
        @set_time_limit(3);

        $rid   = bin2hex(random_bytes(8));
        $log   = Log::scope('webhook', ['rid' => $rid, 'file' => 'webhook.log']);
        $start = microtime(true);

        // На время обработки превращаем предупреждения/нотисы в исключения, чтобы ловить их в одном месте.
        set_error_handler(
            static function (int $severity, string $message, string $file = '', int $line = 0): bool {
                if (!(error_reporting() & $severity)) {
                    return false;
                }

                throw new ErrorException($message, 0, $severity, $file, $line);
            }
        );

        try {
            // Дешёвые проверки до чтения тела и до тяжёлой инициализации.

            $method = $_SERVER['REQUEST_METHOD'] ?? '';
            if ($method !== 'POST') {
                EarlyAck::sendOnce();
                $log->info('non-POST request');
                return;
            }

            $ct = $_SERVER['CONTENT_TYPE'] ?? '';
            if ($ct === '' || stripos($ct, 'application/json') === false) {
                EarlyAck::sendOnce();
                $log->warning('unexpected Content-Type', ['ct' => $ct]);
                return;
            }

            // Проверка секретного токена вебхука (если настроен в конфиге).
            $expected = AppConfig::getTelegramWebhookSecret();
            $secret   = $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '';
            if ($expected !== '' && !hash_equals($expected, $secret)) {
                EarlyAck::sendOnce();
                $log->error('secret mismatch');
                return;
            }

            // Ограничение размера тела.
            $maxBody = 1024 * 1024; // 1MB
            $cl      = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
            if ($cl > $maxBody) {
                EarlyAck::sendOnce();
                $log->error('content length exceeded', ['cl' => $cl]);
                return;
            }

            $raw = file_get_contents('php://input') ?: '';
            if ($raw === '' || strlen($raw) > $maxBody) {
                EarlyAck::sendOnce();
                $log->info('empty or too big body', ['len' => strlen($raw)]);
                return;
            }

            // На этом этапе запрос выглядит валидным — даём ранний ACK.
            EarlyAck::sendOnce();

            /** @var array<string, mixed> $data */
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

            $token = AppConfig::getTelegramToken();
            if ($token === '') {
                $log->error('Telegram token is not configured');
                return;
            }

            $bot = new Bot(
                $token,
                AppConfig::getHttpTimeout(),
                AppConfig::getHttpConnectTimeout()
            );

            $pdo = DbFactory::create(AppConfig::getDbConfig());

            $this->handleUpdate($data, $bot, $pdo);

            $log->info('handled', [
                'ms' => round((microtime(true) - $start) * 1000, 1),
            ]);
        } catch (JsonException $e) {
            $log->error('JSON decode failed: {e}', ['e' => $e]);
        } catch (Throwable $e) {
            $log->error('unhandled error: {e}', ['e' => $e]);
        } finally {
            restore_error_handler();

            $log->info('done', [
                'duration' => round(microtime(true) - $start, 3),
            ]);
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function handleUpdate(array $data, Bot $bot, PDO $pdo): void
    {
        $update = new Update($data);
        $ctx    = new CommandContext($bot, $update, $pdo);

        (new Router($ctx))->handle();
    }
}

EntryKernel::run(
    [
        'sos_file' => $sosPath,
        'on_bootstrap_fail' => static function (): void {
            // Даже если bootstrap упал — Telegram всё равно надо быстро “успокоить”.
            EarlyAck::sendOnce();
        },
    ],
    static function (): void {
        (new TelegramWebhookEntrypoint())->run();
    }
);