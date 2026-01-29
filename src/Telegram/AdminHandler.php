<?php
declare(strict_types=1);

namespace TgRemainder\Telegram;

use Throwable;
use TgRemainder\Components\AdminMenuKeyboard;
use TgRemainder\Components\HelpText;
use TgRemainder\Logger\LogFacade as Log;
use TgRemainder\Repositories\EventRepository;
use TgRemainder\Services\ExcelReminderExporter;
use TgRemainder\Services\ExcelReminderParser;

/**
 * Админские команды и загрузка Excel.
 *
 * Команды:
 * - /start - показать меню
 * - /help - краткая справка
 * - "Справка" - развернутая памятка по парсеру
 * - "Экспорт в Excel" - выгрузка текущих напоминаний
 * - "Получить шаблон" - отдать xlsx-шаблон
 * - "Очистить старые данные" - удалить одноразовые прошедшие события
 *
 * Загрузка .xlsx:
 * - Режимы: update (по умолчанию) / replace (в подписи к файлу).
 */
final class AdminHandler
{
    private readonly CommandContext $ctx;

    public function __construct(CommandContext $ctx)
    {
        $this->ctx = $ctx;
    }

    /**
     * Точка входа: документ имеет приоритет, затем текстовые команды.
     * Молчит, если chat_id отсутствует.
     */
    public function handle(): void
    {
        $chatId = $this->ctx->update->getChatId();
        if (!$chatId) {
            Log::debug('Admin: service update without chat_id, skip', ['file' => 'webhook.log']);
            return;
        }

        Log::debug('Admin: start', ['chat_id' => $chatId, 'file' => 'webhook.log']);

        $document = $this->ctx->update->getDocument();
        if ($document !== null) {
            Log::debug('Admin: document detected', [
                'chat_id' => $chatId,
                'name'    => (string) ($document['file_name'] ?? ''),
                'file'    => 'webhook.log',
            ]);

            $this->handleDocument($chatId, $document);

            Log::debug('Admin: done (document)', ['chat_id' => $chatId, 'file' => 'webhook.log']);
            return;
        }

        $text = $this->ctx->update->getText();
        if ($text === null) {
            Log::debug('Admin: no text, nothing to do', ['chat_id' => $chatId, 'file' => 'webhook.log']);
            return;
        }

        switch ($text) {
            case '/start':
                Log::debug('Admin: /start', ['chat_id' => $chatId, 'file' => 'webhook.log']);
                $this->handleStart($chatId);
                break;

            case '/help':
                Log::debug('Admin: /help', ['chat_id' => $chatId, 'file' => 'webhook.log']);
                $this->handleHelp($chatId);
                break;

            case AdminMenuKeyboard::BTN_HELP:
                Log::debug('Admin: show help memo', ['chat_id' => $chatId, 'file' => 'webhook.log']);
                $this->ctx->bot->sendMessage($chatId, HelpText::get(), ['parse_mode' => 'Markdown']);
                break;

            case AdminMenuKeyboard::BTN_EXPORT:
                Log::debug('Admin: export requested', ['chat_id' => $chatId, 'file' => 'webhook.log']);
                $filepath = null;

                try {
                    $eventRepo = new EventRepository($this->ctx->db);
                    $exporter  = new ExcelReminderExporter($eventRepo);
                    $filepath  = $exporter->export();

                    if (is_file($filepath)) {
                        $this->ctx->bot->sendDocument(
                            $chatId,
                            $filepath,
                            '📤 Экспорт напоминаний (Excel)'
                        );

                        Log::debug('Admin: export done', [
                            'chat_id' => $chatId,
                            'path'    => $filepath,
                            'file'    => 'webhook.log',
                        ]);
                    } else {
                        // Экспортер мог вернуть текст ошибки
                        $this->ctx->bot->sendMessage($chatId, (string) $filepath);
                        Log::warning('Admin: export returned non-file', [
                            'chat_id' => $chatId,
                            'file'    => 'webhook.log',
                        ]);
                    }
                } catch (Throwable $e) {
                    Log::error('Admin: export failed: {e}', [
                        'e'       => $e,
                        'chat_id' => $chatId,
                        'file'    => 'webhook.log',
                    ]);
                    $this->ctx->bot->sendMessage($chatId, '❌ Ошибка при экспорте.');
                } finally {
                    if (is_string($filepath) && is_file($filepath)) {
                        @unlink($filepath); // удаляем временный файл после отправки/попытки
                    }
                }
                break;

            case AdminMenuKeyboard::BTN_TEMPLATE:
                $templatePath = dirname(__DIR__, 2) . '/storage/templates/template.xlsx';
                if (is_file($templatePath)) {
                    $caption = <<<'TXT'
📝 Шаблон для загрузки напоминаний.
Важно: все столбцы должны быть в формате "Текст".
В шаблоне выделено 19 ячеек с нужным форматом. Нужен больший объем — скопируйте выделенный блок.
TXT;
                    $this->ctx->bot->sendDocument($chatId, $templatePath, $caption);
                    Log::debug('Admin: template sent', ['chat_id' => $chatId, 'file' => 'webhook.log']);
                } else {
                    $this->ctx->bot->sendMessage($chatId, '❌ Шаблон Excel не найден.');
                    Log::warning('Admin: template missing', [
                        'chat_id' => $chatId,
                        'path'    => $templatePath,
                        'file'    => 'webhook.log',
                    ]);
                }
                break;

            case AdminMenuKeyboard::BTN_CLEANUP_OLD:
                try {
                    $eventRepo    = new EventRepository($this->ctx->db);
                    $deletedCount = $eventRepo->deletePastEvents();

                    $message = $deletedCount > 0
                        ? "🧹 Удалено {$deletedCount} старых напоминаний."
                        : 'ℹ️ Нет прошедших напоминаний для удаления.';

                    $this->ctx->bot->sendMessage($chatId, $message);

                    Log::debug('Admin: cleanup done', [
                        'chat_id' => $chatId,
                        'deleted' => $deletedCount,
                        'file'    => 'webhook.log',
                    ]);
                } catch (Throwable $e) {
                    Log::error('Admin: cleanup failed: {e}', [
                        'e'       => $e,
                        'chat_id' => $chatId,
                        'file'    => 'webhook.log',
                    ]);
                    $this->ctx->bot->sendMessage($chatId, '❌ Ошибка при очистке данных.');
                }
                break;

            default:
                Log::debug('Admin: unknown command', [
                    'chat_id' => $chatId,
                    'len'     => strlen($text),
                    'file'    => 'webhook.log',
                ]);
                $this->ctx->bot->sendMessage(
                    $chatId,
                    'Неизвестная команда. Напишите /help для списка команд.'
                );
                break;
        }

        Log::debug('Admin: done (command)', ['chat_id' => $chatId, 'file' => 'webhook.log']);
    }

    /**
     * /start: приветствие и клавиатура.
     */
    private function handleStart(int $chatId): void
    {
        $this->ctx->bot->sendMessage(
            $chatId,
            'Привет! Бот принимает Excel-файлы и отправляет напоминания.',
            ['reply_markup' => AdminMenuKeyboard::get()]
        );
    }

    /**
     * /help: краткая справка по действиям.
     */
    private function handleHelp(int $chatId): void
    {
        $message = <<<'TXT'
📋 Доступные команды:
/start — Приветствие и меню
/help — Краткая справка

📤 Загрузка Excel:
Пришлите .xlsx файл. В подписи (caption) укажите режим:

— update (по умолчанию) — добавить данные
— replace — очистить и загрузить заново

Примеры:
- Отправьте файл с подписью "replace"
- Или просто отправьте файл без подписи (будет update)
TXT;

        $this->ctx->bot->sendMessage($chatId, $message);
    }

    /**
     * Обработка .xlsx-документа.
     *
     * @param int                 $chatId   Идентификатор чата
     * @param array<string,mixed> $document Массив документа из Telegram Update
     */
    private function handleDocument(int $chatId, array $document): void
    {
        $fileName = (string) ($document['file_name'] ?? 'file.xlsx');
        $fileId   = (string) ($document['file_id'] ?? '');

        // Разрешаем только .xlsx по расширению (MIME от Telegram не всегда надежен)
        $ext = strtolower((string) pathinfo($fileName, PATHINFO_EXTENSION));
        if ($fileId === '' || $ext !== 'xlsx') {
            $this->ctx->bot->sendMessage($chatId, '⚠️ Поддерживаются только файлы .xlsx');
            Log::warning('Admin: reject non-xlsx or empty file_id', [
                'chat_id' => $chatId,
                'name'    => $fileName,
                'file'    => 'webhook.log',
            ]);
            return;
        }

        $filePath = $this->ctx->bot->getFilePath($fileId);
        if ($filePath === null || $filePath === '') {
            $this->ctx->bot->sendMessage($chatId, '❌ Не удалось получить путь к файлу от Telegram API.');
            Log::warning('Admin: getFilePath returned empty', ['chat_id' => $chatId, 'file' => 'webhook.log']);
            return;
        }

        // Временный файл без утечки имени
        $tmpDir = sys_get_temp_dir();
        try {
            $tmpFile = $tmpDir . DIRECTORY_SEPARATOR . bin2hex(random_bytes(16)) . '.xlsx';
        } catch (Throwable $e) {
            // Редкий fallback: tempnam + удаляем базовый файл
            $base = tempnam($tmpDir, 'upload_');
            if ($base === false) {
                Log::error('Admin: temp file allocation failed', [
                    'chat_id' => $chatId,
                    'file'    => 'webhook.log',
                ]);
                $this->ctx->bot->sendMessage($chatId, '❌ Внутренняя ошибка при подготовке временного файла.');
                return;
            }
            @unlink($base);
            $tmpFile = $base . '.xlsx';
        }

        Log::debug('Admin: downloading file', ['chat_id' => $chatId, 'file' => 'webhook.log']);

        $downloaded = $this->ctx->bot->downloadFile($filePath, $tmpFile);
        if (!$downloaded) {
            $this->ctx->bot->sendMessage($chatId, '❌ Не удалось скачать файл.');
            Log::warning('Admin: download failed', ['chat_id' => $chatId, 'file' => 'webhook.log']);
            @unlink($tmpFile);
            return;
        }

        $this->ctx->bot->sendMessage($chatId, '📥 Файл получен. Начинаю обработку...');

        // Режим загрузки из подписи (caption)
        $caption = strtolower(trim((string) ($this->ctx->update->getCaption() ?? '')));
        $mode    = 'update';

        if ($caption !== '') {
            if (in_array($caption, ['replace', 'truncate', 'clear'], true)) {
                $mode = 'replace';
            } elseif (!in_array($caption, ['update', 'append'], true)) {
                $this->ctx->bot->sendMessage(
                    $chatId,
                    "⚠️ Неподдерживаемый режим: \"{$caption}\"\nИспользуйте: update или replace"
                );
                Log::warning('Admin: unsupported caption mode', [
                    'chat_id' => $chatId,
                    'caption' => $caption,
                    'file'    => 'webhook.log',
                ]);
                @unlink($tmpFile);
                return;
            }
        }

        Log::debug('Admin: mode decided', [
            'chat_id' => $chatId,
            'mode'    => $mode,
            'file'    => 'webhook.log',
        ]);

        try {
            $parser = new ExcelReminderParser();

            /** @var array{
             *   reminders: array<int,array<string,mixed>>,
             *   errors: array<int,string>
             * } $result
             */
            $result = $parser->parse($tmpFile, $fileName);
        } catch (Throwable $e) {
            Log::error('Admin: parse failed: {e}', [
                'e'       => $e,
                'chat_id' => $chatId,
                'file'    => 'webhook.log',
            ]);
            $this->ctx->bot->sendMessage($chatId, '❌ Внутренняя ошибка при разборе файла.');
            @unlink($tmpFile);
            return;
        } finally {
            @unlink($tmpFile);
        }

        $reminders = $result['reminders'] ?? [];
        $errors    = $result['errors'] ?? [];

        if (!empty($errors)) {
            $head = array_slice($errors, 0, 10);
            $msg  = "⚠️ Ошибки при разборе файла:\n\n" . implode("\n", $head);
            $left = count($errors) - count($head);
            if ($left > 0) {
                $msg .= "\n...и еще {$left} ошибок.";
            }

            $this->ctx->bot->sendMessage($chatId, $msg);

            Log::warning('Admin: parse returned errors', [
                'chat_id' => $chatId,
                'errors'  => count($errors),
                'file'    => 'webhook.log',
            ]);
            return;
        }

        if (empty($reminders)) {
            $this->ctx->bot->sendMessage($chatId, '⚠️ В файле нет корректных напоминаний для загрузки.');
            Log::warning('Admin: no reminders parsed', ['chat_id' => $chatId, 'file' => 'webhook.log']);
            return;
        }

        try {
            $eventRepo = new EventRepository($this->ctx->db);
            $saved     = $mode === 'replace'
                ? $eventRepo->replaceAll($reminders)
                : $eventRepo->saveEvents($reminders);

            $this->ctx->bot->sendMessage($chatId, "💾 Успешно сохранено: {$saved} событий.");

            Log::debug('Admin: save done', [
                'chat_id' => $chatId,
                'mode'    => $mode,
                'saved'   => $saved,
                'file'    => 'webhook.log',
            ]);
        } catch (Throwable $e) {
            Log::error('Admin: save failed: {e}', [
                'e'       => $e,
                'chat_id' => $chatId,
                'file'    => 'webhook.log',
            ]);
            $this->ctx->bot->sendMessage($chatId, '❌ Ошибка при сохранении данных.');
        }
    }
}