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

/**
 * GLPI 11 pode operar com um webroot que não publica diretamente
 * /plugins/<plugin>/css. Para evitar 404 e garantir que o dashboard
 * sempre receba o design system, os estilos são carregados no servidor
 * e injetados inline nesta página.
 */
$pluginDir = dirname(__DIR__);
$cssFiles = [
    $pluginDir . '/css/iosindicators.css',
    $pluginDir . '/css/iosindicators-modern.css',
];

foreach ($cssFiles as $cssFile) {
    if (is_readable($cssFile)) {
        echo "<style data-iosindicators-css=\"" . htmlescape(basename($cssFile)) . "\">\n";
        echo file_get_contents($cssFile);
        echo "\n</style>\n";
    } elseif (Session::haveRight('config', UPDATE)) {
        echo '<!-- IOS Indicators: CSS não encontrado: ' . htmlescape($cssFile) . ' -->';
    }
}

try {
    [$from, $to] = Metrics::periodFromRequest($_GET);
    $summary = Metrics::summary($from, $to);

    echo '<div class="ios-dashboard-wrapper iosindicators-wrapper">';

    echo '<div class="ios-page-header">';
    echo '<div class="ios-title-group">';
    echo '<h2>Indicadores Operacionais de Incidentes</h2>';
    echo '<div class="ios-pipeline-badge"><i class="ti ti-git-merge"></i><span>Zabbix → GLPI → Tratativa → Normalização → RCA / Base de Conhecimento</span></div>';
    echo '</div>';

    echo '<form method="get" class="ios-filters-bar iosindicators-filter">';
    echo '<div class="ios-filter-group"><label>Período rápido</label><select class="form-select" name="days">';
    $currentDays = isset($_GET['days']) ? (int) $_GET['days'] : 30;
    foreach ([1 => 'Hoje', 7 => '7 dias', 30 => '30 dias', 90 => '90 dias', 365 => '1 ano'] as $days => $label) {
        $selected = ($currentDays === $days && empty($_GET['from'])) ? ' selected' : '';
        echo '<option value="' . $days . '"' . $selected . '>' . htmlescape($label) . '</option>';
    }
    echo '</select></div>';
    echo '<div class="ios-filter-group"><label>De</label><input type="date" class="form-control" name="from" value="' . htmlescape((string) ($_GET['from'] ?? '')) . '"></div>';
    echo '<div class="ios-filter-group"><label>Até</label><input type="date" class="form-control" name="to" value="' . htmlescape((string) ($_GET['to'] ?? '')) . '"></div>';
    echo '<button class="ios-btn-primary" type="submit"><i class="ti ti-filter"></i><span>Aplicar</span></button>';
    echo '</form>';
    echo '</div>';

    echo '<div class="ios-context-strip">';
    echo '<div><i class="ti ti-calendar-stats"></i><span>Período considerado: <strong>' . $from->format('d/m/Y H:i') . '</strong> até <strong>' . $to->format('d/m/Y H:i') . '</strong></span></div>';
    echo '<div class="ios-context-note"><i class="ti ti-info-circle"></i><span>O recorte usa a data de abertura do ticket.</span></div>';
    echo '</div>';

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
  function initTooltips(scope) {
    if (!(window.bootstrap && bootstrap.Tooltip)) return;
    (scope || document).querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function (el) {
      try {
        bootstrap.Tooltip.getOrCreateInstance(el, {container: 'body'});
      } catch (e) {}
    });
  }

  initTooltips(document);

  var buttons = document.querySelectorAll('#iosindicators-tabs button[data-bs-toggle="tab"]');
  var preferred = window.location.hash
    ? window.location.hash.substring(1)
    : localStorage.getItem('iosindicators.activeTab');

  if (preferred && window.bootstrap && bootstrap.Tab) {
    var target = document.querySelector('#iosindicators-tabs button[data-bs-target="#' + preferred + '"]');
    if (target) {
      try {
        bootstrap.Tab.getOrCreateInstance(target).show();
      } catch (e) {}
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

  var liveTabLabel = document.querySelector('#tab-tempo-real-btn span');
  if (liveTabLabel && !liveTabLabel.querySelector('.live-pulse')) {
    var pulse = document.createElement('span');
    pulse.className = 'live-pulse';
    liveTabLabel.prepend(pulse);
  }
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
