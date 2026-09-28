<?php

namespace GlpiPlugin\Iosindicators;

final class Settings
{
    public const CONTEXT = 'plugin:iosindicators';

    public static function defaults(): array
    {
        return [
            'default_period_days' => 30,
            'ai_task_tag'         => '[IA-RCA]',
            'remote_task_tag'     => '[REMOTO]',
            'zabbix_marker'       => 'Zabbix',
            'mbtr_source'         => 'none',
            'show_only_incidents' => 1,
        ];
    }

    public static function all(): array
    {
        return array_merge(
            self::defaults(),
            \Config::getConfigurationValues(self::CONTEXT)
        );
    }

    public static function get(string $key, mixed $fallback = null): mixed
    {
        $settings = self::all();
        return $settings[$key] ?? $fallback;
    }

    public static function save(array $input): void
    {
        $days = max(1, min(3650, (int)($input['default_period_days'] ?? 30)));

        $mbtrAllowed = ['none', 'waiting_duration', 'close_delay_stat', 'actiontime', 'solve_delay_stat'];
        $mbtrSource = (string)($input['mbtr_source'] ?? 'none');
        if (!in_array($mbtrSource, $mbtrAllowed, true)) {
            $mbtrSource = 'none';
        }

        \Config::setConfigurationValues(self::CONTEXT, [
            'default_period_days' => $days,
            'ai_task_tag'         => self::cleanTag((string)($input['ai_task_tag'] ?? '[IA-RCA]')),
            'remote_task_tag'     => self::cleanTag((string)($input['remote_task_tag'] ?? '[REMOTO]')),
            'zabbix_marker'       => trim((string)($input['zabbix_marker'] ?? 'Zabbix')),
            'mbtr_source'         => $mbtrSource,
            'show_only_incidents' => isset($input['show_only_incidents']) ? 1 : 0,
        ]);
    }

    private static function cleanTag(string $value): string
    {
        $value = trim($value);
        return mb_substr($value !== '' ? $value : '-', 0, 80);
    }
}
