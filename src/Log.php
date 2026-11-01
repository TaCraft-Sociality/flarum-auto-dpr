<?php

namespace TacraftSociality\AutoDpr;

use Psr\Log\LoggerInterface;
use Throwable;

/**
 * 日志写入：优先使用 Flarum 的 PSR-3 日志，失败时退回 PHP error_log，
 * 保证清理过程中的异常不会影响论坛本身的请求。
 */
final class Log
{
    public static function warning(string $message, array $context = []): void
    {
        self::write('warning', $message, $context);
    }

    public static function error(string $message, array $context = []): void
    {
        self::write('error', $message, $context);
    }

    private static function write(string $level, string $message, array $context = []): void
    {
        try {
            $logger = resolve(LoggerInterface::class);

            if ($logger instanceof LoggerInterface) {
                $logger->{$level}($message, $context);

                return;
            }
        } catch (Throwable) {
            // 继续使用 error_log 兜底
        }

        try {
            $suffix = $context === [] ? '' : ' '.json_encode($context, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
            error_log('[flarum-auto-dpr] '.$message.$suffix);
        } catch (Throwable) {
            // 忽略
        }
    }
}
