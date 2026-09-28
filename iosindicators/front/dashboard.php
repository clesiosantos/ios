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
echo '<link rel="stylesheet" href="../css/iosindicators-modern.css?v=' . urlencode((string) PLUGIN_IOSINDICATORS_VERSION) . '">';

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

  var liveTabLabel = document.querySelector('#tab-tempo-real-btn span');
  if (liveTabLabel && !liveTabLabel.querySelector('.live-pulse')) {
    var pulse = document.createElement('span');
    pulse.className = 'live-pulse';
    liveTabLabel.prepend(pulse);
  }

  function cardLabel(card) {
    var el = card ? card.querySelector('.iosindicators-kpi-label') : null;
    return el ? el.textContent.trim() : '';
  }

  function findCard(root, label) {
    if (!root) return null;
    return Array.from(root.querySelectorAll('.iosindicators-kpi')).find(function (card) {
      return cardLabel(card).toLowerCase() === label.toLowerCase();
    }) || null;
  }

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

  function escapeHtml(value) {
    return String(value || '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  function rankingTable(panel, rows, itemLabel) {
    if (!panel || !rows.length) return;
    var body = panel.querySelector('.card-body');
    if (!body) return;

    var html = '<div class="table-responsive"><table class="ios-ranking-table">';
    html += '<thead><tr><th style="width:42px">#</th><th>' + escapeHtml(itemLabel) + '</th><th class="text-end">Incidentes</th><th class="text-end">Participação</th></tr></thead><tbody>';
    rows.forEach(function (row, index) {
      html += '<tr title="' + escapeHtml(row.label + ': ' + row.count + ' incidentes') + '">';
      html += '<td><span class="ios-rank-pos">' + (index + 1) + '</span></td>';
      html += '<td><span class="ios-rank-name">' + escapeHtml(row.label) + '</span></td>';
      html += '<td class="ios-rank-count">' + row.count + '</td>';
      html += '<td class="ios-rank-share">' + row.percent.toFixed(1).replace('.', ',') + '%</td>';
      html += '</tr>';
    });
    html += '</tbody></table></div>';
    body.innerHTML = html;
  }

  function injectDonut(panel, rows, colors, centerLabel) {
    if (!panel || !rows.length) return;
    var body = panel.querySelector('.card-body');
    if (!body) return;
    var total = rows.reduce(function (sum, row) { return sum + row.count; }, 0);
    if (!total) return;

    var cursor = 0;
    var gradients = [];
    rows.forEach(function (row, index) {
      var pct = (row.count / total) * 100;
      var next = cursor + pct;
      gradients.push(colors[index % colors.length] + ' ' + cursor.toFixed(2) + '% ' + next.toFixed(2) + '%');
      cursor = next;
    });

    var legend = rows.map(function (row, index) {
      var pct = total ? ((row.count / total) * 100) : 0;
      return '<div class="ios-donut-legend-row" title="' + escapeHtml(row.label + ': ' + row.count) + '">' +
        '<span class="ios-donut-dot" style="background:' + colors[index % colors.length] + '"></span>' +
        '<span class="ios-donut-legend-label">' + escapeHtml(row.label) + '</span>' +
        '<span class="ios-donut-legend-value">' + row.count + ' · ' + pct.toFixed(1).replace('.', ',') + '%</span>' +
      '</div>';
    }).join('');

    body.innerHTML =
      '<div class="ios-donut-layout">' +
        '<div class="ios-donut-wrap">' +
          '<div class="ios-donut" style="background:conic-gradient(' + gradients.join(',') + ')"></div>' +
          '<div class="ios-donut-center"><strong>' + total + '</strong><span>' + escapeHtml(centerLabel) + '</span></div>' +
        '</div>' +
        '<div class="ios-donut-legend">' + legend + '</div>' +
      '</div>';
  }

  function createDonutPanel(title, subtitle, rows, colors, centerLabel, iconClass, extraClass) {
    if (!rows || !rows.length) return null;
    var col = document.createElement('div');
    col.className = extraClass || '';
    col.innerHTML =
      '<div class="card iosindicators-panel h-100">' +
        '<div class="card-header"><div class="iosindicators-panel-title">' +
          '<i class="' + escapeHtml(iconClass || 'ti ti-chart-donut-3') + '"></i>' +
          '<div><strong>' + escapeHtml(title) + '</strong><div class="text-muted small">' + escapeHtml(subtitle) + '</div></div>' +
        '</div></div>' +
        '<div class="card-body"></div>' +
      '</div>';
    injectDonut(col.querySelector('.iosindicators-panel'), rows, colors, centerLabel);
    return col;
  }

  function buildPresentationKpis(executive, velocity) {
    var executiveGrid = executive ? executive.querySelector('.iosindicators-grid-headline') : null;
    if (!executiveGrid) return;

    var grid = document.createElement('div');
    grid.className = 'ios-presentation-kpi-grid';

    var order = [
      ['Incidentes no período', executive],
      ['Normalizados', executive],
      ['Em tratamento', executive],
      ['MTTR', velocity],
      ['TTO médio', velocity],
      ['MTBF', velocity],
      ['Cobertura estruturada', executive],
      ['Disponibilidade estimada', executive],
      ['Hosts reincidentes', executive],
      ['P90 solução', velocity]
    ];

    order.forEach(function (item) {
      var card = findCard(item[1], item[0]);
      if (card) grid.appendChild(card.cloneNode(true));
    });

    executiveGrid.replaceWith(grid);
  }

  function modernizeExecutive() {
    var executive = document.getElementById('tab-executiva');
    var velocity = document.getElementById('tab-velocidade');
    if (!executive || executive.dataset.presentationReady === '1') return;
    executive.dataset.presentationReady = '1';

    buildPresentationKpis(executive, velocity);

    var originalBoard = executive.querySelector('.row.g-3.mt-1');
    if (!originalBoard) return;
    originalBoard.classList.remove('row', 'g-3', 'mt-1');
    originalBoard.classList.add('ios-presentation-panels-grid');

    Array.from(originalBoard.children).forEach(function (column) {
      var panel = column.querySelector('.iosindicators-panel');
      var title = panelTitle(panel);
      column.className = '';

      if (title.indexOf('Eventos mais frequentes') !== -1) {
        column.classList.add('ios-panel-events');
      } else if (title.indexOf('Clientes com mais incidentes') !== -1) {
        column.classList.add('ios-panel-clients');
        injectDonut(panel, parseRows(panel), ['#2563eb','#10b981','#f59e0b','#7c3aed','#0ea5e9','#64748b'], 'tickets');
      } else if (title.indexOf('Hosts reincidentes') !== -1) {
        column.classList.add('ios-panel-hosts');
        rankingTable(panel, parseRows(panel), 'Host');
      } else if (title.indexOf('Distribuição por tipo de ativo') !== -1) {
        column.classList.add('ios-panel-assets');
      }
    });

    if (velocity) {
      var velocityPanels = Array.from(velocity.querySelectorAll('.iosindicators-panel'));
      var statusPanel = velocityPanels.find(function (panel) { return panelTitle(panel).indexOf('Pipeline de status') !== -1; });
      var severityPanel = velocityPanels.find(function (panel) { return panelTitle(panel).indexOf('Severidade monitorada') !== -1; });

      var status = createDonutPanel(
        'Panorama dos tickets',
        'Distribuição consolidada por status no período.',
        parseRows(statusPanel),
        ['#2563eb','#10b981','#64748b','#f59e0b','#7c3aed','#0ea5e9'],
        'tickets',
        'ti ti-chart-donut-3',
        'ios-panel-status'
      );

      var severity = createDonutPanel(
        'Severidade dos incidentes',
        'Perfil de criticidade dos tickets monitorados.',
        parseRows(severityPanel),
        ['#ef4444','#f59e0b','#2563eb','#10b981','#7c3aed','#64748b'],
        'incidentes',
        'ti ti-alert-triangle',
        'ios-panel-severity'
      );

      if (status) {
        var clients = originalBoard.querySelector('.ios-panel-clients');
        if (clients && clients.nextSibling) originalBoard.insertBefore(status, clients.nextSibling);
        else originalBoard.appendChild(status);
      }
      if (severity) originalBoard.appendChild(severity);
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
