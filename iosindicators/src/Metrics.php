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

        $s = self::emptySummary();
        $diagnostics = [];

        if (!$DB->tableExists('glpi_tickets')) {
            $s['diagnostics'][] = 'Tabela glpi_tickets não encontrada.';
            return $s;
        }

        $required = ['id', 'name', 'content', 'date', 'status', 'type', 'entities_id', 'is_deleted'];
        foreach ($required as $field) {
            if (!$DB->fieldExists('glpi_tickets', $field)) {
                $s['diagnostics'][] = "Campo glpi_tickets.{$field} não encontrado.";
                return $s;
            }
        }

        $optional = [
            'itilcategories_id', 'priority', 'urgency', 'impact', 'solvedate', 'closedate',
            'takeintoaccount_delay_stat', 'solve_delay_stat', 'actiontime',
            'waiting_duration', 'close_delay_stat',
        ];
        $select = $required;
        foreach ($optional as $field) {
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
        if ((int) Settings::get('show_only_incidents', 1) === 1) {
            $where['type'] = Ticket::INCIDENT_TYPE;
        }

        $ticketIds = [];
        $categoryFlags = [];
        $acc = [
            'tto' => [], 'tts' => [], 'tma' => [], 'waiting' => [], 'close_delay' => [],
            'actiontime' => [], 'solve_delay' => [], 'total_incident' => [], 'repair' => [],
        ];
        $statusCounts = [];
        $eventCounts = [];
        $severityCounts = [];
        $equipmentCounts = [];
        $clientCounts = [];
        $hostCounts = [];
        $hostTimeline = [];

        try {
            $iterator = $DB->request([
                'SELECT' => $select,
                'FROM' => 'glpi_tickets',
                'WHERE' => $where,
                'ORDER' => ['date ASC'],
            ]);

            foreach ($iterator as $ticket) {
                $ticketId = (int) ($ticket['id'] ?? 0);
                $ticketIds[] = $ticketId;
                $s['total']++;

                $status = (int) ($ticket['status'] ?? 0);
                $label = self::statusLabel($status);
                $statusCounts[$label] = ($statusCounts[$label] ?? 0) + 1;

                switch ($status) {
                    case Ticket::INCOMING: $s['incoming']++; break;
                    case Ticket::ASSIGNED: $s['assigned']++; break;
                    case Ticket::PLANNED:  $s['planned']++; break;
                    case Ticket::WAITING:  $s['pending']++; break;
                    case Ticket::SOLVED:   $s['solved']++; break;
                    case Ticket::CLOSED:   $s['closed']++; break;
                }
                if (!in_array($status, [Ticket::SOLVED, Ticket::CLOSED], true)) {
                    $s['open']++;
                }

                $hasCategory = (int) ($ticket['itilcategories_id'] ?? 0) > 0;
                $categoryFlags[$ticketId] = $hasCategory;
                if ($hasCategory) {
                    $s['with_categories']++;
                }

                self::pushPositive($acc['tto'], $ticket['takeintoaccount_delay_stat'] ?? null);
                self::pushPositive($acc['waiting'], $ticket['waiting_duration'] ?? null);
                self::pushPositive($acc['close_delay'], $ticket['close_delay_stat'] ?? null);
                self::pushPositive($acc['actiontime'], $ticket['actiontime'] ?? null);
                self::pushPositive($acc['solve_delay'], $ticket['solve_delay_stat'] ?? null);
                self::pushPositive($acc['repair'], $ticket['solve_delay_stat'] ?? null);

                if (!empty($ticket['solvedate'])) {
                    self::pushPositive($acc['tts'], $ticket['solve_delay_stat'] ?? null);
                    self::pushPositive($acc['tma'], $ticket['actiontime'] ?? null);
                }

                $endDate = !empty($ticket['closedate']) ? $ticket['closedate'] : ($ticket['solvedate'] ?? null);
                if (!empty($ticket['date']) && !empty($endDate)) {
                    $startTs = strtotime((string) $ticket['date']);
                    $endTs = strtotime((string) $endDate);
                    if ($startTs !== false && $endTs !== false && $endTs >= $startTs) {
                        $acc['total_incident'][] = (float) ($endTs - $startTs);
                    }
                }

                $ctx = self::extractTicketContext((string) ($ticket['name'] ?? ''), (string) ($ticket['content'] ?? ''));
                self::increment($eventCounts, $ctx['event']);
                self::increment($severityCounts, $ctx['severity']);
                self::increment($equipmentCounts, $ctx['equipment_code']);
                self::increment($clientCounts, $ctx['client']);
                self::increment($hostCounts, $ctx['host']);

                if ($ctx['host'] !== null && !empty($ticket['date']) && !empty($endDate)) {
                    $openedTs = strtotime((string) $ticket['date']);
                    $endedTs = strtotime((string) $endDate);
                    if ($openedTs !== false && $endedTs !== false && $endedTs >= $openedTs) {
                        $hostTimeline[$ctx['host']][] = ['opened_ts' => $openedTs, 'ended_ts' => $endedTs];
                    }
                }
            }
        } catch (Throwable $e) {
            $diagnostics[] = 'Consulta de tickets: ' . $e->getMessage();
        }

        $s['avg_tto'] = self::average($acc['tto']);
        $s['avg_tts'] = self::average($acc['tts']);
        $s['avg_tma'] = self::average($acc['tma']);
        $s['avg_mttr'] = self::average($acc['repair']);
        $s['avg_total_incident'] = self::average($acc['total_incident']);
        $s['avg_waiting'] = self::average($acc['waiting']);
        $s['p50_mttr'] = self::percentile($acc['repair'], 50);
        $s['p90_mttr'] = self::percentile($acc['repair'], 90);
        $s['p95_mttr'] = self::percentile($acc['repair'], 95);

        $mbtrSource = (string) Settings::get('mbtr_source', 'none');
        $s['mbtr'] = match ($mbtrSource) {
            'waiting_duration' => self::average($acc['waiting']),
            'close_delay_stat' => self::average($acc['close_delay']),
            'actiontime' => self::average($acc['actiontime']),
            'solve_delay_stat' => self::average($acc['solve_delay']),
            default => null,
        };

        $s = array_merge($s, self::reliabilityMetrics($hostTimeline, $hostCounts, $s['avg_mttr']));
        $s = array_merge($s, self::enrichmentMetrics($ticketIds, $categoryFlags, $diagnostics));
        $s = array_merge($s, self::taskMetrics($ticketIds, $s['solved'] + $s['closed'], $diagnostics));

        $s['status_counts'] = self::sortCounts($statusCounts);
        $s['event_counts'] = self::sortCounts($eventCounts);
        $s['severity_counts'] = self::sortCounts($severityCounts);
        $s['equipment_counts'] = self::sortCounts($equipmentCounts);
        $s['client_counts'] = self::sortCounts($clientCounts);
        $s['host_counts'] = self::sortCounts($hostCounts);
        $s['period_from'] = $from->format('Y-m-d H:i:s');
        $s['period_to'] = $to->format('Y-m-d H:i:s');
        $s['diagnostics'] = $diagnostics;

        return $s;
    }

    private static function emptySummary(): array
    {
        return [
            'total' => 0, 'incoming' => 0, 'assigned' => 0, 'planned' => 0, 'pending' => 0,
            'solved' => 0, 'closed' => 0, 'open' => 0,
            'with_categories' => 0, 'with_items' => 0, 'with_requester_groups' => 0,
            'with_assign_groups' => 0, 'structured_tickets' => 0,
            'coverage_category_pct' => 0.0, 'coverage_item_pct' => 0.0,
            'coverage_requester_pct' => 0.0, 'coverage_assign_pct' => 0.0,
            'coverage_structured_pct' => 0.0,
            'avg_tto' => null, 'avg_tts' => null, 'avg_tma' => null, 'avg_mttr' => null,
            'avg_total_incident' => null, 'avg_waiting' => null, 'mbtr' => null,
            'mtbf' => null, 'mtbr' => null, 'availability_pct' => 0.0,
            'p50_mttr' => null, 'p90_mttr' => null, 'p95_mttr' => null,
            'recurrent_hosts' => 0, 'recurrent_tickets' => 0, 'recurrent_ticket_pct' => 0.0,
            'ai_tagged' => 0, 'rca_complete' => 0, 'remote_tasks' => 0,
            'rca_coverage_pct' => 0.0, 'rca_complete_pct' => 0.0, 'resolved_for_rca_den' => 0,
            'status_counts' => [], 'event_counts' => [], 'severity_counts' => [],
            'equipment_counts' => [], 'client_counts' => [], 'host_counts' => [],
            'period_from' => null, 'period_to' => null, 'diagnostics' => [],
        ];
    }

    private static function enrichmentMetrics(array $ticketIds, array $categoryFlags, array &$diagnostics): array
    {
        global $DB;

        $total = count($ticketIds);
        $withCategory = count(array_filter($categoryFlags));
        $items = [];
        $requesters = [];
        $assignees = [];

        if ($ticketIds !== [] && $DB->tableExists('glpi_items_tickets') && $DB->fieldExists('glpi_items_tickets', 'tickets_id')) {
            foreach (array_chunk($ticketIds, 1000) as $chunk) {
                try {
                    foreach ($DB->request([
                        'SELECT' => ['tickets_id'],
                        'FROM' => 'glpi_items_tickets',
                        'WHERE' => ['tickets_id' => $chunk],
                    ]) as $row) {
                        $items[(int) ($row['tickets_id'] ?? 0)] = true;
                    }
                } catch (Throwable $e) {
                    $diagnostics[] = 'Consulta de vínculo ticket-item: ' . $e->getMessage();
                    break;
                }
            }
        }

        if ($ticketIds !== [] && $DB->tableExists('glpi_groups_tickets') && $DB->fieldExists('glpi_groups_tickets', 'tickets_id') && $DB->fieldExists('glpi_groups_tickets', 'type')) {
            foreach (array_chunk($ticketIds, 1000) as $chunk) {
                try {
                    foreach ($DB->request([
                        'SELECT' => ['tickets_id', 'type'],
                        'FROM' => 'glpi_groups_tickets',
                        'WHERE' => ['tickets_id' => $chunk],
                    ]) as $row) {
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
        }

        $structured = 0;
        foreach ($ticketIds as $ticketId) {
            if (!empty($categoryFlags[$ticketId]) && isset($items[$ticketId], $requesters[$ticketId], $assignees[$ticketId])) {
                $structured++;
            }
        }

        $withItems = count($items);
        $withRequesters = count($requesters);
        $withAssignees = count($assignees);
        return [
            'with_items' => $withItems,
            'with_requester_groups' => $withRequesters,
            'with_assign_groups' => $withAssignees,
            'structured_tickets' => $structured,
            'coverage_category_pct' => self::ratio($withCategory, $total),
            'coverage_item_pct' => self::ratio($withItems, $total),
            'coverage_requester_pct' => self::ratio($withRequesters, $total),
            'coverage_assign_pct' => self::ratio($withAssignees, $total),
            'coverage_structured_pct' => self::ratio($structured, $total),
        ];
    }

    private static function reliabilityMetrics(array $hostTimeline, array $hostCounts, ?float $avgMttr): array
    {
        $mtbfValues = [];
        $mtbrValues = [];
        $recurrentHosts = 0;
        $recurrentTickets = 0;

        foreach ($hostTimeline as $rows) {
            $count = count($rows);
            if ($count >= 2) {
                $recurrentHosts++;
                $recurrentTickets += $count;
            }
            usort($rows, static fn(array $a, array $b): int => $a['opened_ts'] <=> $b['opened_ts']);
            for ($i = 1; $i < $count; $i++) {
                $prev = $rows[$i - 1];
                $curr = $rows[$i];
                $betweenFailures = $curr['opened_ts'] - $prev['ended_ts'];
                $betweenRepairs = $curr['ended_ts'] - $prev['ended_ts'];
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
        $availability = ($mtbf !== null && $avgMttr !== null && ($mtbf + $avgMttr) > 0)
            ? round(($mtbf / ($mtbf + $avgMttr)) * 100, 2)
            : 0.0;

        return [
            'mtbf' => $mtbf,
            'mtbr' => $mtbr,
            'availability_pct' => $availability,
            'recurrent_hosts' => $recurrentHosts,
            'recurrent_tickets' => $recurrentTickets,
            'recurrent_ticket_pct' => self::ratio($recurrentTickets, array_sum($hostCounts)),
        ];
    }

    private static function taskMetrics(array $ticketIds, int $resolved, array &$diagnostics): array
    {
        global $DB;

        $empty = [
            'ai_tagged' => 0, 'rca_complete' => 0, 'remote_tasks' => 0,
            'rca_coverage_pct' => 0.0, 'rca_complete_pct' => 0.0,
            'resolved_for_rca_den' => $resolved,
        ];
        if ($ticketIds === [] || !$DB->tableExists('glpi_tickettasks')) {
            return $empty;
        }
        foreach (['tickets_id', 'content'] as $field) {
            if (!$DB->fieldExists('glpi_tickettasks', $field)) {
                return $empty;
            }
        }

        $aiTag = trim((string) Settings::get('ai_task_tag', '[IA-RCA]'));
        $remoteTag = trim((string) Settings::get('remote_task_tag', '[REMOTO]'));
        $tagged = [];
        $complete = [];
        $remoteTasks = 0;

        try {
            foreach (array_chunk(array_values(array_unique(array_map('intval', $ticketIds))), 1000) as $chunk) {
                foreach ($DB->request([
                    'SELECT' => ['tickets_id', 'content'],
                    'FROM' => 'glpi_tickettasks',
                    'WHERE' => ['tickets_id' => $chunk],
                ]) as $task) {
                    $ticketId = (int) ($task['tickets_id'] ?? 0);
                    $content = html_entity_decode(strip_tags((string) ($task['content'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    if ($aiTag !== '' && mb_stripos($content, $aiTag) !== false) {
                        $tagged[$ticketId] = true;
                        $n = mb_strtolower($content);
                        if (mb_stripos($n, 'diagn') !== false && mb_stripos($n, 'causa') !== false && mb_stripos($n, 'solu') !== false) {
                            $complete[$ticketId] = true;
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

        return [
            'ai_tagged' => count($tagged),
            'rca_complete' => count($complete),
            'remote_tasks' => $remoteTasks,
            'rca_coverage_pct' => self::ratio(count($tagged), $resolved),
            'rca_complete_pct' => self::ratio(count($complete), $resolved),
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
            $customTo = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $request['to'] . ' 23:59:59');
            if ($customFrom && $customTo && $customFrom <= $customTo) {
                $from = $customFrom;
                $to = $customTo;
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
        if ($days > 0) return sprintf('%dd %02dh %02dm', $days, $hours, $minutes);
        if ($hours > 0) return sprintf('%dh %02dm', $hours, $minutes);
        if ($minutes > 0) return sprintf('%dm %02ds', $minutes, $secs);
        return sprintf('%ds', $secs);
    }

    public static function percent(?float $value, int $decimals = 1): string
    {
        return $value === null ? '—' : number_format($value, $decimals, ',', '.') . '%';
    }

    public static function top(array $counts, int $limit = 5): array
    {
        $counts = self::sortCounts($counts);
        $total = max(1, array_sum($counts));
        $out = [];
        foreach ($counts as $label => $count) {
            $out[] = ['label' => (string) $label, 'count' => (int) $count, 'percent' => round(((int) $count / $total) * 100, 1)];
            if (count($out) >= $limit) break;
        }
        return $out;
    }

    private static function entityIds(): array
    {
        $ids = $_SESSION['glpiactiveentities'] ?? [($_SESSION['glpiactive_entity'] ?? 0)];
        if (!is_array($ids) || $ids === []) $ids = [0];
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
        if ($host === null && preg_match('/Host:\s*([^\n]+)/iu', $full, $m)) $host = self::sanitizeHost($m[1]);
        if ($event === null && preg_match('/Problem name:\s*[^|\n]+\|\s*[A-Z0-9._:-]+\s*\|\s*([a-z0-9_-]+)/iu', $full, $m)) $event = self::sanitizeEvent($m[1]);
        if (preg_match('/Severity:\s*([^\n]+)/iu', $full, $m)) $severity = trim($m[1]);

        $client = null;
        $equipmentCode = null;
        if ($host !== null && preg_match('/^(CLIENTE-\d+)-([A-Z0-9]+)-/u', $host, $m)) {
            $client = $m[1];
            $equipmentCode = $m[2];
        }
        return ['host' => $host, 'event' => $event, 'severity' => $severity, 'client' => $client, 'equipment_code' => $equipmentCode];
    }

    private static function plainText(string $html): string
    {
        $text = html_entity_decode(strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[\t ]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/\R+/u', "\n", $text) ?? $text;
        return trim($text);
    }

    private static function sanitizeHost(string $value): ?string
    {
        $value = trim($value);
        $value = preg_replace('/\s+.*/u', '', $value) ?? $value;
        $value = preg_replace('/[^A-Za-z0-9_.:-]/u', '', $value) ?? '';
        return ($value === '' || mb_strlen($value) > 255) ? null : mb_strtoupper($value);
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
            Ticket::PLANNED => 'Planejados',
            Ticket::WAITING => 'Pendentes',
            Ticket::SOLVED => 'Solucionados',
            Ticket::CLOSED => 'Fechados',
            default => 'Outros',
        };
    }

    private static function increment(array &$counts, ?string $key): void
    {
        if ($key !== null && $key !== '') $counts[$key] = ($counts[$key] ?? 0) + 1;
    }

    private static function sortCounts(array $counts): array
    {
        arsort($counts, SORT_NUMERIC);
        return $counts;
    }

    private static function pushPositive(array &$values, mixed $value): void
    {
        if ($value === null || $value === '') return;
        $number = (float) $value;
        if ($number > 0) $values[] = $number;
    }

    private static function average(array $values): ?float
    {
        return $values === [] ? null : array_sum($values) / count($values);
    }

    private static function percentile(array $values, int $percentile): ?float
    {
        if ($values === []) return null;
        sort($values, SORT_NUMERIC);
        $n = count($values);
        if ($n === 1) return (float) $values[0];
        $rank = ($percentile / 100) * ($n - 1);
        $low = (int) floor($rank);
        $high = (int) ceil($rank);
        if ($low === $high) return (float) $values[$low];
        $weight = $rank - $low;
        return (float) ($values[$low] + (($values[$high] - $values[$low]) * $weight));
    }

    private static function ratio(int $part, int $total): float
    {
        return $total > 0 ? round(($part / $total) * 100, 1) : 0.0;
    }
}
