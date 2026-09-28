<?php

use GlpiPlugin\Iosindicators\Dashboard;
use GlpiPlugin\Iosindicators\Metrics;

include(__DIR__ . '/../../../inc/includes.php');
Plugin::load('iosindicators');
Session::checkRight('config', UPDATE);

global $DB;

Html::header(
    __('Diagnóstico - IOS Indicators', 'iosindicators'),
    $_SERVER['PHP_SELF'],
    'plugins',
    Dashboard::class,
    ''
);

$checks = [];
$add = static function (string $name, bool $ok, string $detail = '') use (&$checks): void {
    $checks[] = ['name' => $name, 'ok' => $ok, 'detail' => $detail];
};

$add('GLPI', defined('GLPI_VERSION'), defined('GLPI_VERSION') ? GLPI_VERSION : 'não identificado');
$add('PHP >= 8.2', version_compare(PHP_VERSION, '8.2.0', '>='), PHP_VERSION);
$add('Tabela glpi_tickets', $DB->tableExists('glpi_tickets'));
$add('Tabela glpi_tickettasks', $DB->tableExists('glpi_tickettasks'));

foreach (['date', 'status', 'type', 'entities_id', 'is_deleted', 'solvedate', 'closedate', 'takeintoaccount_delay_stat', 'solve_delay_stat', 'actiontime', 'waiting_duration', 'close_delay_stat'] as $field) {
    $add('glpi_tickets.' . $field, $DB->fieldExists('glpi_tickets', $field));
}
foreach (['tickets_id', 'content'] as $field) {
    $ok = $DB->tableExists('glpi_tickettasks') && $DB->fieldExists('glpi_tickettasks', $field);
    $add('glpi_tickettasks.' . $field, $ok);
}

$summaryError = null;
$summary = null;
try {
    [$from, $to] = Metrics::periodFromRequest(['days' => 7]);
    $summary = Metrics::summary($from, $to);
    $add('Consulta de indicadores (7 dias)', true, 'executada');
} catch (Throwable $e) {
    $summaryError = $e;
    $add('Consulta de indicadores (7 dias)', false, $e->getMessage());
}

echo '<div class="container py-3" style="max-width:1100px">';
echo '<div class="d-flex justify-content-between align-items-center mb-3"><div><h2 class="mb-1">Diagnóstico IOS Indicators</h2><div class="text-muted">Use esta página para identificar incompatibilidades do schema sem consultar o banco manualmente.</div></div><a class="btn btn-outline-primary" href="dashboard.php">Voltar aos indicadores</a></div>';
echo '<div class="card"><div class="table-responsive"><table class="table table-vcenter mb-0"><thead><tr><th>Teste</th><th>Status</th><th>Detalhe</th></tr></thead><tbody>';
foreach ($checks as $check) {
    $status = $check['ok'] ? '<span class="badge bg-success">OK</span>' : '<span class="badge bg-danger">ERRO</span>';
    echo '<tr><td>' . htmlescape($check['name']) . '</td><td>' . $status . '</td><td><code>' . htmlescape($check['detail']) . '</code></td></tr>';
}
echo '</tbody></table></div></div>';

echo '<div class="card mt-3"><div class="card-header"><strong>Contexto</strong></div><div class="card-body"><pre class="mb-0">';
echo htmlescape(print_r([
    'plugin_version' => defined('PLUGIN_IOSINDICATORS_VERSION') ? PLUGIN_IOSINDICATORS_VERSION : 'n/a',
    'active_entity' => $_SESSION['glpiactive_entity'] ?? null,
    'active_entities' => $_SESSION['glpiactiveentities'] ?? null,
    'profile' => $_SESSION['glpiactiveprofile']['name'] ?? ($_SESSION['glpiactiveprofile']['id'] ?? null),
    'interface' => $_SESSION['glpiactiveprofile']['interface'] ?? null,
], true));
echo '</pre></div></div>';

if (is_array($summary) && !empty($summary['diagnostics'])) {
    echo '<div class="card mt-3"><div class="card-header"><strong>Avisos das consultas</strong></div><div class="card-body"><ul class="mb-0">';
    foreach ($summary['diagnostics'] as $msg) {
        echo '<li><code>' . htmlescape((string)$msg) . '</code></li>';
    }
    echo '</ul></div></div>';
}

if ($summaryError) {
    echo '<div class="alert alert-danger mt-3"><strong>Exceção:</strong><br><code>' . htmlescape($summaryError->getMessage()) . '</code><br><small>' . htmlescape($summaryError->getFile()) . ':' . (int)$summaryError->getLine() . '</small></div>';
}

echo '<div class="alert alert-secondary mt-3 mb-0"><strong>Logs do GLPI:</strong> confira também <code>files/_log/php-errors.log</code> e <code>files/_log/sql-errors.log</code>.</div>';
echo '</div>';

Html::footer();
