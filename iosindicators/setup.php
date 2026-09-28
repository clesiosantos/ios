<?php

use GlpiPlugin\Iosindicators\Dashboard;

/**
 * IOS Indicators - Indicadores operacionais para GLPI 11.
 */
define('PLUGIN_IOSINDICATORS_VERSION', '0.1.1');
define('PLUGIN_IOSINDICATORS_MIN_GLPI_VERSION', '11.0.0');
define('PLUGIN_IOSINDICATORS_MAX_GLPI_VERSION', '11.0.99');

function plugin_init_iosindicators(): void
{
    global $PLUGIN_HOOKS;

    $PLUGIN_HOOKS['csrf_compliant']['iosindicators'] = true;

    // Registro explícito da classe, seguindo o padrão do plugin de exemplo do GLPI 11.
    Plugin::registerClass(Dashboard::class);

    if (Session::getLoginUserID()) {
        if (Dashboard::canView()) {
            $PLUGIN_HOOKS['menu_toadd']['iosindicators'] = [
                'plugins' => Dashboard::class,
            ];
        }

        if (Session::haveRight('config', UPDATE)) {
            $PLUGIN_HOOKS['config_page']['iosindicators'] = 'front/config.php';
        }

        $PLUGIN_HOOKS['add_css']['iosindicators'] = 'css/iosindicators.css';
    }

    // Integração com o dashboard nativo. Mantida, mas isolada do painel principal.
    $PLUGIN_HOOKS['dashboard_types']['iosindicators'] = [Dashboard::class, 'dashboardTypes'];
    $PLUGIN_HOOKS['dashboard_cards']['iosindicators'] = [Dashboard::class, 'dashboardCards'];
}

function plugin_version_iosindicators(): array
{
    return [
        'name'         => 'IOS - Indicadores de Incidentes',
        'version'      => PLUGIN_IOSINDICATORS_VERSION,
        'author'       => 'IOS',
        'license'      => 'GPLv3+',
        'homepage'     => '',
        'requirements' => [
            'glpi' => [
                'min' => PLUGIN_IOSINDICATORS_MIN_GLPI_VERSION,
                'max' => PLUGIN_IOSINDICATORS_MAX_GLPI_VERSION,
            ],
            'php' => [
                'min' => '8.2.0',
            ],
        ],
    ];
}

function plugin_iosindicators_check_prerequisites(): bool
{
    return version_compare(GLPI_VERSION, PLUGIN_IOSINDICATORS_MIN_GLPI_VERSION, '>=')
        && version_compare(GLPI_VERSION, PLUGIN_IOSINDICATORS_MAX_GLPI_VERSION, '<');
}

function plugin_iosindicators_check_config(bool $verbose = false): bool
{
    return true;
}
