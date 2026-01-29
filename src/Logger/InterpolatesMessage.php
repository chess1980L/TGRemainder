<?php
declare(strict_types=1);

namespace TgRemainder\Logger;

trait InterpolatesMessage
{
    /**
     * @param array<string,mixed> $context
     */
    private function interpolate(string $message, array $context): string
    {
        $repl = [];

        foreach ($context as $k => $v) {
            $repl['{' . $k . '}'] = $this->stringify($v);
        }

        return strtr($message, $repl);
    }

    private function stringify(mixed $v): string
    {
        return match (true) {
            $v instanceof \Throwable => (string) $v,
            is_null($v)              => 'null',
            is_scalar($v)            => (string) $v,
            is_object($v) && method_exists($v, '__toString')
            => (string) $v,
            is_object($v) || is_array($v)
            => json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: 'null',
            is_resource($v)          => 'resource(' . get_resource_type($v) . ')',
            default                  => 'null',
        };
    }
}