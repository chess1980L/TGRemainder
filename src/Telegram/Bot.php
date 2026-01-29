<?php

declare(strict_types=1);

namespace TgRemainder\Telegram;

use CURLFile;
use TgRemainder\Logger\LogFacade as Log;
use Throwable;

final class Bot implements BotClientInterface
{
    private const CA_BUNDLE = '/etc/ssl/certs/ca-certificates.crt';

    private string $token;
    private string $apiUrl;

    private int $timeout;        // общий таймаут запроса (сек)
    private int $connectTimeout; // таймаут установления соединения (сек)

    /** @var array{http_code:int, error_code?:int, description?:string, transport?:string}|null */
    private ?array $lastError = null;

    public function __construct(string $token, int $timeout = 15, ?int $connectTimeout = null)
    {
        $this->token          = $token;
        $this->apiUrl         = "https://api.telegram.org/bot{$this->token}";
        $this->timeout        = max(1, $timeout);
        $this->connectTimeout = max(1, $connectTimeout ?? min($this->timeout, 5));
    }

    /**
     * @return array{http_code:int, error_code?:int, description?:string, transport?:string}|null
     */
    public function getLastError(): ?array
    {
        return $this->lastError;
    }

    /**
     * @param array<string,mixed> $params
     */
    public function sendMessage(int $chatId, string $text, array $params = []): bool
    {
        $payload = array_merge([
            'chat_id' => $chatId,
            'text'    => $text,
        ], $params);

        if (isset($payload['reply_markup']) && is_array($payload['reply_markup'])) {
            $payload['reply_markup'] = json_encode($payload['reply_markup'], JSON_UNESCAPED_UNICODE);
        }

        $data = $this->requestJson('sendMessage', $payload, httpMethod: 'POST');

        return $data !== null && ($data['ok'] ?? false) === true;
    }

    public function getFilePath(string $fileId): ?string
    {
        $data = $this->requestJson('getFile', ['file_id' => $fileId], httpMethod: 'POST');
        if ($data === null || !($data['ok'] ?? false)) {
            Log::error('Bot.getFilePath: Telegram API error', [
                'file_id' => $fileId,
                'last'    => $this->lastError,
            ]);
            return null;
        }

        return $data['result']['file_path'] ?? null;
    }

    public function downloadFile(string $filePath, string $saveTo): bool
    {
        $this->lastError = null;

        $url = "https://api.telegram.org/file/bot{$this->token}/{$filePath}";

        if (function_exists('curl_init')) {
            $fp = @fopen($saveTo, 'wb');
            if ($fp === false) {
                $this->lastError = [
                    'http_code'   => 0,
                    'transport'   => 'fs',
                    'description' => 'failed to open file for writing',
                ];
                Log::error('Bot.downloadFile: failed to open file for writing', ['path' => $saveTo]);
                return false;
            }

            $ch = curl_init($url);

            $opts = [
                CURLOPT_FILE            => $fp,
                CURLOPT_FOLLOWLOCATION  => false,
                CURLOPT_CONNECTTIMEOUT  => $this->connectTimeout,
                CURLOPT_TIMEOUT         => $this->timeout,
                CURLOPT_PROTOCOLS       => CURLPROTO_HTTPS,
                CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_SSL_VERIFYPEER  => true,
                CURLOPT_SSL_VERIFYHOST  => 2,
            ];

            if (is_file(self::CA_BUNDLE)) {
                $opts[CURLOPT_CAINFO] = self::CA_BUNDLE;
            }

            curl_setopt_array($ch, $opts);

            $ok    = curl_exec($ch);
            $errno = curl_errno($ch);
            $err   = curl_error($ch);
            $info  = curl_getinfo($ch);
            $code  = (int)($info['http_code'] ?? 0);

            curl_close($ch);
            fclose($fp);

            if ($ok === false || $code !== 200) {
                @unlink($saveTo);

                $this->lastError = [
                    'http_code'   => $code,
                    'transport'   => 'curl',
                    'description' => "errno={$errno}; {$err}",
                ];

                Log::error('Bot.downloadFile: failed', [
                    'http_code'   => $code,
                    'curl_errno'  => $errno,
                    'curl_error'  => $err,
                    'url'         => self::maskTokenInUrl($url),
                ]);

                return false;
            }

            return true;
        }

        // fallback streams (редко нужно на проде, но пусть будет)
        $context = stream_context_create(['http' => ['timeout' => $this->timeout]]);
        $content = @file_get_contents($url, false, $context);

        if ($content === false) {
            $this->lastError = [
                'http_code'   => 0,
                'transport'   => 'stream',
                'description' => 'download failed',
            ];
            Log::error('Bot.downloadFile: failed via streams', ['url' => self::maskTokenInUrl($url)]);
            return false;
        }

        if (@file_put_contents($saveTo, $content) === false) {
            $this->lastError = [
                'http_code'   => 0,
                'transport'   => 'fs',
                'description' => 'failed to write file',
            ];
            Log::error('Bot.downloadFile: failed to write file', ['path' => $saveTo]);
            return false;
        }

        return true;
    }

    public function sendDocument(int $chatId, string $filePath, string $caption = ''): bool
    {
        $this->lastError = null;

        if (!function_exists('curl_init')) {
            $this->lastError = [
                'http_code'   => 0,
                'transport'   => 'curl',
                'description' => 'cURL extension is required',
            ];
            Log::error('Bot.sendDocument: cURL extension is required');
            return false;
        }

        $real = realpath($filePath);
        if ($real === false || !is_file($real)) {
            $this->lastError = [
                'http_code'   => 0,
                'transport'   => 'fs',
                'description' => 'file not found',
            ];
            Log::error('Bot.sendDocument: file not found', ['path' => $filePath]);
            return false;
        }

        $url = "{$this->apiUrl}/sendDocument";

        $postFields = [
            'chat_id'  => $chatId,
            'caption'  => $caption,
            'document' => new CURLFile($real),
        ];

        $ch = curl_init();

        $opts = [
            CURLOPT_URL             => $url,
            CURLOPT_RETURNTRANSFER  => true,
            CURLOPT_POST            => true,
            CURLOPT_POSTFIELDS      => $postFields,
            CURLOPT_CONNECTTIMEOUT  => $this->connectTimeout,
            CURLOPT_TIMEOUT         => $this->timeout,
            CURLOPT_HTTPHEADER      => ['Accept: application/json'],
            CURLOPT_PROTOCOLS       => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER  => true,
            CURLOPT_SSL_VERIFYHOST  => 2,
        ];

        if (is_file(self::CA_BUNDLE)) {
            $opts[CURLOPT_CAINFO] = self::CA_BUNDLE;
        }

        curl_setopt_array($ch, $opts);

        $resp = curl_exec($ch);

        if ($resp === false) {
            $errno = curl_errno($ch);
            $err   = curl_error($ch);
            $info  = curl_getinfo($ch);
            curl_close($ch);

            $this->lastError = [
                'http_code'   => (int)($info['http_code'] ?? 0),
                'transport'   => 'curl',
                'description' => "errno={$errno}; {$err}",
            ];

            Log::error('Bot.sendDocument: cURL error', [
                'curl_errno' => $errno,
                'curl_error' => $err,
                'url'        => self::maskTokenInUrl($url),
            ]);

            return false;
        }

        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $data = json_decode((string)$resp, true);
        if (!is_array($data)) {
            $this->lastError = [
                'http_code'   => $httpCode,
                'transport'   => 'decode',
                'description' => 'invalid JSON response',
            ];
            Log::error('Bot.sendDocument: invalid JSON response', [
                'http_code' => $httpCode,
                'url'       => self::maskTokenInUrl($url),
            ]);
            return false;
        }

        if ($httpCode !== 200 || !($data['ok'] ?? false)) {
            $this->lastError = [
                'http_code'   => $httpCode,
                'error_code'  => isset($data['error_code']) ? (int)$data['error_code'] : null,
                'description' => isset($data['description']) ? (string)$data['description'] : null,
                'transport'   => 'telegram',
            ];

            Log::error('Bot.sendDocument: Telegram API error', [
                'http_code' => $httpCode,
                'error'     => $this->lastError,
                'url'       => self::maskTokenInUrl($url),
            ]);

            return false;
        }

        return true;
    }

    /**
     * Универсальный JSON-запрос к Telegram API.
     *
     * @param array<string,mixed> $params
     * @return array<string,mixed>|null
     */
    private function requestJson(string $method, array $params, string $httpMethod = 'POST'): ?array
    {
        $this->lastError = null;

        $httpMethod = strtoupper(trim($httpMethod));
        $url = "{$this->apiUrl}/{$method}";

        try {
            if (function_exists('curl_init')) {
                $ch = curl_init();

                $opts = [
                    CURLOPT_RETURNTRANSFER  => true,
                    CURLOPT_CONNECTTIMEOUT  => $this->connectTimeout,
                    CURLOPT_TIMEOUT         => $this->timeout,
                    CURLOPT_HTTPHEADER      => ['Accept: application/json'],
                    CURLOPT_PROTOCOLS       => CURLPROTO_HTTPS,
                    CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
                    CURLOPT_SSL_VERIFYPEER  => true,
                    CURLOPT_SSL_VERIFYHOST  => 2,
                ];

                if ($httpMethod === 'GET') {
                    $qs = http_build_query($params, '', '&');
                    $opts[CURLOPT_URL] = $qs === '' ? $url : "{$url}?{$qs}";
                    $opts[CURLOPT_HTTPGET] = true;
                } else {
                    $opts[CURLOPT_URL] = $url;
                    $opts[CURLOPT_POST] = true;
                    $opts[CURLOPT_POSTFIELDS] = http_build_query($params, '', '&');
                    $opts[CURLOPT_HTTPHEADER][] = 'Content-Type: application/x-www-form-urlencoded';
                }

                if (is_file(self::CA_BUNDLE)) {
                    $opts[CURLOPT_CAINFO] = self::CA_BUNDLE;
                }

                curl_setopt_array($ch, $opts);

                $resp = curl_exec($ch);
                if ($resp === false) {
                    $errno = curl_errno($ch);
                    $err   = curl_error($ch);
                    $info  = curl_getinfo($ch);
                    curl_close($ch);

                    $this->lastError = [
                        'http_code'   => (int)($info['http_code'] ?? 0),
                        'transport'   => 'curl',
                        'description' => "errno={$errno}; {$err}",
                    ];

                    Log::error("Bot.requestJson: cURL failed for {$method}", [
                        'curl_errno' => $errno,
                        'curl_error' => $err,
                        'url'        => self::maskTokenInUrl($url),
                    ]);

                    return null;
                }

                $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                $data = json_decode((string)$resp, true);
                if (!is_array($data)) {
                    $this->lastError = [
                        'http_code'   => $httpCode,
                        'transport'   => 'decode',
                        'description' => 'invalid JSON response',
                    ];

                    Log::error("Bot.requestJson: invalid JSON response for {$method}", [
                        'http_code' => $httpCode,
                        'url'       => self::maskTokenInUrl($url),
                    ]);

                    return null;
                }

                if ($httpCode !== 200 || !($data['ok'] ?? false)) {
                    $this->lastError = [
                        'http_code'   => $httpCode,
                        'error_code'  => isset($data['error_code']) ? (int)$data['error_code'] : null,
                        'description' => isset($data['description']) ? (string)$data['description'] : null,
                        'transport'   => 'telegram',
                    ];

                    Log::error("Bot.requestJson: Telegram API error for {$method}", [
                        'http_code' => $httpCode,
                        'error'     => $this->lastError,
                        'url'       => self::maskTokenInUrl($url),
                    ]);
                }

                return $data;
            }

            // streams fallback
            if ($httpMethod === 'GET') {
                $qs = http_build_query($params, '', '&');
                $reqUrl = $qs === '' ? $url : "{$url}?{$qs}";

                $context = stream_context_create([
                    'http' => [
                        'method'  => 'GET',
                        'timeout' => $this->timeout,
                        'header'  => "Accept: application/json\r\n",
                    ],
                ]);

                $resp = @file_get_contents($reqUrl, false, $context);
                $headers = $http_response_header ?? [];
                $httpCode = self::extractHttpCode(is_array($headers) ? $headers : []);

                if ($resp === false) {
                    $this->lastError = [
                        'http_code'   => $httpCode,
                        'transport'   => 'stream',
                        'description' => 'request failed',
                    ];
                    Log::error("Bot.requestJson: stream request failed for {$method}", [
                        'http_code' => $httpCode,
                        'url'       => self::maskTokenInUrl($reqUrl),
                    ]);
                    return null;
                }

                $data = json_decode((string)$resp, true);
                if (!is_array($data)) {
                    $this->lastError = [
                        'http_code'   => $httpCode,
                        'transport'   => 'decode',
                        'description' => 'invalid JSON response',
                    ];
                    Log::error("Bot.requestJson: invalid JSON response for {$method} (stream)", [
                        'http_code' => $httpCode,
                        'url'       => self::maskTokenInUrl($reqUrl),
                    ]);
                    return null;
                }

                return $data;
            }

            // POST via streams
            $context = stream_context_create([
                'http' => [
                    'method'  => 'POST',
                    'timeout' => $this->timeout,
                    'header'  => "Accept: application/json\r\nContent-Type: application/x-www-form-urlencoded\r\n",
                    'content' => http_build_query($params, '', '&'),
                ],
            ]);

            $resp = @file_get_contents($url, false, $context);
            $headers = $http_response_header ?? [];
            $httpCode = self::extractHttpCode(is_array($headers) ? $headers : []);

            if ($resp === false) {
                $this->lastError = [
                    'http_code'   => $httpCode,
                    'transport'   => 'stream',
                    'description' => 'request failed',
                ];
                Log::error("Bot.requestJson: stream POST failed for {$method}", [
                    'http_code' => $httpCode,
                    'url'       => self::maskTokenInUrl($url),
                ]);
                return null;
            }

            $data = json_decode((string)$resp, true);
            if (!is_array($data)) {
                $this->lastError = [
                    'http_code'   => $httpCode,
                    'transport'   => 'decode',
                    'description' => 'invalid JSON response',
                ];
                Log::error("Bot.requestJson: invalid JSON response for {$method} (stream POST)", [
                    'http_code' => $httpCode,
                    'url'       => self::maskTokenInUrl($url),
                ]);
                return null;
            }

            return $data;
        } catch (Throwable $e) {
            $this->lastError = [
                'http_code'   => 0,
                'transport'   => 'exception',
                'description' => $e->getMessage(),
            ];
            Log::error("Bot.requestJson: exception for {$method}", ['e' => $e]);
            return null;
        }
    }

    /**
     * @param string[] $headers
     */
    private static function extractHttpCode(array $headers): int
    {
        $code = 0;
        foreach ($headers as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#i', $h, $m)) {
                $code = (int) $m[1];
            }
        }
        return $code;
    }

    private static function maskTokenInUrl(string $url): string
    {
        return preg_replace('~(api\.telegram\.org/(?:file/)?bot)([^/]+)~', '$1***', $url) ?? $url;
    }
}