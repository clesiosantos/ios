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

echo '<link rel="stylesheet" href="../css/iosindicators.css?v=' . urlencode((string) PLUGIN_IOSINDICATORS_VERSION) . '">';

try {
    [$from, $to] = Metrics::periodFromRequest($_GET);
    $summary = Metrics::summary($from, $to);

    echo '<div class="container-fluid py-3 iosindicators-wrapper">';
    echo '<div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-3">';
    echo '<div>';
    echo '<h2 class="mb-1">Indicadores Operacionais de Incidentes</h2>';
    echo '<div class="text-muted">Ciclo Zabbix → GLPI → Tratativa → Normalização → RCA / Base de Conhecimento</div>';
    echo '</div>';

    echo '<form method="get" class="d-flex flex-wrap align-items-end gap-2 iosindicators-filter">';
    echo '<div><label class="form-label mb-1">Período rápido</label><select class="form-select" name="days">';
    $currentDays = isset($_GET['days']) ? (int) $_GET['days'] : 30;
    foreach ([1 => 'Hoje', 7 => '7 dias', 30 => '30 dias', 90 => '90 dias', 365 => '1 ano'] as $days => $label) {
        $selected = ($currentDays === $days && empty($_GET['from'])) ? ' selected' : '';
        echo '<option value="' . $days . '"' . $selected . '>' . htmlescape($label) . '</option>';
    }
    echo '</select></div>';
    echo '<div><label class="form-label mb-1">De</label><input type="date" class="form-control" name="from" value="' . htmlescape((string) ($_GET['from'] ?? '')) . '"></div>';
    echo '<div><label class="form-label mb-1">Até</label><input type="date" class="form-control" name="to" value="' . htmlescape((string) ($_GET['to'] ?? '')) . '"></div>';
    echo '<button class="btn btn-primary" type="submit"><i class="ti ti-filter"></i> Aplicar</button>';
    echo '</form></div>';

    echo '<div class="alert alert-info py-2">Período considerado: <strong>' . $from->format('d/m/Y H:i') . '</strong> até <strong>' . $to->format('d/m/Y H:i') . '</strong>. O recorte usa a data de abertura do ticket.</div>';

    if (!empty($summary['diagnostics']) && Session::haveRight('config', UPDATE)) {
        echo '<div class="alert alert-warning"><strong>Diagnóstico do plugin</strong><ul class="mb-0 mt-2">';
        foreach ($summary['diagnostics'] as $message) {
            echo '<li><code>' . htmlescape((string) $message) . '</code></li>';
        }
        echo '</ul><div class="mt-2"><a href="diagnostics.php">Abrir diagnóstico técnico completo</a></div></div>';
    }

    Dashboard::renderPage($summary);
    echo '</div>';

    echo <<<'HTML'
<script>
document.addEventListener('DOMContentLoaded', function () {
  if (window.bootstrap && bootstrap.Tooltip) {
    document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function (el) {
      try { new bootstrap.Tooltip(el, {container: 'body'}); } catch (e) {}
    });
  }

  var buttons = document.querySelectorAll('#iosindicators-tabs button[data-bs-toggle="tab"]');
  var preferred = window.location.hash ? window.location.hash.substring(1) : localStorage.getItem('iosindicators.activeTab');
  if (preferred && window.bootstrap && bootstrap.Tab) {
    var target = document.querySelector('#iosindicators-tabs button[data-bs-target="#' + preferred + '"]');
    if (target) {
      try { bootstrap.Tab.getOrCreateInstance(target).show(); } catch (e) {}
    }
  }

  buttons.forEach(function (btn) {
    btn.addEventListener('shown.bs.tab', function (event) {
      var pane = event.target.getAttribute('data-bs-target');
      if (!pane) return;
      var tabId = pane.replace('#', '');
      localStorage.setItem('iosindicators.activeTab', tabId);
      if (history.replaceState) {
        history.replaceState(null, '', '#' + tabId);
      }
    });
  });
});
</script>
HTML;
} catch (Throwable $e) {
    echo '<div class="container-fluid py-3">';
    echo '<div class="alert alert-danger"><strong>Erro no IOS Indicators</strong><br>';
    echo 'O plugin interceptou o erro para evitar a tela genérica do GLPI.';
    if (Session::haveRight('config', UPDATE)) {
        echo '<hr><code>' . htmlescape($e->getMessage()) . '</code>';
        echo '<div class="small mt-2">Arquivo: ' . htmlescape($e->getFile()) . ':' . (int) $e->getLine() . '</div>';
        echo '<div class="mt-2"><a class="btn btn-sm btn-outline-danger" href="diagnostics.php">Abrir diagnóstico técnico</a></div>';
    }
    echo '</div></div>';
}

Html::footer();
