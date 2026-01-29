<?php
declare(strict_types=1);

namespace TgRemainder\Telegram;

use TgRemainder\Logger\LogFacade as Log;
use TgRemainder\Repositories\SubscriberRepository;

/**
 * Маршрутизатор апдейтов Telegram.
 * - идемпотентная регистрация подписчика (первый контакт)
 * - разграничение прав (администратор / не администратор)
 * - делегирование админских операций в AdminHandler
 */
final class Router
{
    private readonly CommandContext $ctx;
    private readonly SubscriberRepository $subscriberRepository;

    public function __construct(CommandContext $ctx)
    {
        $this->ctx = $ctx;
        $this->subscriberRepository = new SubscriberRepository($ctx->db);
    }

    /**
     * Основная точка входа. Для апдейтов без chat_id — тихий выход.
     */
    public function handle(): void
    {
        $chatId = $this->ctx->update->getChatId();
        if ($chatId === 0) {
            Log::debug('Router: service update without chat_id, skip', ['file' => 'webhook.log']);
            return;
        }

        Log::debug('Router: start', ['chat_id' => $chatId, 'file' => 'webhook.log']);

        // Идемпотентная регистрация
        $this->registerSubscriber($chatId);

        $isAdmin = $this->ctx->update->isFromAdmin(AppConfig::getAdminIds());

        // Команды от не-админов
        if (!$isAdmin && $this->ctx->update->isCommand()) {
            $text = $this->ctx->update->getText() ?? '';
            $cmd  = null;

            if (preg_match('/^\/([A-Za-z0-9_]+)/', $text, $m)) {
                $cmd = strtolower((string) ($m[1] ?? ''));
            }

            $harmless = ['start', 'help', 'about'];
            $ctx = [
                'chat_id' => $chatId,
                'cmd'     => $cmd,
                'len'     => strlen($text),
                'file'    => 'webhook.log',
            ];

            if ($cmd !== null && !in_array($cmd, $harmless, true)) {
                Log::warning('Non-admin command attempt', $ctx);
            } else {
                Log::debug('Non-admin command attempt', $ctx);
            }

            $this->ctx->bot->sendMessage(
                $chatId,
                '⛔ Бот предназначен только для администраторов. ' .
                'Вы будете получать уведомления, но команды недоступны.'
            );

            Log::debug('Router: non-admin informed about restricted commands', [
                'chat_id' => $chatId,
                'file'    => 'webhook.log',
            ]);
            return;
        }

        // Админский режим
        if ($isAdmin) {
            Log::debug('Routing to AdminHandler', ['chat_id' => $chatId, 'file' => 'webhook.log']);
            (new AdminHandler($this->ctx))->handle();
        }

        Log::debug('Router: done', ['chat_id' => $chatId, 'file' => 'webhook.log']);
    }

    /**
     * Регистрирует подписчика, если его ещё нет в базе.
     *
     * @return bool true — создана новая запись; false — уже существовал или ошибка.
     */
    private function registerSubscriber(int $chatId): bool
    {
        $user = $this->ctx->update->getUser();

        try {
            $created = $this->subscriberRepository->save([
                'telegram_id' => $chatId,
                'username'    => $user['username']   ?? null,
                'first_name'  => $user['first_name'] ?? null,
                'last_name'   => $user['last_name']  ?? null,
            ]);

            if ($created) {
                $this->ctx->bot->sendMessage(
                    $chatId,
                    '✅ Вы подписались на уведомления. Напоминания будут приходить сюда.'
                );

                Log::debug('Subscriber registered', ['chat_id' => $chatId, 'file' => 'webhook.log']);
                return true;
            }

            Log::debug('Subscriber exists (idempotent)', ['chat_id' => $chatId, 'file' => 'webhook.log']);
            return false;

        } catch (\Throwable $e) {
            Log::error('Subscriber registration failed: {e}', [
                'e'       => $e,
                'chat_id' => $chatId,
                'file'    => 'webhook.log',
            ]);
            return false;
        }
    }
}
