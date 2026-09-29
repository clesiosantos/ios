<?php

namespace GlpiPlugin\Iosindicators;

use CommonGLPI;
use CronTask;
use Planning;
use Ticket;
use TicketTask;
use Throwable;

final class ActionTimeBackfill extends CommonGLPI
{
    public const MARKER = '[IOS-ACTIONTIME-CYCLE-V1]';

    public static function cronInfo($name): array
    {
        if ($name === 'ActionTimeBackfill') {
            return [
                'description' => __('Preenche o actiontime de tickets fechados com o tempo de ciclo abertura→fechamento, por meio de task auditável.', 'iosindicators'),
            ];
        }

        return [];
    }

    public static function cronActionTimeBackfill(CronTask $task): int
    {
        if ((int) Settings::get('actiontime_backfill_enabled', 0) !== 1) {
            $task->log('IOS Indicators: ActionTime Backfill desabilitado.');
            return 0;
        }

        $result = self::runBatch(null, 'cron');
        $task->setVolume((int) $result['created']);

        $message = sprintf(
            'IOS Indicators ActionTime: lidos=%d, elegíveis=%d, criados=%d, já_processados=%d, preservados=%d, ignorados=%d, erros=%d',
            $result['read'],
            $result['eligible'],
            $result['created'],
            $result['already_processed'],
            $result['preserved'],
            $result['ignored'],
            $result['errors']
        );

        if (!empty($result['processed'])) {
            $ids = array_map(
                static fn(array $row): string => sprintf('#%d→task#%d(%ds)', (int) $row['ticket_id'], (int) $row['task_id'], (int) $row['actiontime']),
                array_slice($result['processed'], 0, 30)
            );
            $message .= ' | processados=' . implode(',', $ids);
        }

        $task->log($message);
        return $result['created'] > 0 ? 1 : 0;
    }

    public static function runBatch(?int $limit = null, string $source = 'manual'): array
    {
        global $DB;

        $limit = $limit ?? (int) Settings::get('actiontime_batch_size', 100);
        $limit = max(1, min(1000, $limit));
        $scanLimit = max($limit, min(10000, (int) Settings::get('actiontime_scan_limit', 5000)));
        $fillOnlyZero = (int) Settings::get('actiontime_fill_only_zero', 1) === 1;

        $stats = [
            'read' => 0,
            'eligible' => 0,
            'created' => 0,
            'already_processed' => 0,
            'preserved' => 0,
            'ignored' => 0,
            'errors' => 0,
            'processed' => [],
        ];

        try {
            $iterator = $DB->request([
                'SELECT' => ['id', 'date', 'closedate', 'actiontime'],
                'FROM' => 'glpi_tickets',
                'WHERE' => [
                    'is_deleted' => 0,
                    'status' => Ticket::CLOSED,
                ],
                'ORDER' => ['id DESC'],
                'LIMIT' => $scanLimit,
            ]);

            foreach ($iterator as $row) {
                if ($stats['created'] >= $limit) {
                    break;
                }

                $ticketId = (int) ($row['id'] ?? 0);
                if ($ticketId <= 0) {
                    continue;
                }

                $stats['read']++;

                if (self::findBackfillTaskId($ticketId) > 0) {
                    $stats['already_processed']++;
                    continue;
                }

                $opened = strtotime((string) ($row['date'] ?? ''));
                $closed = strtotime((string) ($row['closedate'] ?? ''));
                if ($opened === false || $closed === false || $closed <= $opened) {
                    $stats['ignored']++;
                    continue;
                }

                $existingActiontime = max(0, (int) ($row['actiontime'] ?? 0));
                if ($fillOnlyZero && $existingActiontime > 0) {
                    $stats['preserved']++;
                    continue;
                }

                $seconds = $closed - $opened;
                if ($seconds <= 0) {
                    $stats['ignored']++;
                    continue;
                }

                $stats['eligible']++;

                try {
                    $taskId = self::createBackfillTask($ticketId, $seconds, (string) $row['date'], (string) $row['closedate'], $source);
                    $stats['created']++;
                    $stats['processed'][] = [
                        'ticket_id' => $ticketId,
                        'task_id' => $taskId,
                        'actiontime' => $seconds,
                    ];
                } catch (Throwable $e) {
                    $stats['errors']++;
                    self::logError(sprintf('Ticket #%d: %s', $ticketId, $e->getMessage()), $e);
                }
            }
        } catch (Throwable $e) {
            $stats['errors']++;
            self::logError('Falha ao selecionar tickets fechados para ActionTime: ' . $e->getMessage(), $e);
        }

        return $stats;
    }

    public static function findBackfillTaskId(int $ticketId): int
    {
        global $DB;

        if (!$DB->tableExists('glpi_tickettasks')) {
            return 0;
        }

        try {
            $iterator = $DB->request([
                'SELECT' => ['id', 'content'],
                'FROM' => 'glpi_tickettasks',
                'WHERE' => ['tickets_id' => $ticketId],
                'ORDER' => ['id DESC'],
            ]);

            foreach ($iterator as $row) {
                $content = html_entity_decode(strip_tags((string) ($row['content'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                if (mb_stripos($content, self::MARKER) !== false) {
                    return (int) ($row['id'] ?? 0);
                }
            }
        } catch (Throwable $e) {
            self::logError('Falha ao verificar ActionTime existente no ticket #' . $ticketId . ': ' . $e->getMessage(), $e);
        }

        return 0;
    }

    private static function createBackfillTask(int $ticketId, int $seconds, string $openedAt, string $closedAt, string $source): int
    {
        $agentName = trim((string) Settings::get('ai_agent_name', 'IOS NORA'));
        if ($agentName === '') {
            $agentName = 'IOS NORA';
        }

        $content = '<p><strong>' . self::MARKER . '</strong></p>'
            . '<p><strong>ActionTime calculado automaticamente para fins analíticos.</strong><br>'
            . '<strong>Ticket:</strong> #' . $ticketId . '<br>'
            . '<strong>Abertura:</strong> ' . htmlescape($openedAt) . '<br>'
            . '<strong>Fechamento:</strong> ' . htmlescape($closedAt) . '<br>'
            . '<strong>Tempo de ciclo registrado:</strong> ' . self::formatDuration($seconds) . ' (' . $seconds . ' segundos)<br>'
            . '<strong>Origem:</strong> IOS Indicators / ' . htmlescape($source) . '<br>'
            . '<strong>Agente de referência:</strong> ' . htmlescape($agentName) . '</p>'
            . '<p><em>Este ActionTime representa a diferença abertura→fechamento e deve ser interpretado como tempo de ciclo operacional, não como apontamento humano real de esforço.</em></p>';

        $task = new TicketTask();
        $taskId = (int) $task->add([
            'tickets_id' => $ticketId,
            'content' => $content,
            'actiontime' => $seconds,
            'state' => Planning::DONE,
            'is_private' => 0,
        ]);

        if ($taskId <= 0) {
            throw new \RuntimeException('Não foi possível criar a task de ActionTime no GLPI.');
        }

        // Em versões do GLPI 11 onde a task não propaga imediatamente o actiontime
        // para o ticket, fazemos um fallback somente quando o ticket continua zerado.
        $ticket = new Ticket();
        if ($ticket->getFromDB($ticketId) && (int) ($ticket->fields['actiontime'] ?? 0) <= 0) {
            $ticket->update([
                'id' => $ticketId,
                'actiontime' => $seconds,
                '_disablenotif' => true,
            ]);
        }

        return $taskId;
    }

    private static function formatDuration(int $seconds): string
    {
        $seconds = max(0, $seconds);
        $days = intdiv($seconds, 86400);
        $hours = intdiv($seconds % 86400, 3600);
        $minutes = intdiv($seconds % 3600, 60);

        if ($days > 0) {
            return sprintf('%dd %02dh %02dm', $days, $hours, $minutes);
        }
        if ($hours > 0) {
            return sprintf('%dh %02dm', $hours, $minutes);
        }
        return sprintf('%dm', max(1, $minutes));
    }

    private static function logError(string $message, ?Throwable $e = null): void
    {
        if (isset($GLOBALS['PHPLOGGER'])) {
            $context = $e !== null ? ['exception' => $e] : [];
            $GLOBALS['PHPLOGGER']->error('IOS Indicators ActionTime: ' . $message, $context);
        }
    }
}
