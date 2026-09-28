<?php

namespace GlpiPlugin\Iosindicators;

use CommonDBTM;
use Session;
use Ticket;

final class Dashboard extends CommonDBTM
{
    public static $rightname = 'ticket';

    public static function getTypeName($nb = 0): string
    {
        return __('Indicadores de Incidentes', 'iosindicators');
    }

    public static function getMenuName(): string
    {
        return __('Indicadores de Incidentes', 'iosindicators');
    }

    public static function getMenuContent()
    {
        global $CFG_GLPI;

        $page = $CFG_GLPI['root_doc'] . '/plugins/iosindicators/front/dashboard.php';
        return [
            'title' => self::getMenuName(),
            'page'  => $page,
            'icon'  => 'ti ti-chart-histogram',
            'links' => [
                'search' => $page,
            ],
        ];
    }

    public static function canView(): bool
    {
        return Session::haveRight('ticket', Ticket::READALL);
    }

    public static function dashboardTypes(): array
    {
        return [
            'iosindicators_summary' => [
                'label'    => __('IOS - Indicadores de Incidentes', 'iosindicators'),
                'function' => self::class . '::summaryWidget',
                'image'    => '',
            ],
        ];
    }

    public static function dashboardCards($cards = []): array
    {
        if ($cards === null) {
            $cards = [];
        }

        $newCards = [
            'plugin_iosindicators_summary' => [
                'widgettype' => ['iosindicators_summary'],
                'label'      => __('Indicadores Operacionais - Incidentes', 'iosindicators'),
                'provider'   => self::class . '::summaryProvider',
            ],
        ];

        return array_merge($cards, $newCards);
    }

    public static function summaryProvider(array $params = []): array
    {
        if (!self::canView()) {
            return [
                'title'   => __('Indicadores Operacionais', 'iosindicators'),
                'metrics' => [],
                'notice'  => __('Sem permissão para consolidar tickets.', 'iosindicators'),
            ];
        }

        try {
            [$from, $to] = Metrics::periodFromRequest([]);
            $summary = Metrics::summary($from, $to);

            return [
                'title'   => __('Indicadores Operacionais', 'iosindicators'),
                'metrics' => self::cardsForWidget($summary),
                'period'  => sprintf('%s → %s', $from->format('d/m/Y'), $to->format('d/m/Y')),
                'notice'  => !empty($summary['diagnostics']) ? __('Alguns indicadores apresentaram avisos. Abra o painel do plugin para detalhes.', 'iosindicators') : '',
            ];
        } catch (\Throwable $e) {
            return [
                'title'   => __('Indicadores Operacionais', 'iosindicators'),
                'metrics' => [],
                'notice'  => __('Não foi possível carregar os indicadores do plugin.', 'iosindicators'),
            ];
        }
    }

    public static function summaryWidget(array $params = []): string
    {
        $metrics = $params['metrics'] ?? [];
        $title   = htmlescape((string) ($params['title'] ?? __('Indicadores Operacionais', 'iosindicators')));
        $period  = htmlescape((string) ($params['period'] ?? ''));
        $notice  = htmlescape((string) ($params['notice'] ?? ''));

        $html = "<div class='card iosindicators-native-card'>";
        $html .= "<div class='card-header d-flex align-items-center justify-content-between'>";
        $html .= "<strong>{$title}</strong>";
        if ($period !== '') {
            $html .= "<span class='text-muted small'>{$period}</span>";
        }
        $html .= '</div><div class="card-body">';

        if ($notice !== '') {
            $html .= "<div class='alert alert-warning mb-0'>{$notice}</div>";
        } else {
            $html .= self::renderMetricGrid($metrics, true);
        }

        $html .= '</div></div>';
        return $html;
    }

    public static function renderPage(array $summary): void
    {
        $headline = self::headlineCards($summary);
        $performance = self::performanceCards($summary);
        $quality = self::qualityCards($summary);

        echo '<div class="iosindicators-page">';

        echo '<div class="iosindicators-section-title">';
        echo '<h3>Visão executiva</h3>';
        echo '<p>Volumes, normalização dos tickets e saúde operacional do ambiente monitorado.</p>';
        echo '</div>';
        echo self::renderMetricGrid($headline, false, 'iosindicators-grid-headline');

        echo '<div class="iosindicators-section-title mt-4">';
        echo '<h3>Velocidade operacional</h3>';
        echo '<p>Tempos médios, percentis e confiabilidade calculados a partir dos tickets classificados.</p>';
        echo '</div>';
        echo self::renderMetricGrid($performance, false, 'iosindicators-grid-performance');

        echo '<div class="iosindicators-section-title mt-4">';
        echo '<h3>Qualidade do dado</h3>';
        echo '<p>Cobertura estrutural para apoiar análise, RCA e futura automação inteligente.</p>';
        echo '</div>';
        echo self::renderMetricGrid($quality, false, 'iosindicators-grid-quality');

        echo '<div class="row g-3 mt-2">';

        echo '<div class="col-12 col-xxl-8">';
        echo '<div class="row g-3">';
        echo '<div class="col-xl-6">' . self::renderDistributionCard('Eventos mais frequentes', 'Top eventos detectados no período.', Metrics::top($summary['event_counts'], 6), 'ti ti-bolt') . '</div>';
        echo '<div class="col-xl-6">' . self::renderDistributionCard('Clientes com mais incidentes', 'Clientes com maior volume de tickets no recorte.', Metrics::top($summary['client_counts'], 6), 'ti ti-building-community') . '</div>';
        echo '<div class="col-xl-6">' . self::renderDistributionCard('Hosts reincidentes', 'Hosts com maior recorrência no período.', Metrics::top($summary['host_counts'], 6), 'ti ti-server-2') . '</div>';
        echo '<div class="col-xl-6">' . self::renderDistributionCard('Distribuição por tipo de ativo', 'Leitura com base no código do equipamento identificado no host.', Metrics::top($summary['equipment_counts'], 6), 'ti ti-devices') . '</div>';
        echo '</div>';
        echo '</div>';

        echo '<div class="col-12 col-xxl-4">';
        echo '<div class="row g-3">';
        echo '<div class="col-12">' . self::renderDistributionCard('Pipeline de status', 'Situação atual dos tickets incluídos no filtro.', Metrics::top($summary['status_counts'], 6), 'ti ti-git-branch') . '</div>';
        echo '<div class="col-12">' . self::renderDistributionCard('Severidade monitorada', 'Severidade extraída do corpo do ticket.', Metrics::top($summary['severity_counts'], 6), 'ti ti-alert-triangle') . '</div>';
        echo '<div class="col-12">' . self::renderDefinitionCard() . '</div>';
        echo '</div>';
        echo '</div>';

        echo '</div>';
        echo '</div>';
    }

    private static function cardsForWidget(array $s): array
    {
        return [
            ['value' => self::compactNumber((int) $s['total']), 'label' => 'Incidentes', 'icon' => 'ti ti-ticket', 'tone' => 'yellow'],
            ['value' => self::compactNumber((int) $s['open']), 'label' => 'Em tratamento', 'icon' => 'ti ti-loader-2', 'tone' => 'cyan'],
            ['value' => Metrics::duration($s['avg_mttr']), 'label' => 'MTTR', 'icon' => 'ti ti-tool', 'tone' => 'purple'],
            ['value' => Metrics::duration($s['mtbf']), 'label' => 'MTBF', 'icon' => 'ti ti-activity', 'tone' => 'blue'],
            ['value' => Metrics::percent($s['coverage_structured_pct']), 'label' => 'Cobertura estruturada', 'icon' => 'ti ti-cpu', 'tone' => 'success'],
            ['value' => Metrics::percent($s['availability_pct'], 2), 'label' => 'Disponibilidade', 'icon' => 'ti ti-shield-check', 'tone' => 'green'],
        ];
    }

    private static function headlineCards(array $s): array
    {
        return [
            ['value' => self::compactNumber((int) $s['total']), 'label' => 'Incidentes no período', 'icon' => 'ti ti-ticket', 'tone' => 'yellow', 'meta' => 'Base consolidada do painel'],
            ['value' => self::compactNumber((int) $s['open']), 'label' => 'Em tratamento', 'icon' => 'ti ti-loader-2', 'tone' => 'cyan', 'meta' => self::compactNumber((int) $s['incoming']) . ' novos e ' . self::compactNumber((int) $s['assigned']) . ' atribuídos'],
            ['value' => self::compactNumber((int) ($s['solved'] + $s['closed'])), 'label' => 'Normalizados', 'icon' => 'ti ti-checkbox', 'tone' => 'success', 'meta' => self::compactNumber((int) $s['closed']) . ' fechados no recorte'],
            ['value' => Metrics::percent($s['coverage_structured_pct']), 'label' => 'Cobertura estruturada', 'icon' => 'ti ti-database-check', 'tone' => 'indigo', 'meta' => self::compactNumber((int) $s['structured_tickets']) . ' tickets com categoria + item + requester + NOC'],
            ['value' => Metrics::percent($s['availability_pct'], 2), 'label' => 'Disponibilidade estimada', 'icon' => 'ti ti-shield-check', 'tone' => 'green', 'meta' => 'Cálculo: MTBF / (MTBF + MTTR)'],
            ['value' => self::compactNumber((int) $s['recurrent_hosts']), 'label' => 'Hosts reincidentes', 'icon' => 'ti ti-repeat', 'tone' => 'orange', 'meta' => Metrics::percent($s['recurrent_ticket_pct']) . ' dos tickets em hosts com 2+ ocorrências'],
        ];
    }

    private static function performanceCards(array $s): array
    {
        return [
            ['value' => Metrics::duration($s['avg_tto']), 'label' => 'TTO médio', 'icon' => 'ti ti-user-check', 'tone' => 'indigo', 'meta' => 'Tempo até assumir o ticket'],
            ['value' => Metrics::duration($s['avg_mttr']), 'label' => 'MTTR', 'icon' => 'ti ti-tool', 'tone' => 'purple', 'meta' => 'Tempo médio de reparo'],
            ['value' => Metrics::duration($s['p50_mttr']), 'label' => 'P50 solução', 'icon' => 'ti ti-clock-hour-4', 'tone' => 'teal', 'meta' => 'Mediana do tempo até solução'],
            ['value' => Metrics::duration($s['p90_mttr']), 'label' => 'P90 solução', 'icon' => 'ti ti-chart-line', 'tone' => 'teal', 'meta' => '90% dos tickets resolvidos abaixo deste tempo'],
            ['value' => Metrics::duration($s['p95_mttr']), 'label' => 'P95 solução', 'icon' => 'ti ti-chart-line', 'tone' => 'teal', 'meta' => '95% dos tickets resolvidos abaixo deste tempo'],
            ['value' => Metrics::duration($s['mtbf']), 'label' => 'MTBF', 'icon' => 'ti ti-activity', 'tone' => 'blue', 'meta' => 'Tempo médio entre falhas por host'],
            ['value' => Metrics::duration($s['mtbr']), 'label' => 'MTBR', 'icon' => 'ti ti-arrows-exchange', 'tone' => 'slate', 'meta' => 'Tempo médio entre reparos concluídos'],
            ['value' => Metrics::duration($s['avg_waiting']), 'label' => 'Espera média', 'icon' => 'ti ti-hourglass-empty', 'tone' => 'light', 'meta' => 'Tempo médio em status pendente'],
            ['value' => Metrics::duration($s['avg_tma']), 'label' => 'TMA', 'icon' => 'ti ti-clock-hour-4', 'tone' => 'pink', 'meta' => 'Tempo médio de atuação registrada'],
        ];
    }

    private static function qualityCards(array $s): array
    {
        return [
            ['value' => Metrics::percent($s['coverage_category_pct']), 'label' => 'Categoria preenchida', 'icon' => 'ti ti-category', 'tone' => 'gray', 'meta' => self::compactNumber((int) $s['with_categories']) . ' tickets classificados'],
            ['value' => Metrics::percent($s['coverage_item_pct']), 'label' => 'Ativo vinculado', 'icon' => 'ti ti-devices', 'tone' => 'gray', 'meta' => self::compactNumber((int) $s['with_items']) . ' tickets com item'],
            ['value' => Metrics::percent($s['coverage_requester_pct']), 'label' => 'Requester preenchido', 'icon' => 'ti ti-user-circle', 'tone' => 'gray', 'meta' => self::compactNumber((int) $s['with_requester_groups']) . ' tickets com grupo cliente'],
            ['value' => Metrics::percent($s['coverage_assign_pct']), 'label' => 'Assigned to preenchido', 'icon' => 'ti ti-users-group', 'tone' => 'gray', 'meta' => self::compactNumber((int) $s['with_assign_groups']) . ' tickets com grupo NOC'],
            ['value' => Metrics::percent($s['rca_coverage_pct']), 'label' => 'Tickets marcados para IA/RCA', 'icon' => 'ti ti-brain', 'tone' => 'success', 'meta' => self::compactNumber((int) $s['ai_tagged']) . ' tickets etiquetados'],
            ['value' => Metrics::percent($s['rca_complete_pct']), 'label' => 'RCA completa', 'icon' => 'ti ti-report-search', 'tone' => 'success', 'meta' => self::compactNumber((int) $s['rca_complete']) . ' tickets com diagnóstico + causa + solução'],
        ];
    }

    private static function renderMetricGrid(array $metrics, bool $compact, string $extraClass = ''): string
    {
        $class = $compact ? 'iosindicators-grid iosindicators-grid-compact' : 'iosindicators-grid';
        if ($extraClass !== '') {
            $class .= ' ' . $extraClass;
        }

        $html = "<div class='{$class}'>";
        foreach ($metrics as $metric) {
            $value = htmlescape((string) ($metric['value'] ?? '—'));
            $label = htmlescape((string) ($metric['label'] ?? ''));
            $icon  = htmlescape((string) ($metric['icon'] ?? 'ti ti-chart-bar'));
            $tone  = htmlescape((string) ($metric['tone'] ?? 'light'));
            $meta  = htmlescape((string) ($metric['meta'] ?? ''));

            $html .= "<div class='iosindicators-kpi iosindicators-tone-{$tone}'>";
            $html .= "<div class='iosindicators-kpi-icon'><i class='{$icon}'></i></div>";
            $html .= "<div class='iosindicators-kpi-value'>{$value}</div>";
            $html .= "<div class='iosindicators-kpi-label'>{$label}</div>";
            if ($meta !== '') {
                $html .= "<div class='iosindicators-kpi-meta'>{$meta}</div>";
            }
            $html .= '</div>';
        }
        $html .= '</div>';
        return $html;
    }

    private static function renderDistributionCard(string $title, string $subtitle, array $rows, string $icon): string
    {
        $html = '<div class="card iosindicators-panel h-100">';
        $html .= '<div class="card-header">';
        $html .= '<div class="iosindicators-panel-title"><i class="' . htmlescape($icon) . '"></i><div><strong>' . htmlescape($title) . '</strong><div class="text-muted small">' . htmlescape($subtitle) . '</div></div></div>';
        $html .= '</div><div class="card-body">';

        if ($rows === []) {
            $html .= '<div class="text-muted">Sem dados suficientes no recorte.</div>';
        } else {
            foreach ($rows as $row) {
                $label = htmlescape((string) ($row['label'] ?? ''));
                $count = (int) ($row['count'] ?? 0);
                $percent = max(0, min(100, (float) ($row['percent'] ?? 0)));
                $html .= '<div class="iosindicators-bar-row">';
                $html .= '<div class="iosindicators-bar-head"><span class="iosindicators-bar-label">' . $label . '</span><span class="iosindicators-bar-value">' . $count . ' <small>(' . number_format($percent, 1, ',', '.') . '%)</small></span></div>';
                $html .= '<div class="progress iosindicators-progress"><div class="progress-bar" role="progressbar" style="width: ' . $percent . '%"></div></div>';
                $html .= '</div>';
            }
        }

        $html .= '</div></div>';
        return $html;
    }

    private static function renderDefinitionCard(): string
    {
        $html = '<div class="card iosindicators-panel h-100">';
        $html .= '<div class="card-header"><div class="iosindicators-panel-title"><i class="ti ti-book-2"></i><div><strong>Leitura dos indicadores</strong><div class="text-muted small">Definições operacionais adotadas pelo painel.</div></div></div></div>';
        $html .= '<div class="card-body">';
        $html .= '<table class="table table-sm mb-0 iosindicators-definitions"><tbody>';
        $rows = [
            ['TTO', 'Tempo médio até o ticket ser assumido.'],
            ['MTTR', 'Tempo médio para reparar/restabelecer o serviço.'],
            ['MTBF', 'Tempo médio entre a normalização de um host e a abertura da próxima falha do mesmo host.'],
            ['MTBR', 'Tempo médio entre reparos concluídos do mesmo host.'],
            ['Disponibilidade', 'Estimativa: MTBF ÷ (MTBF + MTTR).'],
            ['Cobertura estruturada', 'Tickets com categoria, item, requester e assigned to preenchidos.'],
        ];
        foreach ($rows as [$name, $desc]) {
            $html .= '<tr><td class="fw-bold">' . htmlescape($name) . '</td><td>' . htmlescape($desc) . '</td></tr>';
        }
        $html .= '</tbody></table>';
        $html .= '</div></div>';
        return $html;
    }

    private static function compactNumber(int $value): string
    {
        if ($value >= 1000000) {
            return rtrim(rtrim(number_format($value / 1000000, 1, '.', ''), '0'), '.') . 'M';
        }
        if ($value >= 1000) {
            return rtrim(rtrim(number_format($value / 1000, 1, '.', ''), '0'), '.') . 'K';
        }
        return (string) $value;
    }
}
