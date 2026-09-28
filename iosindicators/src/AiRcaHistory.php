<?php

namespace GlpiPlugin\Iosindicators;

use Throwable;

final class AiRcaHistory
{
    private const CONFIG_LOG_DIR = '/var/glpi/config/iosindicators-logs';
    private const FILE_NAME = 'ai-rca.jsonl';
    private const MAX_LINES = 10000;

    public static function append(array $record): void
    {
        $file = self::filePath(true);
        if ($file === null) {
            return;
        }

        $record = array_merge([
            'timestamp' => date('Y-m-d H:i:s'),
            'type' => 'ticket',
            'status' => 'info',
            'ticket_id' => 0,
            'task_id' => 0,
            'source' => 'unknown',
            'model' => '',
            'http_status' => 0,
            'duration_ms' => 0,
            'estimated_minutes' => null,
            'confidence' => null,
            'input_tokens' => null,
            'output_tokens' => null,
            'total_tokens' => null,
            'message' => '',
        ], $record);

        try {
            $json = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            @file_put_contents($file, $json . PHP_EOL, FILE_APPEND | LOCK_EX);
            self::prune($file);
        } catch (Throwable $e) {
            self::fallbackLog('Falha ao gravar histórico IA/RCA: ' . $e->getMessage());
        }
    }

    public static function recent(int $limit = 100): array
    {
        $limit = max(1, min(1000, $limit));
        $file = self::filePath(false);
        if ($file === null || !is_readable($file)) {
            return [];
        }

        $lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines) || $lines === []) {
            return [];
        }

        $rows = [];
        foreach (array_reverse(array_slice($lines, -$limit)) as $line) {
            $row = json_decode($line, true);
            if (is_array($row)) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    public static function latestForTicket(int $ticketId): ?array
    {
        if ($ticketId <= 0) {
            return null;
        }

        foreach (self::recent(2000) as $row) {
            if ((int) ($row['ticket_id'] ?? 0) === $ticketId && ($row['type'] ?? 'ticket') === 'ticket') {
                return $row;
            }
        }

        return null;
    }

    public static function hasSuccess(int $ticketId): bool
    {
        $latest = self::latestForTicket($ticketId);
        return $latest !== null && in_array((string) ($latest['status'] ?? ''), ['success', 'existing'], true);
    }

    public static function isCoolingDown(int $ticketId): bool
    {
        $latest = self::latestForTicket($ticketId);
        if ($latest === null) {
            return false;
        }

        $status = (string) ($latest['status'] ?? '');
        if (!in_array($status, ['error', 'rate_limited', 'service_unavailable'], true)) {
            return false;
        }

        $minutes = max(1, min(1440, (int) Settings::get('ai_rca_failure_cooldown_minutes', 60)));
        $ts = strtotime((string) ($latest['timestamp'] ?? ''));
        if ($ts === false) {
            return false;
        }

        return (time() - $ts) < ($minutes * 60);
    }

    public static function summary(int $limit = 1000): array
    {
        $summary = [
            'success' => 0,
            'existing' => 0,
            'error' => 0,
            'rate_limited' => 0,
            'service_unavailable' => 0,
            'deferred' => 0,
            'batch' => 0,
        ];

        foreach (self::recent($limit) as $row) {
            $type = (string) ($row['type'] ?? 'ticket');
            if ($type === 'batch') {
                $summary['batch']++;
                continue;
            }
            $status = (string) ($row['status'] ?? '');
            if (array_key_exists($status, $summary)) {
                $summary[$status]++;
            }
        }

        return $summary;
    }

    public static function filePathForDisplay(): string
    {
        return self::filePath(false) ?? self::CONFIG_LOG_DIR . '/' . self::FILE_NAME;
    }

    private static function filePath(bool $create): ?string
    {
        $dirs = [self::CONFIG_LOG_DIR];

        if (defined('GLPI_LOG_DIR')) {
            $dirs[] = rtrim((string) GLPI_LOG_DIR, '/') . '/iosindicators';
        }

        foreach ($dirs as $dir) {
            if (!is_dir($dir) && $create) {
                @mkdir($dir, 0770, true);
            }

            if (!is_dir($dir)) {
                continue;
            }

            $file = rtrim($dir, '/') . '/' . self::FILE_NAME;
            if (is_file($file) && is_readable($file)) {
                return $file;
            }
            if ($create && is_writable($dir)) {
                return $file;
            }
        }

        return null;
    }

    private static function prune(string $file): void
    {
        clearstatcache(true, $file);
        if (!is_file($file) || filesize($file) < 5 * 1024 * 1024) {
            return;
        }

        $lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines) || count($lines) <= self::MAX_LINES) {
            return;
        }

        $lines = array_slice($lines, -self::MAX_LINES);
        @file_put_contents($file, implode(PHP_EOL, $lines) . PHP_EOL, LOCK_EX);
    }

    private static function fallbackLog(string $message): void
    {
        if (isset($GLOBALS['PHPLOGGER'])) {
            $GLOBALS['PHPLOGGER']->error('IOS Indicators AI/RCA History: ' . $message);
        }
    }
}
