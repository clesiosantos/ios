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
            'closed_tickets' => 0,
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
            $iterator = $DB->request([
                'SELECT' => ['id', 'name', 'date', 'solvedate', 'closedate'],
                'FROM' => 'glpi_tickets',
                'WHERE' => [
                    'is_deleted' => 0,
                    'status' => \Ticket::CLOSED,
                    ['date' => ['>=', $from->format('Y-m-d H:i:s')]],
                    ['date' => ['<=', $to->format('Y-m-d H:i:s')]],
                ],
                'ORDER' => ['id DESC'],
            ]);

            foreach ($iterator as $row) {
                $id = (int) ($row['id'] ?? 0);
                if ($id <= 0) {
                    continue;
                }
                $ticketIds[] = $id;
                $ticketDates[$id] = [
                    'name' => (string) ($row['name'] ?? ''),
                    'date' => (string) ($row['date'] ?? ''),
                    'solvedate' => (string) ($row['solvedate'] ?? ''),
                    'closedate' => (string) ($row['closedate'] ?? ''),
                ];
            }
        } catch (Throwable $e) {
            $result['diagnostics'][] = 'Falha ao consultar tickets fechados: ' . $e->getMessage();
            return $result;
        }

        $result['closed_tickets'] = count($ticketIds);
        if ($ticketIds === []) {
            return $result;
        }

        $historical = [];
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
                    if ($ticketId <= 0) {
                        continue;
                    }

                    $content = html_entity_decode(strip_tags((string) ($task['content'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8');

                    // Baseline oficial: task V2 com actiontime abertura→solução.
                    if (mb_stripos($content, ActionTimeBackfill::MARKER) !== false) {
                        $seconds = max(0, (int) ($task['actiontime'] ?? 0));
                        if ($seconds > 0) {
                            $historical[$ticketId] = $seconds;
                        }
                    }

                    // IA/RCA: permanece como estimativa separada no conteúdo da task,
                    // sem actiontime, para não somar tempo fictício ao ticket.
                    if (mb_stripos($content, '[IOS-AI-RCA-V1]') !== false || mb_stripos($content, (string) Settings::get('ai_task_tag', '[IA-RCA]')) !== false) {
                        if (preg_match('/Tempo estimado IA \(segundos\):\s*(\d+)/iu', $content, $m)) {
                            $seconds = (int) $m[1];
                            if ($seconds > 0) {
                                $ai[$ticketId] = $seconds;
                            }
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
            $efficiency = $historicalSeconds > 0 ? (($historicalSeconds - $aiSeconds) / $historicalSeconds) * 100 : 0.0;

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
        $result['coverage_pct'] = $result['closed_tickets'] > 0
            ? round(($result['compared_tickets'] / $result['closed_tickets']) * 100, 1)
            : 0.0;

        return $result;
    }

    private static function average(array $values): ?float
    {
        if ($values === []) {
            return null;
        }
        return array_sum($values) / count($values);
    }
}
