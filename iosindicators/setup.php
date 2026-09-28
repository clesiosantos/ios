<?php

use Glpi\Plugin\Hooks;
use GlpiPlugin\Iosindicators\AiRca;
use GlpiPlugin\Iosindicators\Classifier;
use GlpiPlugin\Iosindicators\Dashboard;

/**
 * IOS Indicators - Indicadores operacionais e classificação de incidentes para GLPI 11.
 */
define('PLUGIN_IOSINDICATORS_VERSION', '0.8.0');
define('PLUGIN_IOSINDICATORS_MIN_GLPI_VERSION', '11.0.0');
define('PLUGIN_IOSINDICATORS_MAX_GLPI_VERSION', '11.0.99');

function plugin_init_iosindicators(): void
{
    global $PLUGIN_HOOKS;

    $PLUGIN_HOOKS['csrf_compliant']['iosindicators'] = true;

    Plugin::registerClass(Dashboard::class);
    Plugin::registerClass(Classifier::class);
    Plugin::registerClass(AiRca::class);

    // Classificação imediata de tickets novos. A rotina nunca bloqueia a criação do ticket.
    $PLUGIN_HOOKS[Hooks::ITEM_ADD]['iosindicators'] = [
        Ticket::class => [Classifier::class, 'onTicketAdd'],
    ];

    if (Session::getLoginUserID()) {
        if (Dashboard::canView()) {
            $PLUGIN_HOOKS['menu_toadd']['iosindicators'] = [
                'plugins' => Dashboard::class,
            ];
        }

        if (Session::haveRight('config', UPDATE)) {
            $PLUGIN_HOOKS['config_page']['iosindicators'] = 'front/config.php';
        }

        // O dashboard carrega o CSS no lado do servidor (inline) para evitar 404
        // em instalações GLPI 11 onde /plugins/<plugin>/css não é publicado pelo webroot.
    }

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
