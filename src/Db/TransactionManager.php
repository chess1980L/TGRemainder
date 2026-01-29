<?php
declare(strict_types=1);

namespace TgRemainder\Db;

use PDO;
use Throwable;

/**
 * Лёгкий транзакционный менеджер с поддержкой вложенности через SAVEPOINT.
 * Поддерживает ретраи при дедлоках/serialization failure.
 */
final class TransactionManager
{
    /** @var array<string,int> Глубина вложенности по соединению (ключ — spl_object_id(PDO)) */
    private static array $depth = [];

    /**
     * Выполнить замыкание в транзакции.
     *
     * - Внешняя транзакция: begin/commit/rollback.
     * - Вложенные: SAVEPOINT/RELEASE/ROLLBACK TO.
     * - Ретрай при дедлоке (ошибки 1213, SQLSTATE 40001).
     *
     * @template T
     *
     * @param PDO           $pdo     Подключение PDO
     * @param callable():T  $fn      Пользовательская функция
     * @param int           $retries Кол-во повторов при дедлоке
     *
     * @return T Возвращает результат колбэка
     *
     * @throws Throwable Пробрасывает исключение пользователя, если ретраи исчерпаны
     */
    public static function run(PDO $pdo, callable $fn, int $retries = 1): mixed
    {
        $key = (string) spl_object_id($pdo);
        self::$depth[$key] = self::$depth[$key] ?? 0;

        $isOuter   = !$pdo->inTransaction();
        $savepoint = null;

        retry:
        try {
            if ($isOuter) {
                $pdo->beginTransaction();
            } else {
                $savepoint = 'sp_' . (self::$depth[$key] + 1);
                $pdo->exec("SAVEPOINT {$savepoint}");
            }

            self::$depth[$key]++;

            /** @var T $result */
            $result = $fn();

            self::$depth[$key]--;

            if ($isOuter) {
                $pdo->commit();
            } elseif ($savepoint !== null) {
                $pdo->exec("RELEASE SAVEPOINT {$savepoint}");
            }

            return $result;
        } catch (Throwable $e) {
            self::$depth[$key] = max(0, self::$depth[$key] - 1);

            if ($isOuter && $pdo->inTransaction()) {
                $pdo->rollBack();
            } elseif ($savepoint !== null) {
                // Если вложенная — откатываемся к сейвпоинту
                $pdo->exec("ROLLBACK TO SAVEPOINT {$savepoint}");
            }

            $sqlState = (string) $e->getCode();
            $msg      = $e->getMessage();
            $isDeadlock =
                $sqlState === '40001' ||            // serialization failure
                str_contains($msg, 'Deadlock') ||
                str_contains($msg, 'deadlock') ||
                str_contains($msg, '1213 ');       // ER_LOCK_DEADLOCK

            if ($isDeadlock && $retries > 0) {
                usleep(50_000);
                $retries--;
                goto retry;
            }

            throw $e;
        }
    }
}