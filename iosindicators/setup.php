<?php

use Glpi\Plugin\Hooks;
use GlpiPlugin\Iosindicators\ActionTimeBackfill;
use GlpiPlugin\Iosindicators\AiRca;
use GlpiPlugin\Iosindicators\Classifier;
use GlpiPlugin\Iosindicators\Dashboard;

/**
 * IOS Indicators - Indicadores operacionais e classificação de incidentes para GLPI 11.
 */
define('PLUGIN_IOSINDICATORS_VERSION', '0.9.0');
define('PLUGIN_IOSINDICATORS_MIN_GLPI_VERSION', '11.0.0');
define('PLUGIN_IOSINDICATORS_MAX_GLPI_VERSION', '11.0.99');

/**
 * Carrega segredos/configurações locais do volume de configuração do GLPI.
 *
 * Ambiente Docker atual:
 *   host:      /opt/glpi/glpi11/config
 *   container: /var/glpi/config
 */
function plugin_iosindicators_load_local_env(): void
{
    if (trim((string) getenv('GEMINI_API_KEY')) !== '') {
        return;
    }

    $candidates = [
        '/var/glpi/config/iosindicators.env',
        '/var/glpi/config/.env',
        '/opt/glpi/glpi11/config/iosindicators.env',
        '/opt/glpi/glpi11/config/.env',
    ];

    foreach (['/var/glpi/config/*.env', '/opt/glpi/glpi11/config/*.env'] as $pattern) {
        $matches = glob($pattern) ?: [];
        foreach ($matches as $match) {
            if (!in_array($match, $candidates, true)) {
                $candidates[] = $match;
            }
        }
    }

    foreach ($candidates as $file) {
        if (!is_readable($file)) {
            continue;
        }

        $values = @parse_ini_file($file, false, INI_SCANNER_RAW);
        if (!is_array($values) || empty($values['GEMINI_API_KEY'])) {
            continue;
        }

        $key = trim((string) $values['GEMINI_API_KEY'], " \t\n\r\0\x0B\"'");
        if ($key === '') {
            continue;
        }

        putenv('GEMINI_API_KEY=' . $key);
        $_ENV['GEMINI_API_KEY'] = $key;
        $_SERVER['GEMINI_API_KEY'] = $key;
        break;
    }
}

function plugin_init_iosindicators(): void
{
    global $PLUGIN_HOOKS;

    plugin_iosindicators_load_local_env();

    $PLUGIN_HOOKS['csrf_compliant']['iosindicators'] = true;

    Plugin::registerClass(Dashboard::class);
    Plugin::registerClass(Classifier::class);
    Plugin::registerClass(AiRca::class);
    Plugin::registerClass(ActionTimeBackfill::class);

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
