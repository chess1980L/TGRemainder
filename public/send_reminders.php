<?php

declare(strict_types=1);

use TgRemainder\Bootstrap\DbFactory;
use TgRemainder\Bootstrap\EntryKernel;
use TgRemainder\Logger\LogFacade as Log;
use TgRemainder\Repositories\EventRepository;
use TgRemainder\Telegram\AppConfig;
use TgRemainder\Telegram\Bot;
use TgRemainder\Telegram\ReminderChecker;

/**
 * Точка входа для cron: отправка “подошедших” напоминаний.
 *
 * Задачи:
 * - запрет параллельных запусков (lock-файл),
 * - быстрый ping БД,
 * - аккуратное логирование и уборка ресурсов.
 */

$autoload = dirname(__DIR__) . '/vendor/autoload.php';
$sosPath  = dirname(__DIR__) . '/logs/_cron_sos.log';

if (!is_file($autoload)) {
    @file_put_contents($sosPath, date('c') . " missing autoload\n", FILE_APPEND);
    return;
}

require_once $autoload;

final class CronSendRemindersEntrypoint
{
    public function run(): void
    {
        @set_time_limit(25);

        $rid = bin2hex(random_bytes(8));
        $log = Log::scope('cron', ['rid' => $rid, 'file' => 'cron.log']);
        $log->info('start');

        $token = AppConfig::getTelegramToken();
        if ($token === '') {
            $log->error('TELEGRAM_BOT_TOKEN is not set');
            return;
        }

        // Лочим запуск, чтобы два процесса крона не отправляли одно и то же параллельно.
        $lockDir  = (is_dir('/var/run') && is_writable('/var/run')) ? '/var/run' : sys_get_temp_dir();
        $lockPath = $lockDir . '/tgr_send_reminders.lock';

        /** @var resource|false $lockFp */
        $lockFp = @fopen($lockPath, 'c+');
        if ($lockFp === false) {
            $log->error('cannot open lock file', ['lock' => $lockPath]);
            return;
        }

        if (!@flock($lockFp, LOCK_EX | LOCK_NB)) {
            $log->info('another instance is running, exiting', ['lock' => $lockPath]);
            @fclose($lockFp);
            return;
        }

        // Для удобства: пишем PID в lock-файл (не обязательно, но полезно при диагностике).
        ftruncate($lockFp, 0);
        fwrite($lockFp, (string) getmypid());

        $start = microtime(true);

        try {
            $pdo = DbFactory::create(AppConfig::getDbConfig());

            // Быстрый ping БД: если БД не отвечает, не тратим время на остальную инициализацию.
            try {
                $pong = $pdo->query('SELECT 1')->fetchColumn();
                if ((string) $pong !== '1') {
                    throw new RuntimeException('SELECT 1 returned unexpected result');
                }
            } catch (Throwable $e) {
                $log->error('MySQL ping failed: {e}', ['e' => $e]);
                return;
            }

            $bot     = new Bot($token, AppConfig::getHttpTimeout(), AppConfig::getHttpConnectTimeout());
            $repo    = new EventRepository($pdo);
            $checker = new ReminderChecker($repo, $bot);

            $sent = $checker->sendDueReminders();

            $duration      = round(microtime(true) - $start, 3);
            $slowThreshold = (float) AppConfig::get('CRON_SLOW_SEC', '0.2');
            $logIdle       = AppConfig::get('CRON_LOG_IDLE', '0') === '1';

            if ($sent > 0) {
                $log->info('sent', ['count' => $sent, 'duration' => $duration]);
            } elseif ($duration > $slowThreshold) {
                $log->warning('slow run', [
                    'sent'      => $sent,
                    'duration'  => $duration,
                    'threshold' => $slowThreshold,
                ]);
            } elseif ($logIdle) {
                $log->info('idle', ['sent' => $sent, 'duration' => $duration]);
            }
        } catch (Throwable $e) {
            $log->error('fatal: {e}', ['e' => $e]);
        } finally {
            if (isset($pdo)) {
                $pdo = null;
            }

            if (is_resource($lockFp)) {
                @flock($lockFp, LOCK_UN);
                @fclose($lockFp);
            }

            $log->info('finish', [
                'duration' => round(microtime(true) - $start, 3),
            ]);
        }
    }
}

EntryKernel::run(
    [
        'sos_file' => $sosPath,
    ],
    static function (): void {
        (new CronSendRemindersEntrypoint())->run();
    }
);