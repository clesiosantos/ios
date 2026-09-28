<?php

use GlpiPlugin\Iosindicators\Classifier;
use GlpiPlugin\Iosindicators\Settings;

function plugin_iosindicators_install(): bool
{
    $current = Config::getConfigurationValues(Settings::CONTEXT);
    $missing = [];

    foreach (Settings::defaults() as $key => $value) {
        if (!array_key_exists($key, $current)) {
            $missing[$key] = $value;
        }
    }

    if ($missing !== []) {
        Config::setConfigurationValues(Settings::CONTEXT, $missing);
    }

    // Ação automática visível em Configuração > Ações automáticas.
    // Frequência mínima sugerida: 5 minutos.
    CronTask::Register(Classifier::class, 'Classifier', 300);

    return true;
}

function plugin_iosindicators_uninstall(): bool
{
    CronTask::unregister('iosindicators');

    $config = new Config();
    $config->deleteByCriteria(['context' => Settings::CONTEXT]);

    return true;
}
