<?php

use GlpiPlugin\Iosindicators\Dashboard;
use GlpiPlugin\Iosindicators\EfficiencyMetrics;
use GlpiPlugin\Iosindicators\Metrics;
use GlpiPlugin\Iosindicators\Settings;

include(__DIR__ . '/../../../inc/includes.php');
Plugin::load('iosindicators');

if (!Dashboard::canView()) {
    Html::displayErrorAndDie(__('Você precisa da permissão "Ver todos os tickets" para acessar os indicadores consolidados.', 'iosindicators'));
}

Html::header(
    __('Eficiência potencial IA', 'iosindicators'),
    '/plugins/iosindicators/front/efficiency.php',
    'plugins',
    Dashboard::class,
    ''
);

try {
    [$from, $to] = Metrics::periodFromRequest($_GET);
    $summary = EfficiencyMetrics::summary($from, $to);
    $agentName = (string) Settings::get('ai_agent_name', 'IOS NORA');

    echo '<div class="container-fluid py-3" style="max-width:1400px">';
    echo '<div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-3">';
    echo '<div><div class="text-primary fw-bold text-uppercase small">IOS Indicators</div><h2 class="mb-1">Eficiência potencial com ' . htmlescape($agentName) . '</h2><div class="text-muted">Comparação entre ActionTime histórico abertura→solução e esforço estimado pela IA/RCA.</div></div>';
    echo '<form method="get" action="/plugins/iosindicators/front/efficiency.php" class="d-flex flex-wrap gap-2 align-items-end">';
    $currentDays = isset($_GET['days']) ? (int)$_GET['days'] : 30;
    echo '<div><label class="form-label mb-1">Período</label><select class="form-select" name="days">';
    foreach ([7 => '7 dias', 30 => '30 dias', 90 => '90 dias', 365 => '1 ano'] as $days => $label) {
        echo '<option value="' . $days . '"' . ($currentDays === $days ? ' selected' : '') . '>' . htmlescape($label) . '</option>';
    }
    echo '</select></div><button class="btn btn-warning" type="submit"><i class="ti ti-filter"></i> Aplicar</button></form>';
    echo '</div>';

    echo '<div class="alert alert-info"><strong>Leitura correta:</strong> o baseline histórico é o tempo entre a abertura e a <strong>solução</strong> do ticket, registrado em uma task própria com ActionTime. A estimativa da IA/RCA permanece separada e não é somada ao ActionTime do ticket. O período desta página usa a <strong>data da solução</strong>.</div>';

    // Faixa de diagnóstico rápido da base utilizada.
    echo '<div class="card shadow-sm mb-3"><div class="card-body py-3">';
    echo '<div class="row g-3 text-center">';
    $baseStats = [
        ['label' => 'Solucionados/fechados no período', 'value' => (int)($summary['resolved_tickets'] ?? $summary['closed_tickets'] ?? 0), 'class' => 'text-primary'],
        ['label' => 'Com ActionTime histórico', 'value' => (int)($summary['cycle_tickets'] ?? 0), 'class' => 'text-warning'],
        ['label' => 'Com IA/RCA', 'value' => (int)($summary['ai_tickets'] ?? 0), 'class' => 'text-info'],
        ['label' => 'Comparáveis', 'value' => (int)($summary['compared_tickets'] ?? 0), 'class' => 'text-success'],
    ];
    foreach ($baseStats as $stat) {
        echo '<div class="col-6 col-lg-3"><div class="small text-muted text-uppercase fw-bold">' . htmlescape($stat['label']) . '</div><div class="fs-3 fw-bold ' . htmlescape($stat['class']) . '">' . number_format($stat['value'], 0, ',', '.') . '</div></div>';
    }
    echo '</div></div></div>';

    $cards = [
        ['label' => 'Tickets comparados', 'value' => number_format((int)$summary['compared_tickets'], 0, ',', '.'), 'icon' => 'ti ti-arrows-exchange'],
        ['label' => 'ActionTime histórico médio', 'value' => Metrics::duration($summary['avg_cycle_seconds']), 'icon' => 'ti ti-clock'],
        ['label' => 'TMA estimado ' . $agentName, 'value' => Metrics::duration($summary['avg_ai_seconds']), 'icon' => 'ti ti-brain'],
        ['label' => 'Eficiência potencial IA', 'value' => $summary['potential_efficiency_pct'] === null ? '—' : number_format((float)$summary['potential_efficiency_pct'], 1, ',', '.') . '%', 'icon' => 'ti ti-bolt'],
        ['label' => 'Economia média potencial', 'value' => Metrics::duration($summary['avg_saved_seconds']), 'icon' => 'ti ti-hourglass-low'],
        ['label' => 'Cobertura comparável', 'value' => number_format((float)$summary['coverage_pct'], 1, ',', '.') . '%', 'icon' => 'ti ti-chart-dots'],
    ];

    echo '<div class="row g-3 mb-4">';
    foreach ($cards as $card) {
        echo '<div class="col-12 col-md-6 col-xl-4"><div class="card h-100 shadow-sm"><div class="card-body">';
        echo '<div class="d-flex align-items-center justify-content-between"><div class="text-muted small text-uppercase fw-bold">' . htmlescape($card['label']) . '</div><i class="' . htmlescape($card['icon']) . ' fs-2 text-warning"></i></div>';
        echo '<div class="display-6 fw-bold mt-3">' . htmlescape((string)$card['value']) . '</div>';
        echo '</div></div></div>';
    }
    echo '</div>';

    echo '<div class="card shadow-sm"><div class="card-header"><strong>Histórico × IA/RCA por ticket</strong></div><div class="card-body p-0">';
    if ($summary['rows'] === []) {
        echo '<div class="p-4 text-muted">Ainda não há tickets com os dois lados da comparação no mesmo chamado. Veja o diagnóstico abaixo para identificar se está faltando ActionTime histórico, IA/RCA ou apenas a correlação entre eles.</div>';
    } else {
        echo '<div class="table-responsive"><table class="table table-hover align-middle mb-0"><thead><tr><th>Ticket</th><th>Título</th><th>Abertura</th><th>Solução</th><th>ActionTime histórico</th><th>TMA IA</th><th>Diferença</th><th>Eficiência potencial</th></tr></thead><tbody>';
        foreach ($summary['rows'] as $row) {
            $pct = (float)$row['efficiency_pct'];
            $badge = $pct >= 50 ? 'bg-success' : ($pct >= 0 ? 'bg-warning text-dark' : 'bg-danger');
            $ticketUrl = Ticket::getFormURLWithID((int)$row['ticket_id']);
            echo '<tr>';
            echo '<td><a href="' . htmlescape($ticketUrl) . '">#' . (int)$row['ticket_id'] . '</a></td>';
            echo '<td style="max-width:420px">' . htmlescape((string)$row['name']) . '</td>';
            echo '<td class="text-nowrap">' . (!empty($row['opened_at']) ? htmlescape(date('d/m/Y H:i', strtotime((string)$row['opened_at']))) : '—') . '</td>';
            echo '<td class="text-nowrap">' . (!empty($row['solved_at']) ? htmlescape(date('d/m/Y H:i', strtotime((string)$row['solved_at']))) : '—') . '</td>';
            echo '<td>' . htmlescape(Metrics::duration((float)$row['cycle_seconds'])) . '</td>';
            echo '<td>' . htmlescape(Metrics::duration((float)$row['ai_seconds'])) . '</td>';
            echo '<td>' . htmlescape(Metrics::duration((float)$row['saved_seconds'])) . '</td>';
            echo '<td><span class="badge ' . $badge . '">' . number_format($pct, 1, ',', '.') . '%</span></td>';
            echo '</tr>';
        }
        echo '</tbody></table></div>';
    }
    echo '</div></div>';

    if (!empty($summary['diagnostics'])) {
        echo '<div class="alert alert-warning mt-3"><strong>Diagnóstico da base</strong><ul class="mb-0 mt-2">';
        foreach ($summary['diagnostics'] as $message) {
            echo '<li>' . htmlescape((string)$message) . '</li>';
        }
        echo '</ul></div>';
    }

    echo '</div>';
} catch (Throwable $e) {
    echo '<div class="container py-3"><div class="alert alert-danger">Falha ao calcular eficiência: ' . htmlescape($e->getMessage()) . '</div></div>';
}

Html::footer();
