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
  function initTooltips(scope) {
    if (!(window.bootstrap && bootstrap.Tooltip)) return;
    (scope || document).querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function (el) {
      try { bootstrap.Tooltip.getOrCreateInstance(el, {container: 'body'}); } catch (e) {}
    });
  }

  initTooltips(document);

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

  function parseRows(panel) {
    if (!panel) return [];
    return Array.from(panel.querySelectorAll('.iosindicators-bar-row')).map(function (row) {
      var labelEl = row.querySelector('.iosindicators-bar-label');
      var valueEl = row.querySelector('.iosindicators-bar-value');
      var label = labelEl ? labelEl.textContent.trim() : '—';
      var raw = valueEl ? valueEl.textContent.trim() : '0';
      var countMatch = raw.match(/([0-9][0-9.]*)/);
      var pctMatch = raw.match(/\(([0-9]+(?:[.,][0-9]+)?)%\)/);
      var count = countMatch ? parseInt(countMatch[1].replace(/\./g, ''), 10) : 0;
      var percent = pctMatch ? parseFloat(pctMatch[1].replace(',', '.')) : 0;
      return {label: label, count: count || 0, percent: percent || 0};
    });
  }

  function panelTitle(panel) {
    var title = panel ? panel.querySelector('.iosindicators-panel-title strong') : null;
    return title ? title.textContent.trim() : '';
  }

  function rankingTable(panel, rows, itemLabel) {
    if (!panel || !rows.length) return;
    var body = panel.querySelector('.card-body');
    if (!body) return;

    var html = '<div class="table-responsive"><table class="ios-ranking-table">';
    html += '<thead><tr><th style="width:42px">#</th><th>' + itemLabel + '</th><th class="text-end">Incidentes</th><th class="text-end">Participação</th></tr></thead><tbody>';
    rows.forEach(function (row, index) {
      html += '<tr title="' + row.label.replace(/"/g, '&quot;') + ': ' + row.count + ' incidentes">';
      html += '<td><span class="ios-rank-pos">' + (index + 1) + '</span></td>';
      html += '<td><span class="ios-rank-name">' + row.label + '</span></td>';
      html += '<td class="ios-rank-count">' + row.count + '</td>';
      html += '<td class="ios-rank-share">' + row.percent.toFixed(1).replace('.', ',') + '%</td>';
      html += '</tr>';
    });
    html += '</tbody></table></div>';
    body.innerHTML = html;
  }

  function buildDonutCard(title, subtitle, rows, colors, centerLabel) {
    if (!rows || !rows.length) return null;
    var total = rows.reduce(function (sum, row) { return sum + row.count; }, 0);
    if (!total) return null;

    var cursor = 0;
    var gradients = [];
    rows.forEach(function (row, index) {
      var pct = (row.count / total) * 100;
      var next = cursor + pct;
      gradients.push(colors[index % colors.length] + ' ' + cursor.toFixed(2) + '% ' + next.toFixed(2) + '%');
      cursor = next;
    });

    var card = document.createElement('div');
    card.className = 'card iosindicators-panel ios-donut-card h-100';
    var legend = rows.map(function (row, index) {
      var pct = total ? ((row.count / total) * 100) : 0;
      return '<div class="ios-donut-legend-row" title="' + row.label.replace(/"/g, '&quot;') + ': ' + row.count + '">' +
        '<span class="ios-donut-dot" style="background:' + colors[index % colors.length] + '"></span>' +
        '<span class="ios-donut-legend-label">' + row.label + '</span>' +
        '<span class="ios-donut-legend-value">' + row.count + ' · ' + pct.toFixed(1).replace('.', ',') + '%</span>' +
      '</div>';
    }).join('');

    card.innerHTML =
      '<div class="card-header"><div class="iosindicators-panel-title">' +
        '<i class="ti ti-chart-donut-3"></i>' +
        '<div><strong>' + title + '</strong><div class="text-muted small">' + subtitle + '</div></div>' +
      '</div></div>' +
      '<div class="card-body">' +
        '<div class="ios-donut-wrap">' +
          '<div class="ios-donut" style="background:conic-gradient(' + gradients.join(',') + ')"></div>' +
          '<div class="ios-donut-center"><strong>' + total + '</strong><span>' + centerLabel + '</span></div>' +
        '</div>' +
        '<div class="ios-donut-legend">' + legend + '</div>' +
      '</div>';

    return card;
  }

  function modernizeExecutive() {
    var executive = document.getElementById('tab-executiva');
    if (!executive || executive.dataset.modernized === '1') return;
    executive.dataset.modernized = '1';

    var board = executive.querySelector('.row.g-3.mt-1');
    if (board) {
      board.classList.add('ios-executive-board');
      Array.from(board.children).forEach(function (column) {
        var panel = column.querySelector('.iosindicators-panel');
        var title = panelTitle(panel);

        if (title.indexOf('Eventos mais frequentes') !== -1) {
          column.classList.add('ios-exec-wide');
        } else if (title.indexOf('Clientes com mais incidentes') !== -1) {
          column.classList.add('ios-exec-narrow');
          rankingTable(panel, parseRows(panel), 'Cliente');
        } else if (title.indexOf('Hosts reincidentes') !== -1) {
          column.classList.add('ios-exec-wide');
          rankingTable(panel, parseRows(panel), 'Host');
        } else if (title.indexOf('Distribuição por tipo de ativo') !== -1) {
          column.classList.add('ios-exec-narrow');
        } else {
          column.classList.add('ios-exec-half');
        }
      });
    }

    var velocity = document.getElementById('tab-velocidade');
    if (!velocity) return;

    var sourcePanels = Array.from(velocity.querySelectorAll('.iosindicators-panel'));
    var statusPanel = sourcePanels.find(function (panel) { return panelTitle(panel).indexOf('Pipeline de status') !== -1; });
    var severityPanel = sourcePanels.find(function (panel) { return panelTitle(panel).indexOf('Severidade monitorada') !== -1; });

    var secondary = document.createElement('div');
    secondary.className = 'ios-exec-secondary-grid';

    var statusDonut = buildDonutCard(
      'Panorama dos tickets',
      'Distribuição consolidada por status no período.',
      parseRows(statusPanel),
      ['#2563eb', '#10b981', '#64748b', '#f59e0b', '#7c3aed', '#0ea5e9'],
      'tickets'
    );

    var severityDonut = buildDonutCard(
      'Severidade dos incidentes',
      'Perfil de criticidade extraído dos tickets monitorados.',
      parseRows(severityPanel),
      ['#ef4444', '#f59e0b', '#2563eb', '#10b981', '#7c3aed', '#64748b'],
      'incidentes'
    );

    if (statusDonut) secondary.appendChild(statusDonut);
    if (severityDonut) secondary.appendChild(severityDonut);
    if (secondary.children.length) {
      if (board && board.parentNode) {
        board.parentNode.insertBefore(secondary, board.nextSibling);
      } else {
        executive.appendChild(secondary);
      }
    }

    initTooltips(executive);
  }

  modernizeExecutive();
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
