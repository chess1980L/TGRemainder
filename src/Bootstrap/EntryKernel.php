<?php

declare(strict_types=1);

namespace TgRemainder\Bootstrap;

/**
 * EntryKernel — безопасный раннер:
 * 1) включает TinySos на время Bootstrap::init()
 * 2) при фейле Bootstrap пишет SOS и вызывает on_bootstrap_fail (если задан)
 * 3) отключает TinySos и запускает runner
 */
final class EntryKernel
{
    /**
     * @param array{
     *   sos_file: string,
     *   bootstrap_opts?: array,
     *   on_bootstrap_fail?: callable():void
     * } $opts
     * @param callable():void $runner
     */
    public static function run(array $opts, callable $runner): void
    {
        $sosFile  = $opts['sos_file'] ?? (sys_get_temp_dir() . '/tiny_sos.log');
        $bootOpts = $opts['bootstrap_opts'] ?? [];
        $onFail   = $opts['on_bootstrap_fail'] ?? null;

        TinySos::install($sosFile);

        try {
            Bootstrap::init($bootOpts);
        } catch (\Throwable $e) {
            TinySos::write(
                'BOOTSTRAP FAIL: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine()
            );

            if (is_callable($onFail)) {
                try {
                    $onFail();
                } catch (\Throwable) {
                    // игнорируем ошибки обработчика фейла
                }
            }

            return;
        } finally {
            // uninstall делаем в одном месте, чтобы не было двойного восстановления обработчиков
            TinySos::uninstall();
        }

        $runner();
    }
}