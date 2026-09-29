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
    /** Marcador correto: tempo histórico entre abertura e SOLUÇÃO. */
    public const MARKER = '[IOS-ACTIONTIME-SOLUTION-V2]';

    /** Marcador da versão 0.9.0, que usava abertura→fechamento. */
    public const LEGACY_MARKER = '[IOS-ACTIONTIME-CYCLE-V1]';

    public static function cronInfo($name): array
    {
        if ($name === 'ActionTimeBackfill') {
            return [
                'description' => __('Preenche o actiontime de tickets fechados com o tempo histórico abertura→solução, por meio de task auditável.', 'iosindicators'),
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
        $task->setVolume((int) ($result['created'] + $result['migrated'] + $result['updated']));

        $message = sprintf(
            'IOS Indicators ActionTime: lidos=%d, elegíveis=%d, criados=%d, migrados=%d, atualizados=%d, já_processados=%d, preservados=%d, ignorados=%d, erros=%d',
            $result['read'],
            $result['eligible'],
            $result['created'],
            $result['migrated'],
            $result['updated'],
            $result['already_processed'],
            $result['preserved'],
            $result['ignored'],
            $result['errors']
        );

        if (!empty($result['processed'])) {
            $ids = array_map(
                static fn(array $row): string => sprintf('#%d→task#%d(%ds,%s)', (int) $row['ticket_id'], (int) $row['task_id'], (int) $row['actiontime'], (string) $row['operation']),
                array_slice($result['processed'], 0, 30)
            );
            $message .= ' | processados=' . implode(',', $ids);
        }

        $task->log($message);
        return ($result['created'] + $result['migrated'] + $result['updated']) > 0 ? 1 : 0;
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
            'migrated' => 0,
            'updated' => 0,
            'already_processed' => 0,
            'preserved' => 0,
            'ignored' => 0,
            'errors' => 0,
            'processed' => [],
        ];

        try {
            $iterator = $DB->request([
                'SELECT' => ['id', 'date', 'solvedate', 'closedate', 'actiontime'],
                'FROM' => 'glpi_tickets',
                'WHERE' => [
                    'is_deleted' => 0,
                    'status' => Ticket::CLOSED,
                ],
                'ORDER' => ['id DESC'],
                'LIMIT' => $scanLimit,
            ]);

            foreach ($iterator as $row) {
                $changed = $stats['created'] + $stats['migrated'] + $stats['updated'];
                if ($changed >= $limit) {
                    break;
                }

                $ticketId = (int) ($row['id'] ?? 0);
                if ($ticketId <= 0) {
                    continue;
                }

                $stats['read']++;

                $openedAt = (string) ($row['date'] ?? '');
                $solvedAt = (string) ($row['solvedate'] ?? '');
                $opened = strtotime($openedAt);
                $solved = strtotime($solvedAt);

                // A regra funcional validada é abertura -> solução. Fechamento não entra no cálculo.
                if ($opened === false || $solved === false || $solved <= $opened) {
                    $stats['ignored']++;
                    continue;
                }

                $seconds = $solved - $opened;
                if ($seconds <= 0) {
                    $stats['ignored']++;
                    continue;
                }

                $existing = self::findBackfillTask($ticketId);

                // Se já temos a versão V2, garantimos que o actiontime reflita a data de solução atual.
                if ($existing !== null && $existing['marker'] === self::MARKER) {
                    if ((int) $existing['actiontime'] === $seconds) {
                        $stats['already_processed']++;
                        continue;
                    }

                    try {
                        self::updateBackfillTask((int) $existing['id'], $ticketId, $seconds, $openedAt, $solvedAt, $source, false);
                        $stats['updated']++;
                        $stats['processed'][] = [
                            'ticket_id' => $ticketId,
                            'task_id' => (int) $existing['id'],
                            'actiontime' => $seconds,
                            'operation' => 'updated',
                        ];
                    } catch (Throwable $e) {
                        $stats['errors']++;
                        self::logError(sprintf('Ticket #%d: %s', $ticketId, $e->getMessage()), $e);
                    }
                    continue;
                }

                // Migração transparente da v0.9.0: a task abertura->fechamento é corrigida
                // no mesmo registro para abertura->solução, evitando duplicar actiontime.
                if ($existing !== null && $existing['marker'] === self::LEGACY_MARKER) {
                    $stats['eligible']++;
                    try {
                        self::updateBackfillTask((int) $existing['id'], $ticketId, $seconds, $openedAt, $solvedAt, $source, true);
                        $stats['migrated']++;
                        $stats['processed'][] = [
                            'ticket_id' => $ticketId,
                            'task_id' => (int) $existing['id'],
                            'actiontime' => $seconds,
                            'operation' => 'migrated',
                        ];
                    } catch (Throwable $e) {
                        $stats['errors']++;
                        self::logError(sprintf('Ticket #%d: %s', $ticketId, $e->getMessage()), $e);
                    }
                    continue;
                }

                $existingActiontime = max(0, (int) ($row['actiontime'] ?? 0));
                if ($fillOnlyZero && $existingActiontime > 0) {
                    $stats['preserved']++;
                    continue;
                }

                $stats['eligible']++;

                try {
                    $taskId = self::createBackfillTask($ticketId, $seconds, $openedAt, $solvedAt, $source);
                    $stats['created']++;
                    $stats['processed'][] = [
                        'ticket_id' => $ticketId,
                        'task_id' => $taskId,
                        'actiontime' => $seconds,
                        'operation' => 'created',
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

    /**
     * Localiza tanto a task correta V2 quanto a task legada V1.
     * Prioriza V2 quando as duas existirem.
     */
    public static function findBackfillTask(int $ticketId): ?array
    {
        global $DB;

        if (!$DB->tableExists('glpi_tickettasks')) {
            return null;
        }

        $legacy = null;

        try {
            $iterator = $DB->request([
                'SELECT' => ['id', 'content', 'actiontime'],
                'FROM' => 'glpi_tickettasks',
                'WHERE' => ['tickets_id' => $ticketId],
                'ORDER' => ['id DESC'],
            ]);

            foreach ($iterator as $row) {
                $content = html_entity_decode(strip_tags((string) ($row['content'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                if (mb_stripos($content, self::MARKER) !== false) {
                    return [
                        'id' => (int) ($row['id'] ?? 0),
                        'actiontime' => (int) ($row['actiontime'] ?? 0),
                        'marker' => self::MARKER,
                    ];
                }
                if ($legacy === null && mb_stripos($content, self::LEGACY_MARKER) !== false) {
                    $legacy = [
                        'id' => (int) ($row['id'] ?? 0),
                        'actiontime' => (int) ($row['actiontime'] ?? 0),
                        'marker' => self::LEGACY_MARKER,
                    ];
                }
            }
        } catch (Throwable $e) {
            self::logError('Falha ao verificar ActionTime existente no ticket #' . $ticketId . ': ' . $e->getMessage(), $e);
        }

        return $legacy;
    }

    public static function findBackfillTaskId(int $ticketId): int
    {
        $row = self::findBackfillTask($ticketId);
        return $row !== null ? (int) $row['id'] : 0;
    }

    private static function createBackfillTask(int $ticketId, int $seconds, string $openedAt, string $solvedAt, string $source): int
    {
        $task = new TicketTask();
        $taskId = (int) $task->add([
            'tickets_id' => $ticketId,
            'content' => self::buildContent($ticketId, $seconds, $openedAt, $solvedAt, $source, false),
            'actiontime' => $seconds,
            'state' => Planning::DONE,
            'is_private' => 0,
        ]);

        if ($taskId <= 0) {
            throw new \RuntimeException('Não foi possível criar a task de ActionTime histórico no GLPI.');
        }

        self::ensureTicketActiontimeWhenZero($ticketId, $seconds);
        return $taskId;
    }

    private static function updateBackfillTask(int $taskId, int $ticketId, int $seconds, string $openedAt, string $solvedAt, string $source, bool $migrated): void
    {
        $task = new TicketTask();
        if (!$task->getFromDB($taskId)) {
            throw new \RuntimeException('Task histórica #' . $taskId . ' não encontrada para atualização.');
        }

        $ok = $task->update([
            'id' => $taskId,
            'content' => self::buildContent($ticketId, $seconds, $openedAt, $solvedAt, $source, $migrated),
            'actiontime' => $seconds,
            'state' => Planning::DONE,
            'is_private' => 0,
        ]);

        if (!$ok) {
            throw new \RuntimeException('Não foi possível atualizar a task histórica #' . $taskId . '.');
        }

        self::ensureTicketActiontimeWhenZero($ticketId, $seconds);
    }

    private static function buildContent(int $ticketId, int $seconds, string $openedAt, string $solvedAt, string $source, bool $migrated): string
    {
        $agentName = trim((string) Settings::get('ai_agent_name', 'IOS NORA'));
        if ($agentName === '') {
            $agentName = 'IOS NORA';
        }

        $migrationLine = $migrated
            ? '<br><strong>Migração:</strong> corrigido da regra antiga abertura→fechamento para abertura→solução.'
            : '';

        return '<p><strong>' . self::MARKER . '</strong></p>'
            . '<p><strong>ActionTime histórico calculado automaticamente para fins analíticos.</strong><br>'
            . '<strong>Ticket:</strong> #' . $ticketId . '<br>'
            . '<strong>Abertura:</strong> ' . htmlescape($openedAt) . '<br>'
            . '<strong>Solução:</strong> ' . htmlescape($solvedAt) . '<br>'
            . '<strong>ActionTime histórico:</strong> ' . self::formatDuration($seconds) . ' (' . $seconds . ' segundos)<br>'
            . '<strong>Regra:</strong> data/hora da solução − data/hora da abertura<br>'
            . '<strong>Origem:</strong> IOS Indicators / ' . htmlescape($source) . '<br>'
            . '<strong>Agente de referência:</strong> ' . htmlescape($agentName)
            . $migrationLine
            . '</p>'
            . '<p><em>Este ActionTime representa o tempo histórico do incidente até a solução. A estimativa IA/RCA permanece separada para permitir a comparação Histórico × IOS NORA sem somar os dois tempos no ticket.</em></p>';
    }

    /**
     * Em algumas instalações do GLPI 11 a atualização da task pode não refletir
     * imediatamente no agregado do ticket. Só fazemos fallback quando ele está zero,
     * para não apagar outros apontamentos existentes.
     */
    private static function ensureTicketActiontimeWhenZero(int $ticketId, int $seconds): void
    {
        $ticket = new Ticket();
        if ($ticket->getFromDB($ticketId) && (int) ($ticket->fields['actiontime'] ?? 0) <= 0) {
            $ticket->update([
                'id' => $ticketId,
                'actiontime' => $seconds,
                '_disablenotif' => true,
            ]);
        }
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
