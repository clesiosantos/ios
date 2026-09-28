<?php

/**
 * Instalação: não cria tabelas próprias. As configurações ficam em glpi_configs.
 */
function plugin_iosindicators_install(): bool
{
    $defaults = [
        'default_period_days' => 30,
        'ai_task_tag'         => '[IA-RCA]',
        'remote_task_tag'     => '[REMOTO]',
        'zabbix_marker'       => 'Zabbix',
        'mbtr_source'         => 'none',
        'show_only_incidents' => 1,
    ];

    $current = Config::getConfigurationValues('plugin:iosindicators');
    $missing = [];

    foreach ($defaults as $key => $value) {
        if (!array_key_exists($key, $current)) {
            $missing[$key] = $value;
        }
    }

    if ($missing !== []) {
        Config::setConfigurationValues('plugin:iosindicators', $missing);
    }

    return true;
}

function plugin_iosindicators_uninstall(): bool
{
    $config = new Config();
    $config->deleteByCriteria(['context' => 'plugin:iosindicators']);

    return true;
}
