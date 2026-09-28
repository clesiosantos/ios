<?php

namespace GlpiPlugin\Iosindicators;

use DateTimeImmutable;
use DateTimeInterface;
use Throwable;
use Ticket;

final class Metrics
{
    /**
     * Consolida os indicadores de ciclo de vida do incidente.
     *
     * GLPI 11 bloqueia consultas SQL diretas via DBmysql::query(). Por isso,
     * esta versão usa exclusivamente o Query Builder/DB iterator oficial
     * ($DB->request()) e calcula as agregações em PHP.
     *
     * O período é aplicado à data de abertura do ticket (glpi_tickets.date).
     */
    public static function summary(DateTimeInterface $from, DateTimeInterface $to): array
    {
        global $DB;

        $summary = self::emptySummary();
        $diagnostics = [];

        if (!$DB->tableExists('glpi_tickets')) {
            $summary['diagnostics'][] = 'Tabela glpi_tickets não encontrada.';
            return $summary;
        }

        $required = ['id', 'date', 'status', 'type', 'entities_id', 'is_deleted'];
        foreach ($required as $field) {
            if (!$DB->fieldExists('glpi_tickets', $field)) {
                $summary['diagnostics'][] = "Campo glpi_tickets.{$field} não encontrado.";
                return $summary;
            }
        }

        $optionalFields = [
            'solvedate',
            'closedate',
            'takeintoaccount_delay_stat',
            'solve_delay_stat',
            'actiontime',
            'waiting_duration',
            'close_delay_stat',
        ];

        $select = $required;
        foreach ($optionalFields as $field) {
            if ($DB->fieldExists('glpi_tickets', $field)) {
                $select[] = $field;
            } else {
                $diagnostics[] = "Campo glpi_tickets.{$field} não encontrado; indicador correspondente ficará sem valor.";
            }
        }

        $where = [
            'is_deleted' => 0,
            'entities_id' => self::entityIds(),
            ['date' => ['>=', $from->format('Y-m-d H:i:s')]],
            ['date' => ['<=', $to->format('Y-m-d H:i:s')]],
        ];

        if ((int)Settings::get('show_only_incidents', 1) === 1) {
            $where['type'] = Ticket::INCIDENT_TYPE;
        }

        $ticketIds = [];
        $acc = [
            'tto' => [],
            'tts' => [],
            'tma' => [],
            'waiting' => [],
            'close_delay' => [],
            'actiontime' => [],
            'solve_delay' => [],
            'total_incident' => [],
        ];

        try {
            $iterator = $DB->request([
                'SELECT' => $select,
                'FROM'   => 'glpi_tickets',
                'WHERE'  => $where,
            ]);

            foreach ($iterator as $ticket) {
                $ticketIds[] = (int)$ticket['id'];
                $summary['total']++;

                $status = (int)($ticket['status'] ?? 0);
                switch ($status) {
                    case Ticket::INCOMING:
                        $summary['incoming']++;
                        break;
                    case Ticket::ASSIGNED:
                        $summary['assigned']++;
                        break;
                    case Ticket::PLANNED:
                        $summary['planned']++;
                        break;
                    case Ticket::WAITING:
                        $summary['pending']++;
                        break;
                    case Ticket::SOLVED:
                        $summary['solved']++;
                        break;
                    case Ticket::CLOSED:
                        $summary['closed']++;
                        break;
                }

                if (!in_array($status, [Ticket::SOLVED, Ticket::CLOSED], true)) {
                    $summary['open']++;
                }

                self::pushPositive($acc['tto'], $ticket['takeintoaccount_delay_stat'] ?? null);
                self::pushPositive($acc['waiting'], $ticket['waiting_duration'] ?? null);
                self::pushPositive($acc['close_delay'], $ticket['close_delay_stat'] ?? null);
                self::pushPositive($acc['actiontime'], $ticket['actiontime'] ?? null);
                self::pushPositive($acc['solve_delay'], $ticket['solve_delay_stat'] ?? null);

                if (!empty($ticket['solvedate'])) {
                    self::pushPositive($acc['tts'], $ticket['solve_delay_stat'] ?? null);
                    self::pushPositive($acc['tma'], $ticket['actiontime'] ?? null);
                }

                $endDate = $ticket['closedate'] ?? null;
                if (empty($endDate)) {
                    $endDate = $ticket['solvedate'] ?? null;
                }

                if (!empty($ticket['date']) && !empty($endDate)) {
                    $startTs = strtotime((string)$ticket['date']);
                    $endTs   = strtotime((string)$endDate);
                    if ($startTs !== false && $endTs !== false && $endTs >= $startTs) {
                        $acc['total_incident'][] = (float)($endTs - $startTs);
                    }
                }
            }
        } catch (Throwable $e) {
            $diagnostics[] = 'Consulta de tickets: ' . $e->getMessage();
        }

        $summary['avg_tto'] = self::average($acc['tto']);
        $summary['avg_tts'] = self::average($acc['tts']);
        $summary['avg_tma'] = self::average($acc['tma']);
        $summary['avg_mttr'] = self::average($acc['tts']);
        $summary['avg_total_incident'] = self::average($acc['total_incident']);
        $summary['avg_waiting'] = self::average($acc['waiting']);

        $mbtrSource = (string)Settings::get('mbtr_source', 'none');
        $summary['mbtr'] = match ($mbtrSource) {
            'waiting_duration' => self::average($acc['waiting']),
            'close_delay_stat' => self::average($acc['close_delay']),
            'actiontime'       => self::average($acc['actiontime']),
            'solve_delay_stat' => self::average($acc['solve_delay']),
            default            => null,
        };

        $taskMetrics = self::taskMetrics($ticketIds, $summary['solved'] + $summary['closed'], $diagnostics);
        $summary = array_merge($summary, $taskMetrics);

        $summary['period_from'] = $from->format('Y-m-d H:i:s');
        $summary['period_to']   = $to->format('Y-m-d H:i:s');
        $summary['diagnostics'] = $diagnostics;

        return $summary;
    }

    private static function emptySummary(): array
    {
        return [
            'total' => 0,
            'incoming' => 0,
            'assigned' => 0,
            'planned' => 0,
            'pending' => 0,
            'solved' => 0,
            'closed' => 0,
            'open' => 0,
            'avg_tto' => null,
            'avg_tts' => null,
            'avg_tma' => null,
            'avg_mttr' => null,
            'avg_total_incident' => null,
            'avg_waiting' => null,
            'mbtr' => null,
            'ai_tagged' => 0,
            'rca_complete' => 0,
            'remote_tasks' => 0,
            'rca_coverage_pct' => 0.0,
            'rca_complete_pct' => 0.0,
            'resolved_for_rca_den' => 0,
            'period_from' => null,
            'period_to' => null,
            'diagnostics' => [],
        ];
    }

    /**
     * Indicadores construídos a partir das tarefas dos tickets selecionados.
     *
     * Os IDs são consultados em lotes para evitar listas IN excessivamente grandes.
     */
    private static function taskMetrics(array $ticketIds, int $resolved, array &$diagnostics): array
    {
        global $DB;

        $empty = [
            'ai_tagged' => 0,
            'rca_complete' => 0,
            'remote_tasks' => 0,
            'rca_coverage_pct' => 0.0,
            'rca_complete_pct' => 0.0,
            'resolved_for_rca_den' => $resolved,
        ];

        if ($ticketIds === []) {
            return $empty;
        }

        if (!$DB->tableExists('glpi_tickettasks')) {
            $diagnostics[] = 'Tabela glpi_tickettasks não encontrada; indicadores de RCA foram desativados.';
            return $empty;
        }

        foreach (['tickets_id', 'content'] as $field) {
            if (!$DB->fieldExists('glpi_tickettasks', $field)) {
                $diagnostics[] = "Campo glpi_tickettasks.{$field} não encontrado; indicadores de RCA foram desativados.";
                return $empty;
            }
        }

        $aiTag = trim((string)Settings::get('ai_task_tag', '[IA-RCA]'));
        $remoteTag = trim((string)Settings::get('remote_task_tag', '[REMOTO]'));

        $taggedTickets = [];
        $completeTickets = [];
        $remoteTasks = 0;

        try {
            foreach (array_chunk(array_values(array_unique(array_map('intval', $ticketIds))), 1000) as $chunk) {
                $iterator = $DB->request([
                    'SELECT' => ['tickets_id', 'content'],
                    'FROM'   => 'glpi_tickettasks',
                    'WHERE'  => ['tickets_id' => $chunk],
                ]);

                foreach ($iterator as $task) {
                    $ticketId = (int)($task['tickets_id'] ?? 0);
                    $content = html_entity_decode(strip_tags((string)($task['content'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8');

                    $hasAiTag = $aiTag !== '' && mb_stripos($content, $aiTag) !== false;
                    if ($hasAiTag) {
                        $taggedTickets[$ticketId] = true;

                        $normalized = mb_strtolower($content);
                        $hasDiagnostic = mb_stripos($normalized, 'diagn') !== false;
                        $hasCause = mb_stripos($normalized, 'causa') !== false;
                        $hasSolution = mb_stripos($normalized, 'solu') !== false;

                        if ($hasDiagnostic && $hasCause && $hasSolution) {
                            $completeTickets[$ticketId] = true;
                        }
                    }

                    if ($remoteTag !== '' && mb_stripos($content, $remoteTag) !== false) {
                        $remoteTasks++;
                    }
                }
            }
        } catch (Throwable $e) {
            $diagnostics[] = 'Consulta de tarefas/RCA: ' . $e->getMessage();
        }

        $tagged = count($taggedTickets);
        $complete = count($completeTickets);

        return [
            'ai_tagged'            => $tagged,
            'rca_complete'         => $complete,
            'remote_tasks'         => $remoteTasks,
            'rca_coverage_pct'     => $resolved > 0 ? round(($tagged / $resolved) * 100, 1) : 0.0,
            'rca_complete_pct'     => $resolved > 0 ? round(($complete / $resolved) * 100, 1) : 0.0,
            'resolved_for_rca_den' => $resolved,
        ];
    }

    public static function periodFromRequest(array $request): array
    {
        $defaultDays = max(1, (int)Settings::get('default_period_days', 30));
        $days = isset($request['days']) ? max(1, min(3650, (int)$request['days'])) : $defaultDays;

        $to = new DateTimeImmutable('now');
        $from = $to->modify('-' . ($days - 1) . ' days')->setTime(0, 0, 0);

        if (!empty($request['from']) && !empty($request['to'])) {
            $customFrom = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $request['from'] . ' 00:00:00');
            $customTo   = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $request['to'] . ' 23:59:59');
            if ($customFrom && $customTo && $customFrom <= $customTo) {
                $from = $customFrom;
                $to   = $customTo;
            }
        }

        return [$from, $to];
    }

    public static function duration(?float $seconds): string
    {
        if ($seconds === null) {
            return '—';
        }

        $seconds = max(0, (int)round($seconds));
        $days = intdiv($seconds, 86400);
        $hours = intdiv($seconds % 86400, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $secs = $seconds % 60;

        if ($days > 0) {
            return sprintf('%dd %02dh %02dm', $days, $hours, $minutes);
        }
        if ($hours > 0) {
            return sprintf('%dh %02dm', $hours, $minutes);
        }
        if ($minutes > 0) {
            return sprintf('%dm %02ds', $minutes, $secs);
        }
        return sprintf('%ds', $secs);
    }

    private static function entityIds(): array
    {
        $ids = $_SESSION['glpiactiveentities'] ?? [($_SESSION['glpiactive_entity'] ?? 0)];
        if (!is_array($ids) || $ids === []) {
            $ids = [0];
        }

        $ids = array_values(array_unique(array_map('intval', $ids)));
        return $ids !== [] ? $ids : [0];
    }

    private static function pushPositive(array &$values, mixed $value): void
    {
        if ($value === null || $value === '') {
            return;
        }

        $number = (float)$value;
        if ($number > 0) {
            $values[] = $number;
        }
    }

    private static function average(array $values): ?float
    {
        $count = count($values);
        if ($count === 0) {
            return null;
        }

        return array_sum($values) / $count;
    }
}
