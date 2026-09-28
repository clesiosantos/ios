<?php

namespace GlpiPlugin\Iosindicators;

use CommonITILActor;
use DateTimeImmutable;
use DateTimeInterface;
use Throwable;
use Ticket;

final class Metrics
{
    public static function summary(DateTimeInterface $from, DateTimeInterface $to): array
    {
        global $DB;

        $summary = self::emptySummary();
        $diagnostics = [];

        if (!$DB->tableExists('glpi_tickets')) {
            $summary['diagnostics'][] = 'Tabela glpi_tickets não encontrada.';
            return $summary;
        }

        $required = ['id', 'name', 'content', 'date', 'status', 'type', 'entities_id', 'is_deleted'];
        foreach ($required as $field) {
            if (!$DB->fieldExists('glpi_tickets', $field)) {
                $summary['diagnostics'][] = "Campo glpi_tickets.{$field} não encontrado.";
                return $summary;
            }
        }

        $optionalFields = [
            'itilcategories_id',
            'priority',
            'urgency',
            'impact',
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
            'is_deleted'  => 0,
            'entities_id' => self::entityIds(),
            ['date' => ['>=', $from->format('Y-m-d H:i:s')]],
            ['date' => ['<=', $to->format('Y-m-d H:i:s')]],
        ];

        if ((int) Settings::get('show_only_incidents', 1) === 1) {
            $where['type'] = Ticket::INCIDENT_TYPE;
        }

        $ticketIds = [];
        $categoryFlags = [];
        $acc = [
            'tto'             => [],
            'tma'             => [],
            'waiting'         => [],
            'close_delay'     => [],
            'actiontime'      => [],
            'solve_delay'     => [],
            'repair'          => [],
            'total_incident'  => [],
        ];

        $statusCounts = [];
        $eventCounts = [];
        $severityCounts = [];
        $equipmentCounts = [];
        $clientCounts = [];
        $hostCounts = [];
        $hostTimeline = [];

        $openStatusCounts = [];
        $openEventCounts = [];
        $openSeverityCounts = [];
        $openEquipmentCounts = [];
        $openClientCounts = [];
        $openHostCounts = [];
        $latestTickets = [];

        try {
            $iterator = $DB->request([
                'SELECT' => $select,
                'FROM'   => 'glpi_tickets',
                'WHERE'  => $where,
                'ORDER'  => ['date DESC'],
            ]);

            foreach ($iterator as $ticket) {
                $ticketId = (int) ($ticket['id'] ?? 0);
                $ticketIds[] = $ticketId;
                $summary['total']++;

                $status = (int) ($ticket['status'] ?? 0);
                $statusLabel = self::statusLabel($status);
                $statusCounts[$statusLabel] = ($statusCounts[$statusLabel] ?? 0) + 1;

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

                $isOpen = !in_array($status, [Ticket::SOLVED, Ticket::CLOSED], true);
                if ($isOpen) {
                    $summary['open']++;
                    $openStatusCounts[$statusLabel] = ($openStatusCounts[$statusLabel] ?? 0) + 1;
                }

                $hasCategory = (int) ($ticket['itilcategories_id'] ?? 0) > 0;
                $categoryFlags[$ticketId] = $hasCategory;
                if ($hasCategory) {
                    $summary['with_categories']++;
                }

                self::pushPositive($acc['tto'], $ticket['takeintoaccount_delay_stat'] ?? null);
                self::pushPositive($acc['waiting'], $ticket['waiting_duration'] ?? null);
                self::pushPositive($acc['close_delay'], $ticket['close_delay_stat'] ?? null);
                self::pushPositive($acc['actiontime'], $ticket['actiontime'] ?? null);
                self::pushPositive($acc['solve_delay'], $ticket['solve_delay_stat'] ?? null);
                self::pushPositive($acc['repair'], $ticket['solve_delay_stat'] ?? null);

                if (!empty($ticket['solvedate'])) {
                    self::pushPositive($acc['tma'], $ticket['actiontime'] ?? null);
                }

                $endDate = $ticket['closedate'] ?? null;
                if (empty($endDate)) {
                    $endDate = $ticket['solvedate'] ?? null;
                }

                if (!empty($ticket['date']) && !empty($endDate)) {
                    $startTs = strtotime((string) $ticket['date']);
                    $endTs   = strtotime((string) $endDate);
                    if ($startTs !== false && $endTs !== false && $endTs >= $startTs) {
                        $acc['total_incident'][] = (float) ($endTs - $startTs);
                    }
                }

                $parsed = self::extractTicketContext((string) ($ticket['name'] ?? ''), (string) ($ticket['content'] ?? ''));
                if ($parsed['event'] !== null) {
                    $eventCounts[$parsed['event']] = ($eventCounts[$parsed['event']] ?? 0) + 1;
                }
                if ($parsed['severity'] !== null) {
                    $severityCounts[$parsed['severity']] = ($severityCounts[$parsed['severity']] ?? 0) + 1;
                }
                if ($parsed['equipment_code'] !== null) {
                    $equipmentCounts[$parsed['equipment_code']] = ($equipmentCounts[$parsed['equipment_code']] ?? 0) + 1;
                }
                if ($parsed['client'] !== null) {
                    $clientCounts[$parsed['client']] = ($clientCounts[$parsed['client']] ?? 0) + 1;
                }
                if ($parsed['host'] !== null) {
                    $hostCounts[$parsed['host']] = ($hostCounts[$parsed['host']] ?? 0) + 1;
                }

                if ($isOpen) {
                    if ($parsed['event'] !== null) {
                        $openEventCounts[$parsed['event']] = ($openEventCounts[$parsed['event']] ?? 0) + 1;
                    }
                    if ($parsed['severity'] !== null) {
                        $openSeverityCounts[$parsed['severity']] = ($openSeverityCounts[$parsed['severity']] ?? 0) + 1;
                    }
                    if ($parsed['equipment_code'] !== null) {
                        $openEquipmentCounts[$parsed['equipment_code']] = ($openEquipmentCounts[$parsed['equipment_code']] ?? 0) + 1;
                    }
                    if ($parsed['client'] !== null) {
                        $openClientCounts[$parsed['client']] = ($openClientCounts[$parsed['client']] ?? 0) + 1;
                    }
                    if ($parsed['host'] !== null) {
                        $openHostCounts[$parsed['host']] = ($openHostCounts[$parsed['host']] ?? 0) + 1;
                    }
                }

                if ($parsed['host'] !== null && !empty($ticket['date']) && !empty($endDate)) {
                    $openedTs = strtotime((string) $ticket['date']);
                    $endedTs  = strtotime((string) $endDate);
                    if ($openedTs !== false && $endedTs !== false && $endedTs >= $openedTs) {
                        $hostTimeline[$parsed['host']][] = [
                            'opened_ts' => $openedTs,
                            'ended_ts'  => $endedTs,
                        ];
                    }
                }

                if (count($latestTickets) < 8) {
                    $latestTickets[] = [
                        'id'        => $ticketId,
                        'title'     => self::shortTitle((string) ($ticket['name'] ?? 'Ticket #' . $ticketId), 72),
                        'client'    => $parsed['client'] ?? '—',
                        'host'      => $parsed['host'] ?? '—',
                        'event'     => $parsed['event'] ?? '—',
                        'severity'  => $parsed['severity'] ?? '—',
                        'status'    => $statusLabel,
                        'status_class' => self::statusClass($status),
                        'date'      => !empty($ticket['date']) ? date('d/m H:i', strtotime((string) $ticket['date'])) : '—',
                    ];
                }
            }
        } catch (Throwable $e) {
            $diagnostics[] = 'Consulta de tickets: ' . $e->getMessage();
        }

        $summary['avg_tto']            = self::average($acc['tto']);
        $summary['avg_tma']            = self::average($acc['tma']);
        $summary['avg_mttr']           = self::average($acc['repair']);
        $summary['avg_total_incident'] = self::average($acc['total_incident']);
        $summary['avg_waiting']        = self::average($acc['waiting']);
        $summary['p50_mttr']           = self::percentile($acc['repair'], 50);
        $summary['p90_mttr']           = self::percentile($acc['repair'], 90);
        $summary['p95_mttr']           = self::percentile($acc['repair'], 95);

        $mbtrSource = (string) Settings::get('mbtr_source', 'none');
        $summary['mbtr'] = match ($mbtrSource) {
            'waiting_duration' => self::average($acc['waiting']),
            'close_delay_stat' => self::average($acc['close_delay']),
            'actiontime'       => self::average($acc['actiontime']),
            'solve_delay_stat' => self::average($acc['solve_delay']),
            default            => null,
        };

        $reliability = self::reliabilityMetrics($hostTimeline, $hostCounts);
        $summary = array_merge($summary, $reliability);

        $enrichment = self::enrichmentMetrics($ticketIds, $categoryFlags, $diagnostics);
        $summary = array_merge($summary, $enrichment);

        $taskMetrics = self::taskMetrics($ticketIds, $summary['solved'] + $summary['closed'], $diagnostics);
        $summary = array_merge($summary, $taskMetrics);

        $summary['status_counts']    = self::sortCounts($statusCounts);
        $summary['event_counts']     = self::sortCounts($eventCounts);
        $summary['severity_counts']  = self::sortCounts($severityCounts);
        $summary['equipment_counts'] = self::sortCounts($equipmentCounts);
        $summary['client_counts']    = self::sortCounts($clientCounts);
        $summary['host_counts']      = self::sortCounts($hostCounts);

        $summary['open_status_counts']    = self::sortCounts($openStatusCounts);
        $summary['open_event_counts']     = self::sortCounts($openEventCounts);
        $summary['open_severity_counts']  = self::sortCounts($openSeverityCounts);
        $summary['open_equipment_counts'] = self::sortCounts($openEquipmentCounts);
        $summary['open_client_counts']    = self::sortCounts($openClientCounts);
        $summary['open_host_counts']      = self::sortCounts($openHostCounts);
        $summary['latest_tickets']        = $latestTickets;
        $summary['open_clients']          = count($openClientCounts);
        $summary['open_hosts']            = count($openHostCounts);
        $summary['active_severities']     = count($openSeverityCounts);

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
            'with_categories' => 0,
            'with_items' => 0,
            'with_requester_groups' => 0,
            'with_assign_groups' => 0,
            'structured_tickets' => 0,
            'coverage_category_pct' => 0.0,
            'coverage_item_pct' => 0.0,
            'coverage_requester_pct' => 0.0,
            'coverage_assign_pct' => 0.0,
            'coverage_structured_pct' => 0.0,
            'avg_tto' => null,
            'avg_tma' => null,
            'avg_mttr' => null,
            'avg_total_incident' => null,
            'avg_waiting' => null,
            'mbtr' => null,
            'mtbf' => null,
            'mtbr' => null,
            'availability_pct' => 0.0,
            'p50_mttr' => null,
            'p90_mttr' => null,
            'p95_mttr' => null,
            'recurrent_hosts' => 0,
            'recurrent_tickets' => 0,
            'recurrent_ticket_pct' => 0.0,
            'ai_tagged' => 0,
            'rca_complete' => 0,
            'remote_tasks' => 0,
            'rca_coverage_pct' => 0.0,
            'rca_complete_pct' => 0.0,
            'resolved_for_rca_den' => 0,
            'status_counts' => [],
            'event_counts' => [],
            'severity_counts' => [],
            'equipment_counts' => [],
            'client_counts' => [],
            'host_counts' => [],
            'open_status_counts' => [],
            'open_event_counts' => [],
            'open_severity_counts' => [],
            'open_equipment_counts' => [],
            'open_client_counts' => [],
            'open_host_counts' => [],
            'latest_tickets' => [],
            'open_clients' => 0,
            'open_hosts' => 0,
            'active_severities' => 0,
            'period_from' => null,
            'period_to' => null,
            'diagnostics' => [],
        ];
    }

    private static function enrichmentMetrics(array $ticketIds, array $categoryFlags, array &$diagnostics): array
    {
        global $DB;

        $empty = [
            'with_items' => 0,
            'with_requester_groups' => 0,
            'with_assign_groups' => 0,
            'structured_tickets' => 0,
            'coverage_item_pct' => 0.0,
            'coverage_requester_pct' => 0.0,
            'coverage_assign_pct' => 0.0,
            'coverage_structured_pct' => 0.0,
            'coverage_category_pct' => 0.0,
        ];

        $total = count($ticketIds);
        $withCategory = count(array_filter($categoryFlags));
        $empty['coverage_category_pct'] = $total > 0 ? round(($withCategory / $total) * 100, 1) : 0.0;
        if ($ticketIds === []) {
            return $empty;
        }

        $items = [];
        $requesters = [];
        $assignees = [];

        if ($DB->tableExists('glpi_items_tickets') && $DB->fieldExists('glpi_items_tickets', 'tickets_id')) {
            foreach (array_chunk($ticketIds, 1000) as $chunk) {
                try {
                    $iterator = $DB->request([
                        'SELECT' => ['tickets_id'],
                        'FROM'   => 'glpi_items_tickets',
                        'WHERE'  => ['tickets_id' => $chunk],
                    ]);
                    foreach ($iterator as $row) {
                        $items[(int) ($row['tickets_id'] ?? 0)] = true;
                    }
                } catch (Throwable $e) {
                    $diagnostics[] = 'Consulta de vínculo ticket-item: ' . $e->getMessage();
                    break;
                }
            }
        } else {
            $diagnostics[] = 'Tabela glpi_items_tickets não encontrada; cobertura de ativos não pôde ser calculada.';
        }

        if ($DB->tableExists('glpi_groups_tickets') && $DB->fieldExists('glpi_groups_tickets', 'tickets_id') && $DB->fieldExists('glpi_groups_tickets', 'type')) {
            foreach (array_chunk($ticketIds, 1000) as $chunk) {
                try {
                    $iterator = $DB->request([
                        'SELECT' => ['tickets_id', 'type'],
                        'FROM'   => 'glpi_groups_tickets',
                        'WHERE'  => ['tickets_id' => $chunk],
                    ]);
                    foreach ($iterator as $row) {
                        $ticketId = (int) ($row['tickets_id'] ?? 0);
                        $type = (int) ($row['type'] ?? 0);
                        if ($type === CommonITILActor::REQUESTER) {
                            $requesters[$ticketId] = true;
                        }
                        if ($type === CommonITILActor::ASSIGN) {
                            $assignees[$ticketId] = true;
                        }
                    }
                } catch (Throwable $e) {
                    $diagnostics[] = 'Consulta de grupos do ticket: ' . $e->getMessage();
                    break;
                }
            }
        } else {
            $diagnostics[] = 'Tabela glpi_groups_tickets não encontrada; cobertura de grupos não pôde ser calculada.';
        }

        $withItems = count($items);
        $withRequesters = count($requesters);
        $withAssignees = count($assignees);
        $structured = 0;

        foreach ($ticketIds as $ticketId) {
            if (!empty($categoryFlags[$ticketId]) && isset($items[$ticketId]) && isset($requesters[$ticketId]) && isset($assignees[$ticketId])) {
                $structured++;
            }
        }

        return [
            'with_items' => $withItems,
            'with_requester_groups' => $withRequesters,
            'with_assign_groups' => $withAssignees,
            'structured_tickets' => $structured,
            'coverage_item_pct' => $total > 0 ? round(($withItems / $total) * 100, 1) : 0.0,
            'coverage_requester_pct' => $total > 0 ? round(($withRequesters / $total) * 100, 1) : 0.0,
            'coverage_assign_pct' => $total > 0 ? round(($withAssignees / $total) * 100, 1) : 0.0,
            'coverage_structured_pct' => $total > 0 ? round(($structured / $total) * 100, 1) : 0.0,
            'coverage_category_pct' => $empty['coverage_category_pct'],
        ];
    }

    private static function reliabilityMetrics(array $hostTimeline, array $hostCounts): array
    {
        $mtbfValues = [];
        $mtbrValues = [];
        $recurrentHosts = 0;
        $recurrentTickets = 0;

        foreach ($hostTimeline as $host => $rows) {
            $incidentsForHost = count($rows);
            if ($incidentsForHost >= 2) {
                $recurrentHosts++;
                $recurrentTickets += $incidentsForHost;
            }

            usort($rows, static fn(array $a, array $b): int => ($a['opened_ts'] <=> $b['opened_ts']));
            for ($i = 1, $max = count($rows); $i < $max; $i++) {
                $prev = $rows[$i - 1];
                $curr = $rows[$i];
                $betweenFailures = $curr['opened_ts'] - $prev['ended_ts'];
                $betweenRepairs  = $curr['ended_ts'] - $prev['ended_ts'];
                if ($betweenFailures >= 0) {
                    $mtbfValues[] = (float) $betweenFailures;
                }
                if ($betweenRepairs >= 0) {
                    $mtbrValues[] = (float) $betweenRepairs;
                }
            }
        }

        $mtbf = self::average($mtbfValues);
        $mtbr = self::average($mtbrValues);
        $mttr = null;
        if ($mtbf !== null && $mtbr !== null && $mtbr >= $mtbf) {
            $mttr = $mtbr - $mtbf;
        }
        $availability = ($mtbf !== null && $mttr !== null && ($mtbf + $mttr) > 0)
            ? round(($mtbf / ($mtbf + $mttr)) * 100, 2)
            : 0.0;

        return [
            'mtbf' => $mtbf,
            'mtbr' => $mtbr,
            'availability_pct' => $availability,
            'recurrent_hosts' => $recurrentHosts,
            'recurrent_tickets' => $recurrentTickets,
            'recurrent_ticket_pct' => count($hostCounts) > 0 ? round(($recurrentTickets / max(1, array_sum($hostCounts))) * 100, 1) : 0.0,
        ];
    }

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

        $aiTag = trim((string) Settings::get('ai_task_tag', '[IA-RCA]'));
        $remoteTag = trim((string) Settings::get('remote_task_tag', '[REMOTO]'));

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
                    $ticketId = (int) ($task['tickets_id'] ?? 0);
                    $content = html_entity_decode(strip_tags((string) ($task['content'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8');

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
            'ai_tagged' => $tagged,
            'rca_complete' => $complete,
            'remote_tasks' => $remoteTasks,
            'rca_coverage_pct' => $resolved > 0 ? round(($tagged / $resolved) * 100, 1) : 0.0,
            'rca_complete_pct' => $resolved > 0 ? round(($complete / $resolved) * 100, 1) : 0.0,
            'resolved_for_rca_den' => $resolved,
        ];
    }

    public static function periodFromRequest(array $request): array
    {
        $defaultDays = max(1, (int) Settings::get('default_period_days', 30));
        $days = isset($request['days']) ? max(1, min(3650, (int) $request['days'])) : $defaultDays;

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

        $seconds = max(0, (int) round($seconds));
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

    public static function percent(?float $value, int $decimals = 1): string
    {
        if ($value === null) {
            return '—';
        }

        return number_format($value, $decimals, ',', '.') . '%';
    }

    public static function top(array $counts, int $limit = 5): array
    {
        $counts = self::sortCounts($counts);
        $top = [];
        $total = max(1, array_sum($counts));
        $i = 0;
        foreach ($counts as $label => $count) {
            $top[] = [
                'label'   => self::displayLabel((string) $label),
                'count'   => (int) $count,
                'percent' => round(((int) $count / $total) * 100, 1),
            ];
            $i++;
            if ($i >= $limit) {
                break;
            }
        }
        return $top;
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

    private static function extractTicketContext(string $name, string $content): array
    {
        $plain = self::plainText($content);
        $full = trim($name . "\n" . $plain);

        $host = null;
        $event = null;
        $severity = null;

        if (preg_match('/(?:Problem|Resolved in .*?):\s*[^|\n]+\|\s*([A-Z0-9._:-]+)\s*\|\s*([a-z0-9_-]+)/iu', $name, $m)) {
            $host = self::sanitizeHost($m[1]);
            $event = self::sanitizeEvent($m[2]);
        }

        if ($host === null && preg_match('/Host:\s*(.+?)(?:\s+Severity:|\s+Operational data:|\s+Original problem ID:|\s+Link to problem|\n|$)/iu', $full, $m)) {
            $host = self::sanitizeHost($m[1]);
        }
        if ($event === null && preg_match('/Problem name:\s*[^|\n]+\|\s*[A-Z0-9._:-]+\s*\|\s*([a-z0-9_-]+)/iu', $full, $m)) {
            $event = self::sanitizeEvent($m[1]);
        }
        if (preg_match('/Severity:\s*(.+?)(?:\s+Operational data:|\s+Original problem ID:|\s+Link to problem|\n|$)/iu', $full, $m)) {
            $severity = self::displayLabel(trim($m[1]));
        }

        $client = null;
        $equipmentCode = null;
        if ($host !== null && preg_match('/^(CLIENTE-\d+)-([A-Z0-9]+)-/u', $host, $m)) {
            $client = $m[1];
            $equipmentCode = $m[2];
        }

        return [
            'host' => $host,
            'event' => $event,
            'severity' => $severity,
            'client' => $client,
            'equipment_code' => $equipmentCode,
        ];
    }

    private static function plainText(string $html): string
    {
        $normalized = str_ireplace(['<br>', '<br/>', '<br />', '</p>', '</div>', '</li>'], ["\n", "\n", "\n", "\n", "\n", "\n"], $html);
        $normalized = preg_replace('/<(p|div|li|ul|ol)[^>]*>/i', "\n", $normalized) ?? $normalized;
        $text = html_entity_decode(strip_tags($normalized), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[\t ]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/\R+/u', "\n", $text) ?? $text;
        return trim($text);
    }

    private static function sanitizeHost(string $value): ?string
    {
        $value = trim($value);
        $value = preg_replace('/\s+.*/u', '', $value) ?? $value;
        $value = preg_replace('/[^A-Za-z0-9_.:-]/u', '', $value) ?? '';
        if ($value === '' || mb_strlen($value) > 255) {
            return null;
        }

        return mb_strtoupper($value);
    }

    private static function sanitizeEvent(string $value): ?string
    {
        $value = trim(mb_strtolower($value));
        $value = preg_replace('/[^a-z0-9_-]/u', '', $value) ?? '';
        return $value !== '' ? mb_substr($value, 0, 120) : null;
    }

    private static function statusLabel(int $status): string
    {
        return match ($status) {
            Ticket::INCOMING => 'Novos',
            Ticket::ASSIGNED => 'Atribuídos',
            Ticket::PLANNED  => 'Planejados',
            Ticket::WAITING  => 'Pendentes',
            Ticket::SOLVED   => 'Solucionados',
            Ticket::CLOSED   => 'Fechados',
            default          => 'Outros',
        };
    }

    private static function statusClass(int $status): string
    {
        return match ($status) {
            Ticket::INCOMING => 'primary',
            Ticket::ASSIGNED => 'info',
            Ticket::PLANNED  => 'secondary',
            Ticket::WAITING  => 'warning',
            Ticket::SOLVED   => 'success',
            Ticket::CLOSED   => 'dark',
            default          => 'light',
        };
    }

    private static function displayLabel(string $value): string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return '—';
        }

        $map = [
            'srv' => 'SRV · Servidores',
            'sw'  => 'SW · Switches',
            'db'  => 'DB · Banco de Dados',
            'web' => 'WEB · Portais',
            'fw'  => 'FW · Firewalls',
            'cpu_high' => 'CPU alta',
            'memory_high' => 'Memória alta',
            'disk_full' => 'Disco cheio',
            'service_down' => 'Serviço indisponível',
            'host_unavailable' => 'Host indisponível',
            'packet_loss' => 'Perda de pacotes',
            'latency_high' => 'Latência alta',
            'average' => 'Average',
            'high' => 'High',
            'warning' => 'Warning',
            'disaster' => 'Disaster',
            'information' => 'Information',
        ];

        $lower = mb_strtolower($trimmed);
        if (isset($map[$lower])) {
            return $map[$lower];
        }

        return $trimmed;
    }

    private static function shortTitle(string $value, int $limit = 64): string
    {
        $value = trim($value);
        if (mb_strlen($value) <= $limit) {
            return $value;
        }
        return mb_substr($value, 0, $limit - 1) . '…';
    }

    private static function sortCounts(array $counts): array
    {
        arsort($counts, SORT_NUMERIC);
        return $counts;
    }

    private static function pushPositive(array &$values, mixed $value): void
    {
        if ($value === null || $value === '') {
            return;
        }

        $number = (float) $value;
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

    private static function percentile(array $values, int $percentile): ?float
    {
        if ($values === []) {
            return null;
        }
        sort($values, SORT_NUMERIC);
        $n = count($values);
        if ($n === 1) {
            return (float) $values[0];
        }
        $rank = ($percentile / 100) * ($n - 1);
        $low = (int) floor($rank);
        $high = (int) ceil($rank);
        if ($low === $high) {
            return (float) $values[$low];
        }
        $weight = $rank - $low;
        return (float) ($values[$low] + (($values[$high] - $values[$low]) * $weight));
    }
}
