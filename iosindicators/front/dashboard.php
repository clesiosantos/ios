<?php

use GlpiPlugin\Iosindicators\Dashboard;
use GlpiPlugin\Iosindicators\Metrics;

include(__DIR__ . '/../../../inc/includes.php');
Plugin::load('iosindicators');

if (!Dashboard::canView()) {
    Html::displayErrorAndDie(__('Você precisa da permissão "Ver todos os tickets" para acessar os indicadores consolidados.', 'iosindicators'));
}

Html::header(
    __('Indicadores de Incidentes', 'iosindicators'),
    $_SERVER['PHP_SELF'],
    'plugins',
    Dashboard::class,
    ''
);

// Carregamento explícito do CSS para evitar que cache/hook do GLPI deixe o painel sem estilo.
global $CFG_GLPI;
echo '<link rel="stylesheet" href="' . htmlescape($CFG_GLPI['root_doc'] . '/plugins/iosindicators/css/iosindicators.css?v=' . PLUGIN_IOSINDICATORS_VERSION) . '">';

try {
    [$from, $to] = Metrics::periodFromRequest($_GET);
    $summary = Metrics::summary($from, $to);

    echo '<div class="container-fluid py-3 iosindicators-wrapper">';
    echo '<div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-3">';
    echo '<div><h2 class="mb-1">Indicadores Operacionais de Incidentes</h2>';
    echo '<div class="text-muted">Confiabilidade, desempenho, recorrência e qualidade estrutural dos tickets monitorados</div></div>';

    echo '<form method="get" class="d-flex flex-wrap align-items-end gap-2 iosindicators-filter">';
    echo '<div><label class="form-label mb-1">Período rápido</label><select class="form-select" name="days">';
    $currentDays = isset($_GET['days']) ? (int)$_GET['days'] : 30;
    foreach ([1 => 'Hoje', 7 => '7 dias', 30 => '30 dias', 90 => '90 dias', 365 => '1 ano'] as $days => $label) {
        $selected = ($currentDays === $days && empty($_GET['from'])) ? ' selected' : '';
        echo '<option value="' . $days . '"' . $selected . '>' . htmlescape($label) . '</option>';
    }
    echo '</select></div>';
    echo '<div><label class="form-label mb-1">De</label><input type="date" class="form-control" name="from" value="' . htmlescape((string)($_GET['from'] ?? '')) . '"></div>';
    echo '<div><label class="form-label mb-1">Até</label><input type="date" class="form-control" name="to" value="' . htmlescape((string)($_GET['to'] ?? '')) . '"></div>';
    echo '<button class="btn btn-primary" type="submit"><i class="ti ti-filter"></i> Aplicar</button>';
    echo '</form></div>';

    echo '<div class="alert alert-info py-2">Período considerado: <strong>' . $from->format('d/m/Y H:i') . '</strong> até <strong>' . $to->format('d/m/Y H:i') . '</strong>. O recorte usa a data de abertura do ticket.</div>';

    if (!empty($summary['diagnostics']) && Session::haveRight('config', UPDATE)) {
        echo '<div class="alert alert-warning"><strong>Diagnóstico do plugin</strong><ul class="mb-0 mt-2">';
        foreach ($summary['diagnostics'] as $message) {
            echo '<li><code>' . htmlescape((string)$message) . '</code></li>';
        }
        echo '</ul><div class="mt-2"><a href="diagnostics.php">Abrir diagnóstico técnico completo</a></div></div>';
    }

    Dashboard::renderPage($summary);
    echo '</div>';
} catch (Throwable $e) {
    echo '<div class="container-fluid py-3">';
    echo '<div class="alert alert-danger"><strong>Erro no IOS Indicators</strong><br>';
    echo 'O plugin interceptou o erro para evitar a tela genérica do GLPI.';
    if (Session::haveRight('config', UPDATE)) {
        echo '<hr><code>' . htmlescape($e->getMessage()) . '</code>';
        echo '<div class="small mt-2">Arquivo: ' . htmlescape($e->getFile()) . ':' . (int)$e->getLine() . '</div>';
        echo '<div class="mt-2"><a class="btn btn-sm btn-outline-danger" href="diagnostics.php">Abrir diagnóstico técnico</a></div>';
    }
    echo '</div></div>';
}

Html::footer();
