<?php

namespace GlpiPlugin\Iosindicators;

final class Settings
{
    public const CONTEXT = 'plugin:iosindicators';

    public static function defaults(): array
    {
        return [
            'default_period_days'           => 30,
            'ai_task_tag'                   => '[IA-RCA]',
            'remote_task_tag'               => '[REMOTO]',
            'zabbix_marker'                 => 'Zabbix',
            'mbtr_source'                   => 'none',
            'show_only_incidents'           => 1,
            'classifier_enabled'            => 0,
            'classifier_immediate'          => 1,
            'classifier_create_categories'  => 1,
            'classifier_create_hosts'       => 1,
            'classifier_assign_requester'   => 1,
            'classifier_assign_noc'         => 1,
            'classifier_overwrite_category' => 0,
            'classifier_batch_size'         => 100,
            'classifier_cursor_id'          => 0,
            'classifier_root_category'      => 'Monitoramento',
            'classifier_noc_root_group'     => 'NOC',
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
        $batchSize = max(1, min(1000, (int)($input['classifier_batch_size'] ?? 100)));

        $mbtrAllowed = ['none', 'waiting_duration', 'close_delay_stat', 'actiontime', 'solve_delay_stat'];
        $mbtrSource = (string)($input['mbtr_source'] ?? 'none');
        if (!in_array($mbtrSource, $mbtrAllowed, true)) {
            $mbtrSource = 'none';
        }

        $values = [
            'default_period_days'           => $days,
            'ai_task_tag'                   => self::cleanTag((string)($input['ai_task_tag'] ?? '[IA-RCA]')),
            'remote_task_tag'               => self::cleanTag((string)($input['remote_task_tag'] ?? '[REMOTO]')),
            'zabbix_marker'                 => trim((string)($input['zabbix_marker'] ?? 'Zabbix')),
            'mbtr_source'                   => $mbtrSource,
            'show_only_incidents'           => isset($input['show_only_incidents']) ? 1 : 0,
            'classifier_enabled'            => isset($input['classifier_enabled']) ? 1 : 0,
            'classifier_immediate'          => isset($input['classifier_immediate']) ? 1 : 0,
            'classifier_create_categories'  => isset($input['classifier_create_categories']) ? 1 : 0,
            'classifier_create_hosts'       => isset($input['classifier_create_hosts']) ? 1 : 0,
            'classifier_assign_requester'   => isset($input['classifier_assign_requester']) ? 1 : 0,
            'classifier_assign_noc'         => isset($input['classifier_assign_noc']) ? 1 : 0,
            'classifier_overwrite_category' => isset($input['classifier_overwrite_category']) ? 1 : 0,
            'classifier_batch_size'         => $batchSize,
            'classifier_root_category'      => self::cleanName((string)($input['classifier_root_category'] ?? 'Monitoramento'), 'Monitoramento'),
            'classifier_noc_root_group'     => self::cleanName((string)($input['classifier_noc_root_group'] ?? 'NOC'), 'NOC'),
        ];

        if (isset($input['reset_classifier_cursor']) || isset($input['classifier_cursor_id'])) {
            $values['classifier_cursor_id'] = 0;
        }

        \Config::setConfigurationValues(self::CONTEXT, $values);
    }

    private static function cleanTag(string $value): string
    {
        $value = trim($value);
        return mb_substr($value !== '' ? $value : '-', 0, 80);
    }

    private static function cleanName(string $value, string $fallback): string
    {
        $value = trim($value);
        return mb_substr($value !== '' ? $value : $fallback, 0, 255);
    }
}
