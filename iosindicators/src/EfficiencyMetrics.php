<?php

namespace GlpiPlugin\Iosindicators;

use DateTimeInterface;
use Throwable;

final class EfficiencyMetrics
{
    public static function summary(DateTimeInterface $from, DateTimeInterface $to): array
    {
        global $DB;

        $result = [
            // Mantido por compatibilidade com a tela existente. A partir da 0.9.3
            // representa tickets solucionados/fechados no período de solução.
            'closed_tickets' => 0,
            'resolved_tickets' => 0,
            'cycle_tickets' => 0,
            'ai_tickets' => 0,
            'compared_tickets' => 0,
            'avg_cycle_seconds' => null,
            'avg_ai_seconds' => null,
            'avg_saved_seconds' => null,
            'total_saved_seconds' => 0,
            'potential_efficiency_pct' => null,
            'coverage_pct' => 0.0,
            'rows' => [],
            'diagnostics' => [],
        ];

        if (!$DB->tableExists('glpi_tickets') || !$DB->tableExists('glpi_tickettasks')) {
            $result['diagnostics'][] = 'Tabelas necessárias não encontradas.';
            return $result;
        }

        $ticketIds = [];
        $ticketDates = [];

        try {
            // Eficiência é uma métrica de trabalho concluído. Por isso o recorte do
            // período é feito pela DATA DA SOLUÇÃO, e não pela data de abertura.
            // Isso também inclui tickets antigos que foram efetivamente solucionados
            // dentro do período selecionado.
            $iterator = $DB->request([
                'SELECT' => ['id', 'name', 'date', 'solvedate', 'closedate', 'status', 'actiontime'],
                'FROM' => 'glpi_tickets',
                'WHERE' => [
                    'is_deleted' => 0,
                    'status' => [\Ticket::SOLVED, \Ticket::CLOSED],
                    ['solvedate' => ['>=', $from->format('Y-m-d H:i:s')]],
                    ['solvedate' => ['<=', $to->format('Y-m-d H:i:s')]],
                ],
                'ORDER' => ['solvedate DESC'],
            ]);

            foreach ($iterator as $row) {
                $id = (int) ($row['id'] ?? 0);
                if ($id <= 0) {
                    continue;
                }

                $openedAt = (string) ($row['date'] ?? '');
                $solvedAt = (string) ($row['solvedate'] ?? '');
                $opened = strtotime($openedAt);
                $solved = strtotime($solvedAt);
                $calculatedSeconds = ($opened !== false && $solved !== false && $solved > $opened)
                    ? ($solved - $opened)
                    : 0;

                $ticketIds[] = $id;
                $ticketDates[$id] = [
                    'name' => (string) ($row['name'] ?? ''),
                    'date' => $openedAt,
                    'solvedate' => $solvedAt,
                    'closedate' => (string) ($row['closedate'] ?? ''),
                    'status' => (int) ($row['status'] ?? 0),
                    'ticket_actiontime' => max(0, (int) ($row['actiontime'] ?? 0)),
                    'calculated_solution_seconds' => max(0, $calculatedSeconds),
                ];
            }
        } catch (Throwable $e) {
            $result['diagnostics'][] = 'Falha ao consultar tickets solucionados/fechados: ' . $e->getMessage();
            return $result;
        }

        $result['resolved_tickets'] = count($ticketIds);
        $result['closed_tickets'] = $result['resolved_tickets'];

        if ($ticketIds === []) {
            $result['diagnostics'][] = 'Nenhum ticket solucionado/fechado foi encontrado pela data de solução no período selecionado.';
            return $result;
        }

        $historical = [];
        $historicalSource = [];
        $ai = [];

        try {
            foreach (array_chunk($ticketIds, 1000) as $chunk) {
                $iterator = $DB->request([
                    'SELECT' => ['id', 'tickets_id', 'content', 'actiontime'],
                    'FROM' => 'glpi_tickettasks',
                    'WHERE' => ['tickets_id' => $chunk],
                    'ORDER' => ['id ASC'],
                ]);

                foreach ($iterator as $task) {
                    $ticketId = (int) ($task['tickets_id'] ?? 0);
                    if ($ticketId <= 0 || !isset($ticketDates[$ticketId])) {
                        continue;
                    }

                    $content = self::plainTaskText((string) ($task['content'] ?? ''));

                    // Baseline oficial da v0.9.1+: task V2 com actiontime
                    // correspondente exatamente a abertura -> solução.
                    if (mb_stripos($content, ActionTimeBackfill::MARKER) !== false) {
                        $seconds = max(0, (int) ($task['actiontime'] ?? 0));
                        if ($seconds <= 0) {
                            // Fallback defensivo: a task existe, mas o actiontime não foi
                            // persistido. Recalculamos pela mesma regra oficial.
                            $seconds = (int) ($ticketDates[$ticketId]['calculated_solution_seconds'] ?? 0);
                        }
                        if ($seconds > 0) {
                            $historical[$ticketId] = $seconds;
                            $historicalSource[$ticketId] = 'V2';
                        }
                    }

                    // Compatibilidade com tickets processados na v0.9.0. O actiontime
                    // da V1 era abertura -> fechamento, portanto NÃO usamos esse valor;
                    // recalculamos pela regra correta abertura -> solução.
                    if (mb_stripos($content, ActionTimeBackfill::LEGACY_MARKER) !== false && !isset($historical[$ticketId])) {
                        $seconds = (int) ($ticketDates[$ticketId]['calculated_solution_seconds'] ?? 0);
                        if ($seconds > 0) {
                            $historical[$ticketId] = $seconds;
                            $historicalSource[$ticketId] = 'V1-recalculado';
                        }
                    }

                    // IA/RCA: estimativa permanece separada do actiontime real.
                    $hasAiMarker = mb_stripos($content, '[IOS-AI-RCA-V1]') !== false
                        || mb_stripos($content, (string) Settings::get('ai_task_tag', '[IA-RCA]')) !== false;

                    if ($hasAiMarker) {
                        $seconds = self::extractAiSeconds($content);
                        if ($seconds > 0) {
                            $ai[$ticketId] = $seconds;
                        }
                    }
                }
            }
        } catch (Throwable $e) {
            $result['diagnostics'][] = 'Falha ao consultar tasks: ' . $e->getMessage();
            return $result;
        }

        $result['cycle_tickets'] = count($historical);
        $result['ai_tickets'] = count($ai);

        $historicalValues = [];
        $aiValues = [];
        $savedValues = [];
        $weightedHistorical = 0;
        $weightedAi = 0;
        $rows = [];

        foreach ($historical as $ticketId => $historicalSeconds) {
            if (!isset($ai[$ticketId])) {
                continue;
            }

            $aiSeconds = $ai[$ticketId];
            $saved = $historicalSeconds - $aiSeconds;
            $efficiency = $historicalSeconds > 0
                ? (($historicalSeconds - $aiSeconds) / $historicalSeconds) * 100
                : 0.0;

            $historicalValues[] = $historicalSeconds;
            $aiValues[] = $aiSeconds;
            $savedValues[] = $saved;
            $weightedHistorical += $historicalSeconds;
            $weightedAi += $aiSeconds;

            $rows[] = [
                'ticket_id' => $ticketId,
                'name' => $ticketDates[$ticketId]['name'] ?? '',
                'opened_at' => $ticketDates[$ticketId]['date'] ?? '',
                'solved_at' => $ticketDates[$ticketId]['solvedate'] ?? '',
                'cycle_seconds' => $historicalSeconds,
                'ai_seconds' => $aiSeconds,
                'saved_seconds' => $saved,
                'efficiency_pct' => round($efficiency, 1),
                'historical_source' => $historicalSource[$ticketId] ?? 'V2',
            ];
        }

        usort($rows, static fn(array $a, array $b): int => $b['efficiency_pct'] <=> $a['efficiency_pct']);
        $result['rows'] = array_slice($rows, 0, 100);
        $result['compared_tickets'] = count($rows);
        $result['avg_cycle_seconds'] = self::average($historicalValues);
        $result['avg_ai_seconds'] = self::average($aiValues);
        $result['avg_saved_seconds'] = self::average($savedValues);
        $result['total_saved_seconds'] = array_sum($savedValues);
        $result['potential_efficiency_pct'] = $weightedHistorical > 0
            ? round((($weightedHistorical - $weightedAi) / $weightedHistorical) * 100, 1)
            : null;
        $result['coverage_pct'] = $result['resolved_tickets'] > 0
            ? round(($result['compared_tickets'] / $result['resolved_tickets']) * 100, 1)
            : 0.0;

        // Diagnóstico operacional visível na tela. Isso deixa claro qual lado da
        // comparação está faltando caso o resultado volte a ficar zerado.
        $result['diagnostics'][] = sprintf(
            'Base no período: %d solucionados/fechados; %d com ActionTime histórico; %d com IA/RCA; %d comparáveis.',
            $result['resolved_tickets'],
            $result['cycle_tickets'],
            $result['ai_tickets'],
            $result['compared_tickets']
        );

        if ($result['cycle_tickets'] === 0) {
            $result['diagnostics'][] = 'Nenhuma task [IOS-ACTIONTIME-SOLUTION-V2] (ou V1 legada) foi encontrada no período. Execute o processamento de ActionTime histórico.';
        }
        if ($result['ai_tickets'] === 0) {
            $result['diagnostics'][] = 'Nenhuma task IA/RCA com estimativa de tempo foi encontrada no período. Execute/processse a IA/RCA.';
        }
        if ($result['cycle_tickets'] > 0 && $result['ai_tickets'] > 0 && $result['compared_tickets'] === 0) {
            $result['diagnostics'][] = 'Existem dados nos dois lados, mas em tickets diferentes. A eficiência só compara tickets que possuem Histórico e IA/RCA no mesmo chamado.';
        }

        return $result;
    }

    private static function extractAiSeconds(string $content): int
    {
        // Formato atual.
        if (preg_match('/Tempo\s+estimado\s+IA\s*\(segundos\)\s*:\s*(\d+)/iu', $content, $m)) {
            return max(0, (int) $m[1]);
        }

        // Fallback para tasks que tenham apenas a linha em minutos.
        if (preg_match('/Tempo\s+estimado\s+de\s+atua[cç][aã]o\s*\(IA\)\s*:\s*(\d+)\s*minutos?/iu', $content, $m)) {
            return max(0, (int) $m[1] * 60);
        }

        return 0;
    }

    private static function plainTaskText(string $html): string
    {
        $normalized = str_ireplace(
            ['<br>', '<br/>', '<br />', '</p>', '</div>', '</li>'],
            ["\n", "\n", "\n", "\n", "\n", "\n"],
            $html
        );
        $text = html_entity_decode(strip_tags($normalized), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[\t ]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/\R+/u', "\n", $text) ?? $text;
        return trim($text);
    }

    private static function average(array $values): ?float
    {
        if ($values === []) {
            return null;
        }
        return array_sum($values) / count($values);
    }
}
