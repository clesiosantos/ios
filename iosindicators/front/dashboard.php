<?php

use GlpiPlugin\Iosindicators\Dashboard;
use GlpiPlugin\Iosindicators\EfficiencyMetrics;
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
    $efficiency = EfficiencyMetrics::summary($from, $to);

    /**
     * Normalização de severidade do monitoramento.
     *
     * Alguns tickets antigos trazem o HTML do corpo sem separação entre os
     * campos, gerando valores como:
     *   AverageOperational data: 99 %Original problem ID: 2893
     *
     * Para fins de governança, o dashboard consolida exclusivamente a escala
     * oficial esperada do monitoramento e agrupa qualquer valor ausente ou não
     * reconhecido em "Not classified".
     *
     * Ordem de criticidade utilizada na apresentação:
     * Disaster > High > Average > Warning > Information > Not classified.
     */
    $normalizeSeverityCounts = static function (array $rawCounts, int $baseTotal): array {
        $normalized = [
            'Disaster' => 0,
            'High' => 0,
            'Average' => 0,
            'Warning' => 0,
            'Information' => 0,
            'Not classified' => 0,
        ];

        $recognized = 0;
        foreach ($rawCounts as $rawLabel => $rawCount) {
            $count = max(0, (int) $rawCount);
            $label = trim((string) $rawLabel);
            $target = null;

            foreach (['Disaster', 'High', 'Average', 'Warning', 'Information'] as $severity) {
                if (stripos($label, $severity) === 0) {
                    $target = $severity;
                    break;
                }
            }

            if ($target === null && preg_match('/^Not\s*classified/iu', $label)) {
                $target = 'Not classified';
            }

            if ($target === null) {
                $target = 'Not classified';
            }

            $normalized[$target] += $count;
            $recognized += $count;
        }

        // Tickets que não possuíam campo Severity nunca entravam em severity_counts.
        // Eles também são explicitamente contabilizados como Not classified.
        if ($baseTotal > $recognized) {
            $normalized['Not classified'] += ($baseTotal - $recognized);
        }

        return $normalized;
    };

    $summary['severity_counts'] = $normalizeSeverityCounts(
        (array) ($summary['severity_counts'] ?? []),
        (int) ($summary['total'] ?? 0)
    );
    $summary['open_severity_counts'] = $normalizeSeverityCounts(
        (array) ($summary['open_severity_counts'] ?? []),
        (int) ($summary['open'] ?? 0)
    );
    $summary['active_severities'] = count(array_filter(
        $summary['open_severity_counts'],
        static fn($count): bool => (int) $count > 0
    ));

    /**
     * Disponibilidade estimada
     * ------------------------
     * A versão anterior recebia availability_pct da rotina de confiabilidade,
     * que inferia o MTTR por diferença entre MTBR e MTBF. Isso podia produzir
     * 0,00% mesmo quando havia MTBF e MTTR válidos no painel.
     *
     * Para apresentação operacional, a disponibilidade passa a usar diretamente
     * os dois indicadores já calculados na mesma base do dashboard:
     *
     *   Disponibilidade = MTBF / (MTBF + MTTR) * 100
     *
     * O MTTR é o tempo médio até a solução calculado pelo GLPI para os tickets
     * do recorte. Quando MTBF ou MTTR não estiverem disponíveis, exibimos "—"
     * em vez de 0,00%, evitando interpretar ausência de amostra como indisponibilidade.
     */
    $mtbf = isset($summary['mtbf']) && is_numeric($summary['mtbf'])
        ? (float) $summary['mtbf']
        : null;
    $mttr = isset($summary['avg_mttr']) && is_numeric($summary['avg_mttr'])
        ? (float) $summary['avg_mttr']
        : null;

    if ($mtbf !== null && $mtbf > 0 && $mttr !== null && $mttr >= 0 && ($mtbf + $mttr) > 0) {
        $summary['availability_pct'] = round(($mtbf / ($mtbf + $mttr)) * 100, 2);
    } else {
        $summary['availability_pct'] = null;
        $summary['diagnostics'][] = 'Disponibilidade estimada sem valor: é necessário ter MTBF e MTTR válidos no mesmo recorte. O painel exibirá “—” em vez de 0,00%.';
    }

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
    echo '<div class="ios-context-note"><i class="ti ti-info-circle"></i><span>Abas operacionais usam a data de abertura; <strong>Eficiência IA</strong> usa a data da solução.</span></div>';
    echo '</div>';

    if (!empty($summary['diagnostics']) && Session::haveRight('config', UPDATE)) {
        echo '<div class="alert alert-warning"><strong>Diagnóstico do plugin</strong><ul class="mb-0 mt-2">';
        foreach ($summary['diagnostics'] as $message) {
            echo '<li><code>' . htmlescape((string) $message) . '</code></li>';
        }
        echo '</ul><div class="mt-2"><a href="diagnostics.php">Abrir diagnóstico técnico completo</a></div></div>';
    }

    Dashboard::renderPage($summary, $efficiency);
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

  function severityCard(title) {
    var cards = Array.from(document.querySelectorAll('.iosindicators-panel'));
    return cards.find(function (card) {
      var strong = card.querySelector('.card-header strong');
      return strong && strong.textContent.trim() === title;
    });
  }

  function orderSeverityCard(title) {
    var card = severityCard(title);
    if (!card) return;

    var body = card.querySelector('.card-body');
    if (!body || body.dataset.severityOrdered === '1') return;

    var order = ['Disaster', 'High', 'Average', 'Warning', 'Information', 'Not classified'];
    var colors = {
      'Disaster': '#991b1b',
      'High': '#ef4444',
      'Average': '#f97316',
      'Warning': '#eab308',
      'Information': '#3b82f6',
      'Not classified': '#94a3b8'
    };

    var rows = Array.from(body.querySelectorAll('.iosindicators-bar-row'));
    var byLabel = {};

    rows.forEach(function (row) {
      var label = row.querySelector('.iosindicators-bar-label');
      if (!label) return;
      byLabel[label.textContent.trim()] = row;
    });

    order.forEach(function (severity) {
      var row = byLabel[severity];
      if (!row) return;

      var label = row.querySelector('.iosindicators-bar-label');
      var bar = row.querySelector('.progress-bar');

      if (label && !label.querySelector('.ios-severity-dot')) {
        var dot = document.createElement('span');
        dot.className = 'ios-severity-dot';
        dot.style.display = 'inline-block';
        dot.style.width = '8px';
        dot.style.height = '8px';
        dot.style.borderRadius = '50%';
        dot.style.marginRight = '8px';
        dot.style.verticalAlign = '1px';
        dot.style.backgroundColor = colors[severity];
        label.prepend(dot);
      }

      if (bar) {
        bar.style.backgroundColor = colors[severity];
      }

      body.appendChild(row);
    });

    body.dataset.severityOrdered = '1';
  }

  initTooltips(document);
  orderSeverityCard('Severidade monitorada');
  orderSeverityCard('Severidade ativa');

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
      if (tabId === 'tab-velocidade') {
        orderSeverityCard('Severidade monitorada');
      }
      if (tabId === 'tab-tempo-real') {
        orderSeverityCard('Severidade ativa');
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