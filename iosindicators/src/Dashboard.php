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

    public static function renderPage(array $summary, array $efficiency = []): void
    {
        echo '<div class="iosindicators-shell">';
        echo self::renderTabsNavigation();
        echo '<div class="tab-content iosindicators-tab-content" id="iosindicators-tab-content">';

        echo '<div class="tab-pane fade show active" id="tab-executiva" role="tabpanel" aria-labelledby="tab-executiva-btn">';
        echo self::renderExecutiveTab($summary);
        echo '</div>';

        echo '<div class="tab-pane fade" id="tab-velocidade" role="tabpanel" aria-labelledby="tab-velocidade-btn">';
        echo self::renderVelocityTab($summary);
        echo '</div>';

        echo '<div class="tab-pane fade" id="tab-qualidade" role="tabpanel" aria-labelledby="tab-qualidade-btn">';
        echo self::renderQualityTab($summary);
        echo '</div>';

        echo '<div class="tab-pane fade" id="tab-eficiencia" role="tabpanel" aria-labelledby="tab-eficiencia-btn">';
        echo self::renderEfficiencyTab($efficiency);
        echo '</div>';

        echo '<div class="tab-pane fade" id="tab-tempo-real" role="tabpanel" aria-labelledby="tab-tempo-real-btn">';
        echo self::renderRealtimeTab($summary);
        echo '</div>';

        echo '</div>';
        echo '</div>';
    }

    private static function renderTabsNavigation(): string
    {
        $tabs = [
            ['id' => 'tab-executiva', 'label' => 'Visão executiva', 'icon' => 'ti ti-layout-dashboard'],
            ['id' => 'tab-velocidade', 'label' => 'Velocidade operacional', 'icon' => 'ti ti-bolt'],
            ['id' => 'tab-qualidade', 'label' => 'Qualidade do dado', 'icon' => 'ti ti-shield-check'],
            ['id' => 'tab-eficiencia', 'label' => 'Eficiência IA', 'icon' => 'ti ti-sparkles'],
            ['id' => 'tab-tempo-real', 'label' => 'Tempo Real', 'icon' => 'ti ti-activity-heartbeat'],
        ];

        $html = '<div class="iosindicators-tabs card mb-4"><div class="card-body p-2">';
        $html .= '<ul class="nav nav-pills nav-fill gap-2" id="iosindicators-tabs" role="tablist">';

        foreach ($tabs as $index => $tab) {
            $active = $index === 0 ? ' active' : '';
            $selected = $index === 0 ? 'true' : 'false';
            $html .= '<li class="nav-item" role="presentation">';
            $html .= '<button class="nav-link iosindicators-tab-btn' . $active . '" id="' . htmlescape($tab['id']) . '-btn" data-bs-toggle="tab" data-bs-target="#' . htmlescape($tab['id']) . '" type="button" role="tab" aria-controls="' . htmlescape($tab['id']) . '" aria-selected="' . $selected . '">';
            $html .= '<i class="' . htmlescape($tab['icon']) . '"></i><span>' . htmlescape($tab['label']) . '</span>';
            $html .= '</button></li>';
        }

        $html .= '</ul></div></div>';
        return $html;
    }

    private static function renderExecutiveTab(array $s): string
    {
        $html = self::renderHero(
            'Visão executiva',
            'Volumes, normalização dos tickets e saúde operacional do ambiente monitorado.',
            [
                ['label' => 'Período', 'value' => date('d/m/Y', strtotime((string) $s['period_from'])) . ' → ' . date('d/m/Y', strtotime((string) $s['period_to']))],
                ['label' => 'Cobertura estruturada', 'value' => Metrics::percent($s['coverage_structured_pct'])],
                ['label' => 'Disponibilidade', 'value' => Metrics::percent($s['availability_pct'], 2)],
            ]
        );

        $html .= self::renderMetricGrid(self::headlineCards($s), false, 'iosindicators-grid-headline');

        $html .= '<div class="row g-3 mt-1">';
        $html .= '<div class="col-12 col-xl-6">' . self::renderDistributionCard('Eventos mais frequentes', 'Top eventos detectados no período.', Metrics::top($s['event_counts'], 6), 'ti ti-bolt', 'Concentração dos tipos de incidente capturados no recorte selecionado.') . '</div>';
        $html .= '<div class="col-12 col-xl-6">' . self::renderDistributionCard('Clientes com mais incidentes', 'Clientes com maior volume de tickets no recorte.', Metrics::top($s['client_counts'], 6), 'ti ti-building-community', 'Identifica concentração de incidentes por cliente para apoiar priorização.') . '</div>';
        $html .= '<div class="col-12 col-xl-6">' . self::renderDistributionCard('Hosts reincidentes', 'Hosts com maior recorrência no período.', Metrics::top($s['host_counts'], 6), 'ti ti-server-2', 'Lista os hosts com maior reincidência de incidentes, não apenas os que estão abertos.') . '</div>';
        $html .= '<div class="col-12 col-xl-6">' . self::renderDistributionCard('Distribuição por tipo de ativo', 'Leitura com base no código do equipamento identificado no host.', Metrics::top($s['equipment_counts'], 6), 'ti ti-devices', 'Tipificação inferida do host: SRV, SW, DB, WEB, FW e demais padrões configurados.') . '</div>';
        $html .= '</div>';

        return $html;
    }

    private static function renderVelocityTab(array $s): string
    {
        $html = self::renderHero(
            'Velocidade operacional',
            'Tempos médios, percentis e confiabilidade calculados a partir dos tickets classificados.',
            [
                ['label' => 'TTO médio', 'value' => Metrics::duration($s['avg_tto'])],
                ['label' => 'MTTR', 'value' => Metrics::duration($s['avg_mttr'])],
                ['label' => 'MTBF', 'value' => Metrics::duration($s['mtbf'])],
            ]
        );

        $html .= self::renderMetricGrid(self::performanceCards($s), false, 'iosindicators-grid-performance');
        $html .= '<div class="row g-3 mt-1">';
        $html .= '<div class="col-12 col-xl-6">' . self::renderDistributionCard('Pipeline de status', 'Situação atual dos tickets incluídos no filtro.', Metrics::top($s['status_counts'], 6), 'ti ti-git-branch', 'Distribuição do estoque de tickets por status dentro do período selecionado.') . '</div>';
        $html .= '<div class="col-12 col-xl-6">' . self::renderDistributionCard('Severidade monitorada', 'Severidade extraída do corpo do ticket.', Metrics::top($s['severity_counts'], 6), 'ti ti-alert-triangle', 'Severidade extraída das informações do ticket originado pelo monitoramento.') . '</div>';
        $html .= '</div>';
        return $html;
    }

    private static function renderQualityTab(array $s): string
    {
        $html = self::renderHero(
            'Qualidade do dado',
            'Cobertura estrutural para apoiar análise, RCA e futura automação inteligente.',
            [
                ['label' => 'Categorias', 'value' => Metrics::percent($s['coverage_category_pct'])],
                ['label' => 'Ativos', 'value' => Metrics::percent($s['coverage_item_pct'])],
                ['label' => 'Requester + NOC', 'value' => Metrics::percent($s['coverage_structured_pct'])],
            ]
        );

        $html .= self::renderMetricGrid(self::qualityCards($s), false, 'iosindicators-grid-quality');
        $html .= '<div class="row g-3 mt-1">';
        $html .= '<div class="col-12 col-xl-7">' . self::renderQualityMatrixCard($s) . '</div>';
        $html .= '<div class="col-12 col-xl-5">' . self::renderDefinitionCard() . '</div>';
        $html .= '</div>';
        return $html;
    }

    private static function renderEfficiencyTab(array $e): string
    {
        $agentName = trim((string) Settings::get('ai_agent_name', 'IOS NORA'));
        if ($agentName === '') {
            $agentName = 'IOS NORA';
        }

        $efficiencyValue = $e['potential_efficiency_pct'] ?? null;
        $coverage = (float) ($e['coverage_pct'] ?? 0.0);
        $compared = (int) ($e['compared_tickets'] ?? 0);

        $html = self::renderHero(
            'Eficiência potencial com ' . $agentName,
            'Comparação entre o ActionTime histórico abertura→solução e o esforço estimado pela IA/RCA. Esta aba usa a data da solução para o recorte.',
            [
                ['label' => 'Comparáveis', 'value' => self::compactNumber($compared)],
                ['label' => 'Cobertura', 'value' => Metrics::percent($coverage)],
                ['label' => 'Eficiência potencial', 'value' => $efficiencyValue === null ? '—' : Metrics::percent((float) $efficiencyValue)],
            ]
        );

        $html .= self::renderMetricGrid(self::efficiencyCards($e, $agentName), false, 'iosindicators-grid-performance');

        $html .= '<div class="row g-3 mt-1">';
        $html .= '<div class="col-12 col-xxl-8">' . self::renderEfficiencyComparisonCard($e['rows'] ?? [], $agentName) . '</div>';
        $html .= '<div class="col-12 col-xxl-4">' . self::renderEfficiencyDiagnosticsCard($e) . '</div>';
        $html .= '</div>';

        return $html;
    }

    private static function renderRealtimeTab(array $s): string
    {
        $html = self::renderHero(
            'Tempo Real',
            'Leitura operacional focada nos tickets que ainda estão em tratamento no momento do recorte.',
            [
                ['label' => 'Abertos agora', 'value' => self::compactNumber((int) $s['open'])],
                ['label' => 'Clientes impactados', 'value' => self::compactNumber((int) $s['open_clients'])],
                ['label' => 'Hosts impactados', 'value' => self::compactNumber((int) $s['open_hosts'])],
            ]
        );

        $html .= self::renderMetricGrid(self::realtimeCards($s), false, 'iosindicators-grid-realtime');
        $html .= '<div class="row g-3 mt-1">';
        $html .= '<div class="col-12 col-xxl-7">' . self::renderLatestTicketsCard($s['latest_tickets'] ?? []) . '</div>';
        $html .= '<div class="col-12 col-xxl-5">';
        $html .= '<div class="row g-3">';
        $html .= '<div class="col-12">' . self::renderDistributionCard('Pipeline aberto', 'Tickets ainda em tratamento.', Metrics::top($s['open_status_counts'], 6), 'ti ti-layers-linked', 'Recorte apenas dos tickets ainda não solucionados ou fechados.') . '</div>';
        $html .= '<div class="col-12">' . self::renderDistributionCard('Clientes impactados agora', 'Clientes com tickets abertos no momento.', Metrics::top($s['open_client_counts'], 6), 'ti ti-users-group', 'Concentração dos tickets abertos por cliente.') . '</div>';
        $html .= '</div></div>';
        $html .= '<div class="col-12 col-xl-6">' . self::renderDistributionCard('Hosts impactados agora', 'Hosts com tickets abertos no momento.', Metrics::top($s['open_host_counts'], 6), 'ti ti-server', 'Hosts com ocorrências ainda ativas no recorte.') . '</div>';
        $html .= '<div class="col-12 col-xl-6">' . self::renderDistributionCard('Severidade ativa', 'Severidade dos tickets ainda abertos.', Metrics::top($s['open_severity_counts'], 6), 'ti ti-radar-2', 'Severidades extraídas dos tickets que seguem em tratamento.') . '</div>';
        $html .= '</div>';
        return $html;
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
            [
                'value' => self::compactNumber((int) $s['total']),
                'label' => 'Incidentes no período',
                'icon' => 'ti ti-ticket',
                'tone' => 'yellow',
                'meta' => 'Base consolidada do painel',
                'tooltip' => 'Total de tickets de incidente incluídos no recorte selecionado.',
            ],
            [
                'value' => self::compactNumber((int) $s['open']),
                'label' => 'Em tratamento',
                'icon' => 'ti ti-loader-2',
                'tone' => 'cyan',
                'meta' => self::compactNumber((int) $s['incoming']) . ' novos e ' . self::compactNumber((int) $s['assigned']) . ' atribuídos',
                'tooltip' => 'Tickets ainda não solucionados/fechados no período selecionado.',
            ],
            [
                'value' => self::compactNumber((int) ($s['solved'] + $s['closed'])),
                'label' => 'Normalizados',
                'icon' => 'ti ti-checkbox',
                'tone' => 'success',
                'meta' => self::compactNumber((int) $s['closed']) . ' fechados no recorte',
                'tooltip' => 'Tickets solucionados ou fechados dentro do recorte.',
            ],
            [
                'value' => Metrics::percent($s['coverage_structured_pct']),
                'label' => 'Cobertura estruturada',
                'icon' => 'ti ti-cpu',
                'tone' => 'indigo',
                'meta' => self::compactNumber((int) $s['structured_tickets']) . ' tickets com categoria + item + requester + NOC',
                'tooltip' => 'Percentual de tickets já classificados com estrutura mínima para análise operacional.',
            ],
            [
                'value' => Metrics::percent($s['availability_pct'], 2),
                'label' => 'Disponibilidade estimada',
                'icon' => 'ti ti-shield-check',
                'tone' => 'green',
                'meta' => 'Cálculo: MTBF / (MTBF + MTTR)',
                'tooltip' => 'Estimativa simplificada de disponibilidade com base na confiabilidade e no tempo médio de reparo.',
            ],
            [
                'value' => self::compactNumber((int) $s['recurrent_hosts']),
                'label' => 'Hosts reincidentes',
                'icon' => 'ti ti-repeat',
                'tone' => 'orange',
                'meta' => Metrics::percent($s['recurrent_ticket_pct']) . ' dos tickets em hosts com 2+ ocorrências',
                'tooltip' => 'Hosts que tiveram duas ou mais ocorrências dentro do recorte.',
            ],
        ];
    }

    private static function performanceCards(array $s): array
    {
        return [
            ['value' => Metrics::duration($s['avg_tto']), 'label' => 'TTO médio', 'icon' => 'ti ti-user-check', 'tone' => 'indigo', 'meta' => 'Tempo até assumir o ticket', 'tooltip' => 'Tempo médio até que o ticket seja assumido/tratado.'],
            ['value' => Metrics::duration($s['avg_mttr']), 'label' => 'MTTR', 'icon' => 'ti ti-tool', 'tone' => 'purple', 'meta' => 'Tempo médio de reparo', 'tooltip' => 'Tempo médio até restaurar o serviço após a falha.'],
            ['value' => Metrics::duration($s['p50_mttr']), 'label' => 'P50 solução', 'icon' => 'ti ti-clock-hour-4', 'tone' => 'teal', 'meta' => 'Mediana do tempo até solução', 'tooltip' => '50% dos tickets resolvidos ficaram abaixo deste tempo.'],
            ['value' => Metrics::duration($s['p90_mttr']), 'label' => 'P90 solução', 'icon' => 'ti ti-chart-line', 'tone' => 'teal', 'meta' => '90% dos tickets resolvidos abaixo deste tempo', 'tooltip' => 'Faixa de desempenho operacional nos casos mais demorados.'],
            ['value' => Metrics::duration($s['p95_mttr']), 'label' => 'P95 solução', 'icon' => 'ti ti-chart-line', 'tone' => 'teal', 'meta' => '95% dos tickets resolvidos abaixo deste tempo', 'tooltip' => 'Destaca a cauda longa dos tickets resolvidos.'],
            ['value' => Metrics::duration($s['mtbf']), 'label' => 'MTBF', 'icon' => 'ti ti-activity', 'tone' => 'blue', 'meta' => 'Tempo médio entre falhas por host', 'tooltip' => 'Tempo médio entre o fim de uma ocorrência e a próxima falha do mesmo host.'],
            ['value' => Metrics::duration($s['mtbr']), 'label' => 'MTBR', 'icon' => 'ti ti-arrows-exchange', 'tone' => 'slate', 'meta' => 'Tempo médio entre reparos concluídos', 'tooltip' => 'Intervalo médio entre reparos sucessivos do mesmo host.'],
            ['value' => Metrics::duration($s['avg_waiting']), 'label' => 'Espera média', 'icon' => 'ti ti-hourglass-empty', 'tone' => 'light', 'meta' => 'Tempo médio em status pendente', 'tooltip' => 'Mede quanto tempo os tickets permanecem aguardando.'],
            ['value' => Metrics::duration($s['avg_tma']), 'label' => 'TMA', 'icon' => 'ti ti-clock-hour-4', 'tone' => 'pink', 'meta' => 'Tempo médio de atuação registrada', 'tooltip' => 'Tempo médio de trabalho/atuação lançado no ticket.'],
        ];
    }

    private static function efficiencyCards(array $e, string $agentName): array
    {
        $efficiency = $e['potential_efficiency_pct'] ?? null;
        return [
            ['value' => self::compactNumber((int) ($e['compared_tickets'] ?? 0)), 'label' => 'Tickets comparados', 'icon' => 'ti ti-arrows-exchange', 'tone' => 'indigo', 'meta' => 'Histórico e IA/RCA no mesmo ticket', 'tooltip' => 'Tickets que possuem ActionTime histórico abertura→solução e estimativa IA/RCA.'],
            ['value' => Metrics::duration($e['avg_cycle_seconds'] ?? null), 'label' => 'ActionTime histórico médio', 'icon' => 'ti ti-clock', 'tone' => 'orange', 'meta' => 'Abertura → solução', 'tooltip' => 'Tempo histórico médio entre abertura e solução nos tickets comparáveis.'],
            ['value' => Metrics::duration($e['avg_ai_seconds'] ?? null), 'label' => 'TMA estimado ' . $agentName, 'icon' => 'ti ti-brain', 'tone' => 'purple', 'meta' => 'Esforço técnico estimado pela IA', 'tooltip' => 'Tempo médio que a IA estima para diagnosticar, atuar e validar os tickets comparáveis.'],
            ['value' => $efficiency === null ? '—' : Metrics::percent((float) $efficiency), 'label' => 'Eficiência potencial IA', 'icon' => 'ti ti-bolt', 'tone' => 'success', 'meta' => 'Redução potencial vs. histórico', 'tooltip' => 'Percentual potencial de redução entre o tempo histórico e o tempo estimado pela IA.'],
            ['value' => Metrics::duration($e['avg_saved_seconds'] ?? null), 'label' => 'Economia média potencial', 'icon' => 'ti ti-hourglass-low', 'tone' => 'green', 'meta' => 'Diferença média Histórico − IA', 'tooltip' => 'Tempo médio potencialmente economizado por ticket comparável.'],
            ['value' => Metrics::percent((float) ($e['coverage_pct'] ?? 0.0)), 'label' => 'Cobertura comparável', 'icon' => 'ti ti-chart-dots', 'tone' => 'cyan', 'meta' => self::compactNumber((int) ($e['resolved_tickets'] ?? 0)) . ' tickets solucionados/fechados na base', 'tooltip' => 'Percentual da base resolvida que já possui os dois lados necessários para comparação.'],
        ];
    }

    private static function qualityCards(array $s): array
    {
        return [
            ['value' => Metrics::percent($s['coverage_category_pct']), 'label' => 'Categoria preenchida', 'icon' => 'ti ti-category', 'tone' => 'gray', 'meta' => self::compactNumber((int) $s['with_categories']) . ' tickets classificados', 'tooltip' => 'Percentual de tickets com categoria definida.'],
            ['value' => Metrics::percent($s['coverage_item_pct']), 'label' => 'Ativo vinculado', 'icon' => 'ti ti-devices', 'tone' => 'gray', 'meta' => self::compactNumber((int) $s['with_items']) . ' tickets com item', 'tooltip' => 'Percentual de tickets que possuem ativo/item associado.'],
            ['value' => Metrics::percent($s['coverage_requester_pct']), 'label' => 'Requester preenchido', 'icon' => 'ti ti-user-circle', 'tone' => 'gray', 'meta' => self::compactNumber((int) $s['with_requester_groups']) . ' tickets com grupo cliente', 'tooltip' => 'Percentual de tickets com grupo solicitante preenchido.'],
            ['value' => Metrics::percent($s['coverage_assign_pct']), 'label' => 'Assigned to preenchido', 'icon' => 'ti ti-users-group', 'tone' => 'gray', 'meta' => self::compactNumber((int) $s['with_assign_groups']) . ' tickets com grupo NOC', 'tooltip' => 'Percentual de tickets com grupo de atendimento / NOC vinculado.'],
            ['value' => Metrics::percent($s['rca_coverage_pct']), 'label' => 'Tickets marcados para IA/RCA', 'icon' => 'ti ti-brain', 'tone' => 'success', 'meta' => self::compactNumber((int) $s['ai_tagged']) . ' tickets etiquetados', 'tooltip' => 'Cobertura de follow-ups etiquetados para análise, IA ou RCA.'],
            ['value' => Metrics::percent($s['rca_complete_pct']), 'label' => 'RCA completa', 'icon' => 'ti ti-report-search', 'tone' => 'success', 'meta' => self::compactNumber((int) $s['rca_complete']) . ' tickets com diagnóstico + causa + solução', 'tooltip' => 'Tickets com registro mínimo estruturado de RCA.'],
        ];
    }

    private static function realtimeCards(array $s): array
    {
        return [
            ['value' => self::compactNumber((int) $s['open']), 'label' => 'Abertos agora', 'icon' => 'ti ti-loader-2', 'tone' => 'cyan', 'meta' => 'Estoque operacional atual no recorte', 'tooltip' => 'Tickets abertos, atribuídos, planejados ou pendentes.'],
            ['value' => self::compactNumber((int) $s['incoming']), 'label' => 'Novos', 'icon' => 'ti ti-bell-ringing', 'tone' => 'yellow', 'meta' => 'Aguardando início de tratativa', 'tooltip' => 'Tickets recém-criados, ainda não assumidos.'],
            ['value' => self::compactNumber((int) $s['assigned']), 'label' => 'Atribuídos', 'icon' => 'ti ti-user-check', 'tone' => 'indigo', 'meta' => 'Já direcionados para atendimento', 'tooltip' => 'Tickets atualmente atribuídos a grupos ou equipes.'],
            ['value' => self::compactNumber((int) $s['pending']), 'label' => 'Pendentes', 'icon' => 'ti ti-pause', 'tone' => 'orange', 'meta' => 'Em espera/aguardando ação', 'tooltip' => 'Tickets em situação pendente ou aguardando insumo externo.'],
            ['value' => self::compactNumber((int) $s['open_clients']), 'label' => 'Clientes impactados', 'icon' => 'ti ti-users-group', 'tone' => 'blue', 'meta' => 'Clientes com incidente aberto', 'tooltip' => 'Número de clientes distintos com ao menos um ticket aberto no recorte.'],
            ['value' => self::compactNumber((int) $s['open_hosts']), 'label' => 'Hosts impactados', 'icon' => 'ti ti-server-2', 'tone' => 'purple', 'meta' => 'Ativos/hosts com incidente aberto', 'tooltip' => 'Número de hosts distintos com ao menos um ticket aberto.'],
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
            $tooltip = htmlescape((string) ($metric['tooltip'] ?? ''));

            $html .= "<div class='iosindicators-kpi iosindicators-tone-{$tone}'>";
            $html .= "<div class='iosindicators-kpi-top'>";
            $html .= "<div class='iosindicators-kpi-icon'><i class='{$icon}'></i></div>";
            if ($tooltip !== '') {
                $html .= "<button type='button' class='iosindicators-info-btn' data-bs-toggle='tooltip' data-bs-placement='top' title='{$tooltip}' aria-label='Informações'><i class='ti ti-info-circle'></i></button>";
            }
            $html .= '</div>';
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

    private static function renderDistributionCard(string $title, string $subtitle, array $rows, string $icon, string $tooltip = ''): string
    {
        $html = '<div class="card iosindicators-panel h-100">';
        $html .= '<div class="card-header">';
        $html .= '<div class="iosindicators-panel-title">';
        $html .= '<i class="' . htmlescape($icon) . '"></i>';
        $html .= '<div class="flex-grow-1"><strong>' . htmlescape($title) . '</strong><div class="text-muted small">' . htmlescape($subtitle) . '</div></div>';
        if ($tooltip !== '') {
            $html .= '<button type="button" class="iosindicators-info-btn is-light" data-bs-toggle="tooltip" data-bs-placement="top" title="' . htmlescape($tooltip) . '"><i class="ti ti-info-circle"></i></button>';
        }
        $html .= '</div></div><div class="card-body">';

        if ($rows === []) {
            $html .= '<div class="iosindicators-empty-state"><i class="ti ti-database-off"></i><span>Sem dados suficientes no recorte.</span></div>';
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

    private static function renderEfficiencyComparisonCard(array $rows, string $agentName): string
    {
        $html = '<div class="card iosindicators-panel h-100">';
        $html .= '<div class="card-header"><div class="iosindicators-panel-title"><i class="ti ti-arrows-diff"></i><div><strong>Histórico × ' . htmlescape($agentName) . ' por ticket</strong><div class="text-muted small">Comparação dos tickets com os dois lados da análise disponíveis.</div></div></div></div>';
        $html .= '<div class="card-body p-0">';

        if ($rows === []) {
            $html .= '<div class="iosindicators-empty-state p-4"><i class="ti ti-chart-dots-3"></i><span>Ainda não há tickets comparáveis neste recorte. Processe ActionTime histórico e IA/RCA nos mesmos chamados.</span></div>';
        } else {
            $html .= '<div class="table-responsive"><table class="table table-hover iosindicators-table mb-0">';
            $html .= '<thead><tr><th>Ticket</th><th>Histórico</th><th>' . htmlescape($agentName) . '</th><th>Economia</th><th>Eficiência</th></tr></thead><tbody>';
            foreach (array_slice($rows, 0, 25) as $row) {
                $ticketId = (int) ($row['ticket_id'] ?? 0);
                $pct = (float) ($row['efficiency_pct'] ?? 0.0);
                $badge = $pct >= 50 ? 'text-bg-success' : ($pct >= 0 ? 'text-bg-warning' : 'text-bg-danger');
                $saved = (float) ($row['saved_seconds'] ?? 0);
                $html .= '<tr>';
                $html .= '<td><a class="fw-semibold" href="' . htmlescape(Ticket::getFormURLWithID($ticketId)) . '">#' . $ticketId . '</a><div class="small text-muted text-truncate" style="max-width:300px">' . htmlescape((string) ($row['name'] ?? '')) . '</div></td>';
                $html .= '<td class="text-nowrap">' . htmlescape(Metrics::duration((float) ($row['cycle_seconds'] ?? 0))) . '</td>';
                $html .= '<td class="text-nowrap">' . htmlescape(Metrics::duration((float) ($row['ai_seconds'] ?? 0))) . '</td>';
                $html .= '<td class="text-nowrap">' . ($saved >= 0 ? '' : '−') . htmlescape(Metrics::duration(abs($saved))) . '</td>';
                $html .= '<td><span class="badge ' . $badge . '">' . number_format($pct, 1, ',', '.') . '%</span></td>';
                $html .= '</tr>';
            }
            $html .= '</tbody></table></div>';
        }

        $html .= '</div></div>';
        return $html;
    }

    private static function renderEfficiencyDiagnosticsCard(array $e): string
    {
        $resolved = (int) ($e['resolved_tickets'] ?? 0);
        $historical = (int) ($e['cycle_tickets'] ?? 0);
        $ai = (int) ($e['ai_tickets'] ?? 0);
        $compared = (int) ($e['compared_tickets'] ?? 0);

        $html = '<div class="card iosindicators-panel h-100">';
        $html .= '<div class="card-header"><div class="iosindicators-panel-title"><i class="ti ti-chart-donut-3"></i><div><strong>Cobertura da comparação</strong><div class="text-muted small">Diagnóstico dos dados necessários para a eficiência.</div></div></div></div>';
        $html .= '<div class="card-body">';

        $items = [
            ['Solucionados/fechados', $resolved, $resolved > 0 ? 100.0 : 0.0],
            ['Com ActionTime histórico', $historical, $resolved > 0 ? ($historical / $resolved) * 100 : 0.0],
            ['Com IA/RCA', $ai, $resolved > 0 ? ($ai / $resolved) * 100 : 0.0],
            ['Comparáveis', $compared, $resolved > 0 ? ($compared / $resolved) * 100 : 0.0],
        ];

        foreach ($items as [$label, $count, $percent]) {
            $percent = max(0, min(100, (float) $percent));
            $html .= '<div class="iosindicators-completeness-row">';
            $html .= '<div class="iosindicators-completeness-head"><span>' . htmlescape((string) $label) . '</span><span>' . self::compactNumber((int) $count) . '</span></div>';
            $html .= '<div class="progress iosindicators-progress lg"><div class="progress-bar" role="progressbar" style="width:' . $percent . '%"></div></div>';
            $html .= '<div class="iosindicators-completeness-meta">' . number_format($percent, 1, ',', '.') . '% da base resolvida</div>';
            $html .= '</div>';
        }

        if (!empty($e['diagnostics'])) {
            $html .= '<div class="alert alert-light border mt-3 mb-0 small"><strong>Leitura:</strong><ul class="mb-0 mt-1 ps-3">';
            foreach (array_slice((array) $e['diagnostics'], 0, 4) as $message) {
                $html .= '<li>' . htmlescape((string) $message) . '</li>';
            }
            $html .= '</ul></div>';
        }

        $html .= '</div></div>';
        return $html;
    }

    private static function renderQualityMatrixCard(array $s): string
    {
        $rows = [
            ['Categoria', $s['coverage_category_pct'], $s['with_categories'] . ' tickets'],
            ['Ativo', $s['coverage_item_pct'], $s['with_items'] . ' tickets'],
            ['Requester', $s['coverage_requester_pct'], $s['with_requester_groups'] . ' tickets'],
            ['Assigned/NOC', $s['coverage_assign_pct'], $s['with_assign_groups'] . ' tickets'],
            ['Estruturado', $s['coverage_structured_pct'], $s['structured_tickets'] . ' tickets'],
            ['IA/RCA', $s['rca_coverage_pct'], $s['ai_tagged'] . ' tickets'],
        ];

        $html = '<div class="card iosindicators-panel h-100">';
        $html .= '<div class="card-header"><div class="iosindicators-panel-title"><i class="ti ti-checklist"></i><div><strong>Matriz de completude</strong><div class="text-muted small">Evolução desejada para saneamento dos tickets.</div></div></div></div>';
        $html .= '<div class="card-body">';
        foreach ($rows as [$label, $value, $meta]) {
            $percent = max(0, min(100, (float) $value));
            $html .= '<div class="iosindicators-completeness-row">';
            $html .= '<div class="iosindicators-completeness-head"><span>' . htmlescape((string) $label) . '</span><span>' . Metrics::percent((float) $value) . '</span></div>';
            $html .= '<div class="progress iosindicators-progress lg"><div class="progress-bar" role="progressbar" style="width: ' . $percent . '%"></div></div>';
            $html .= '<div class="iosindicators-completeness-meta">' . htmlescape((string) $meta) . '</div>';
            $html .= '</div>';
        }
        $html .= '</div></div>';
        return $html;
    }

    private static function renderLatestTicketsCard(array $rows): string
    {
        $html = '<div class="card iosindicators-panel h-100">';
        $html .= '<div class="card-header"><div class="iosindicators-panel-title"><i class="ti ti-clock-hour-4"></i><div><strong>Últimos tickets classificados</strong><div class="text-muted small">Monitoramento das entradas mais recentes no GLPI.</div></div></div></div>';
        $html .= '<div class="card-body p-0">';

        if ($rows === []) {
            $html .= '<div class="iosindicators-empty-state p-4"><i class="ti ti-ticket-off"></i><span>Sem tickets para exibir.</span></div>';
        } else {
            $html .= '<div class="table-responsive"><table class="table table-hover iosindicators-table mb-0">';
            $html .= '<thead><tr><th>#</th><th>Data</th><th>Cliente</th><th>Host</th><th>Evento</th><th>Status</th></tr></thead><tbody>';
            foreach ($rows as $row) {
                $html .= '<tr>';
                $html .= '<td><span class="fw-semibold">' . (int) ($row['id'] ?? 0) . '</span></td>';
                $html .= '<td>' . htmlescape((string) ($row['date'] ?? '—')) . '</td>';
                $html .= '<td>' . htmlescape((string) ($row['client'] ?? '—')) . '</td>';
                $html .= '<td><span class="text-nowrap">' . htmlescape((string) ($row['host'] ?? '—')) . '</span></td>';
                $html .= '<td><div class="fw-medium">' . htmlescape((string) ($row['event'] ?? '—')) . '</div><div class="small text-muted">' . htmlescape((string) ($row['title'] ?? '')) . '</div></td>';
                $html .= '<td><span class="badge text-bg-' . htmlescape((string) ($row['status_class'] ?? 'light')) . '">' . htmlescape((string) ($row['status'] ?? '—')) . '</span></td>';
                $html .= '</tr>';
            }
            $html .= '</tbody></table></div>';
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
            ['MTBF', 'Tempo médio entre o fim de uma ocorrência e a abertura da próxima falha do mesmo host.'],
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

    private static function renderHero(string $title, string $subtitle, array $chips): string
    {
        $html = '<div class="iosindicators-hero card mb-4"><div class="card-body">';
        $html .= '<div class="d-flex flex-column flex-xl-row gap-3 align-items-xl-center justify-content-between">';
        $html .= '<div><div class="iosindicators-hero-eyebrow">IOS Indicators</div><h3 class="mb-2">' . htmlescape($title) . '</h3><p class="mb-0 text-muted">' . htmlescape($subtitle) . '</p></div>';
        $html .= '<div class="iosindicators-chip-group">';
        foreach ($chips as $chip) {
            $html .= '<div class="iosindicators-chip"><span class="iosindicators-chip-label">' . htmlescape((string) $chip['label']) . '</span><strong>' . htmlescape((string) $chip['value']) . '</strong></div>';
        }
        $html .= '</div></div></div></div>';
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
