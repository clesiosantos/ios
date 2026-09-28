<?php

namespace GlpiPlugin\Iosindicators;

use CommonDBTM;
use Html;
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

    /**
     * O plugin agrega dados de vários tickets. Para não contornar ACLs de tickets,
     * a versão 0.1 exige READALL.
     */
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
                'metrics' => self::cardsFromSummary($summary),
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
        $title   = htmlescape((string)($params['title'] ?? __('Indicadores Operacionais', 'iosindicators')));
        $period  = htmlescape((string)($params['period'] ?? ''));
        $notice  = htmlescape((string)($params['notice'] ?? ''));

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
        $metrics = self::cardsFromSummary($summary);

        echo '<div class="iosindicators-page">';
        echo self::renderMetricGrid($metrics, false);

        echo '<div class="row g-3 mt-1">';
        echo '<div class="col-xl-6">';
        echo '<div class="card h-100"><div class="card-header"><strong>Qualidade do registro para IA / RCA</strong></div><div class="card-body">';
        echo '<div class="iosindicators-quality">';
        echo '<div><span>Tickets solucionados/fechados no recorte</span><strong>' . (int)$summary['resolved_for_rca_den'] . '</strong></div>';
        echo '<div><span>Com tarefa marcada para IA</span><strong>' . (int)$summary['ai_tagged'] . ' (' . htmlescape((string)$summary['rca_coverage_pct']) . '%)</strong></div>';
        echo '<div><span>RCA com diagnóstico + causa + solução</span><strong>' . (int)$summary['rca_complete'] . ' (' . htmlescape((string)$summary['rca_complete_pct']) . '%)</strong></div>';
        echo '<div><span>Atividades remotas registradas</span><strong>' . (int)$summary['remote_tasks'] . '</strong></div>';
        echo '</div></div></div></div>';

        echo '<div class="col-xl-6">';
        echo '<div class="card h-100"><div class="card-header"><strong>Definição dos tempos</strong></div><div class="card-body">';
        echo '<table class="table table-sm mb-0"><tbody>';
        echo '<tr><td><strong>TTO</strong></td><td>média do <code>takeintoaccount_delay_stat</code></td></tr>';
        echo '<tr><td><strong>TTS</strong></td><td>média do <code>solve_delay_stat</code></td></tr>';
        echo '<tr><td><strong>MTTR</strong></td><td>média do tempo até solução dos tickets resolvidos</td></tr>';
        echo '<tr><td><strong>TMA</strong></td><td>média do <code>actiontime</code> registrado</td></tr>';
        echo '<tr><td><strong>MBTR</strong></td><td>fonte configurável até a nomenclatura/fórmula do projeto ser fechada</td></tr>';
        echo '</tbody></table>';
        echo '</div></div></div>';
        echo '</div>';

        echo '</div>';
    }

    private static function cardsFromSummary(array $s): array
    {
        return [
            ['value' => self::compactNumber((int)$s['total']),    'label' => 'Incidentes',          'icon' => 'ti ti-ticket',              'tone' => 'yellow'],
            ['value' => self::compactNumber((int)$s['incoming']), 'label' => 'Novos',               'icon' => 'ti ti-alert-circle',        'tone' => 'green'],
            ['value' => self::compactNumber((int)$s['pending']),  'label' => 'Pendentes',           'icon' => 'ti ti-player-pause',        'tone' => 'orange'],
            ['value' => self::compactNumber((int)$s['assigned']), 'label' => 'Atribuídos',          'icon' => 'ti ti-users',               'tone' => 'cyan'],
            ['value' => self::compactNumber((int)$s['planned']),  'label' => 'Planejados',          'icon' => 'ti ti-calendar-event',      'tone' => 'blue'],
            ['value' => self::compactNumber((int)$s['solved']),   'label' => 'Solucionados',        'icon' => 'ti ti-checkbox',            'tone' => 'gray'],
            ['value' => self::compactNumber((int)$s['closed']),   'label' => 'Fechados',            'icon' => 'ti ti-archive',             'tone' => 'light'],
            ['value' => Metrics::duration($s['avg_tto']),         'label' => 'TTO médio',            'icon' => 'ti ti-user-check',          'tone' => 'indigo'],
            ['value' => Metrics::duration($s['avg_tts']),         'label' => 'TTS médio',            'icon' => 'ti ti-clock-check',         'tone' => 'teal'],
            ['value' => Metrics::duration($s['avg_mttr']),        'label' => 'MTTR',                 'icon' => 'ti ti-tool',                'tone' => 'purple'],
            ['value' => Metrics::duration($s['avg_tma']),         'label' => 'TMA',                  'icon' => 'ti ti-hourglass',           'tone' => 'pink'],
            ['value' => Metrics::duration($s['avg_total_incident']),'label' => 'Tempo total médio',    'icon' => 'ti ti-clock-hour-4',        'tone' => 'orange'],
            ['value' => Metrics::duration($s['avg_waiting']),     'label' => 'Espera média',          'icon' => 'ti ti-hourglass-empty',     'tone' => 'light'],
            ['value' => Metrics::duration($s['mbtr']),            'label' => 'MBTR',                 'icon' => 'ti ti-arrows-exchange',     'tone' => 'slate'],
            ['value' => number_format((float)$s['rca_complete_pct'], 1, ',', '.') . '%', 'label' => 'RCA completa', 'icon' => 'ti ti-report-analytics', 'tone' => 'success'],
            ['value' => self::compactNumber((int)$s['remote_tasks']), 'label' => 'Ações remotas',     'icon' => 'ti ti-terminal-2',           'tone' => 'dark'],
        ];
    }

    private static function renderMetricGrid(array $metrics, bool $compact): string
    {
        $class = $compact ? 'iosindicators-grid iosindicators-grid-compact' : 'iosindicators-grid';
        $html = "<div class='{$class}'>";

        foreach ($metrics as $metric) {
            $value = htmlescape((string)$metric['value']);
            $label = htmlescape((string)$metric['label']);
            $icon  = htmlescape((string)$metric['icon']);
            $tone  = htmlescape((string)$metric['tone']);

            $html .= "<div class='iosindicators-kpi iosindicators-tone-{$tone}'>";
            $html .= "<div class='iosindicators-kpi-icon'><i class='{$icon}'></i></div>";
            $html .= "<div class='iosindicators-kpi-value'>{$value}</div>";
            $html .= "<div class='iosindicators-kpi-label'>{$label}</div>";
            $html .= '</div>';
        }

        $html .= '</div>';
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
        return (string)$value;
    }
}
