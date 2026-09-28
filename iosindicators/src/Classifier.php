<?php

namespace GlpiPlugin\Iosindicators;

use CommonGLPI;
use Computer;
use Config;
use CronTask;
use ITILCategory;
use Item_Ticket;
use Ticket;
use Throwable;

final class Classifier extends CommonGLPI
{
    private const CATEGORY_MAP = [
        'cpu_high' => ['Monitoramento', 'CPU', 'Utilização alta'],
        'memory_high' => ['Monitoramento', 'Memória', 'Utilização alta'],
        'disk_full' => ['Monitoramento', 'Armazenamento', 'Espaço insuficiente'],
        'service_down' => ['Monitoramento', 'Disponibilidade', 'Serviço indisponível'],
        'host_unavailable' => ['Monitoramento', 'Disponibilidade', 'Host indisponível'],
        'packet_loss' => ['Monitoramento', 'Rede', 'Perda de pacotes'],
        'latency_high' => ['Monitoramento', 'Rede', 'Latência elevada'],
    ];

    public static function cronInfo($name): array
    {
        if ($name === 'Classifier') {
            return [
                'description' => __('Classifica tickets Zabbix, cria hosts e associa categorias automaticamente.', 'iosindicators'),
            ];
        }
        return [];
    }

    public static function cronClassifier(CronTask $task): int
    {
        if ((int) Settings::get('classifier_enabled', 0) !== 1) {
            $task->log('IOS Indicators: classificador desabilitado.');
            return 0;
        }

        $result = self::runBatch();
        $task->setVolume((int) $result['processed']);
        $task->log(sprintf(
            'IOS Indicators: lidos=%d, zabbix=%d, classificados=%d, ignorados=%d, erros=%d, cursor=%d',
            $result['read'],
            $result['zabbix'],
            $result['classified'],
            $result['ignored'],
            $result['errors'],
            $result['cursor']
        ));

        return $result['read'] > 0 ? 1 : 0;
    }

    public static function onTicketAdd(Ticket $ticket): bool
    {
        if ((int) Settings::get('classifier_enabled', 0) !== 1
            || (int) Settings::get('classifier_immediate', 1) !== 1) {
            return true;
        }

        try {
            self::classifyTicket($ticket);
        } catch (Throwable $e) {
            if (isset($GLOBALS['PHPLOGGER'])) {
                $GLOBALS['PHPLOGGER']->error(
                    'IOS Indicators: falha ao classificar ticket recém-criado: ' . $e->getMessage(),
                    ['exception' => $e]
                );
            }
        }
        return true;
    }

    public static function runBatch(?int $limit = null): array
    {
        global $DB;

        $limit = $limit ?? (int) Settings::get('classifier_batch_size', 100);
        $limit = max(1, min(1000, $limit));
        $cursor = max(0, (int) Settings::get('classifier_cursor_id', 0));

        $where = [
            'glpi_tickets.is_deleted' => 0,
            'glpi_tickets.id' => ['>', $cursor],
        ];
        if ((int) Settings::get('show_only_incidents', 1) === 1) {
            $where['glpi_tickets.type'] = Ticket::INCIDENT_TYPE;
        }

        $iterator = $DB->request([
            'SELECT' => ['glpi_tickets.id'],
            'FROM' => 'glpi_tickets',
            'WHERE' => $where,
            'ORDER' => ['glpi_tickets.id ASC'],
            'LIMIT' => $limit,
        ]);

        $stats = [
            'read' => 0,
            'zabbix' => 0,
            'classified' => 0,
            'ignored' => 0,
            'errors' => 0,
            'processed' => 0,
            'cursor' => $cursor,
        ];

        foreach ($iterator as $row) {
            $ticketId = (int) $row['id'];
            $stats['read']++;
            $stats['processed']++;
            $stats['cursor'] = $ticketId;

            $ticket = new Ticket();
            if (!$ticket->getFromDB($ticketId)) {
                $stats['ignored']++;
                continue;
            }

            try {
                $result = self::classifyTicket($ticket);
                if ($result['is_zabbix']) {
                    $stats['zabbix']++;
                    if ($result['changed']) {
                        $stats['classified']++;
                    }
                } else {
                    $stats['ignored']++;
                }
            } catch (Throwable $e) {
                $stats['errors']++;
                if (isset($GLOBALS['PHPLOGGER'])) {
                    $GLOBALS['PHPLOGGER']->error(
                        sprintf('IOS Indicators: erro no ticket #%d: %s', $ticketId, $e->getMessage()),
                        ['exception' => $e]
                    );
                }
            }
        }

        if ($stats['cursor'] > $cursor) {
            Config::setConfigurationValues(Settings::CONTEXT, [
                'classifier_cursor_id' => $stats['cursor'],
            ]);
        }

        return $stats;
    }

    public static function classifyTicket(Ticket $ticket): array
    {
        $parsed = self::parseTicket($ticket);
        if (!$parsed['is_zabbix']) {
            return [
                'is_zabbix' => false,
                'changed' => false,
                'host' => null,
                'event' => null,
                'category_id' => 0,
                'computer_id' => 0,
            ];
        }

        $changed = false;
        $entityId = (int) ($ticket->fields['entities_id'] ?? 0);
        $categoryId = 0;
        $computerId = 0;

        if ((int) Settings::get('classifier_create_categories', 1) === 1 && $parsed['event'] !== null) {
            $categoryId = self::ensureCategoryForEvent($parsed['event'], $entityId);
            $currentCategory = (int) ($ticket->fields['itilcategories_id'] ?? 0);
            $overwrite = (int) Settings::get('classifier_overwrite_category', 0) === 1;
            if ($categoryId > 0 && ($currentCategory === 0 || $overwrite) && $currentCategory !== $categoryId) {
                $ticket->update([
                    'id' => (int) $ticket->getID(),
                    'itilcategories_id' => $categoryId,
                ]);
                $ticket->getFromDB((int) $ticket->getID());
                $changed = true;
            }
        }

        if ((int) Settings::get('classifier_create_hosts', 1) === 1 && $parsed['host'] !== null) {
            $computerId = self::ensureComputer($parsed['host'], $entityId);
            if ($computerId > 0 && self::ensureTicketItemLink((int) $ticket->getID(), $computerId)) {
                $changed = true;
            }
        }

        return [
            'is_zabbix' => true,
            'changed' => $changed,
            'host' => $parsed['host'],
            'event' => $parsed['event'],
            'category_id' => $categoryId,
            'computer_id' => $computerId,
        ];
    }

    public static function parseTicket(Ticket $ticket): array
    {
        $name = trim((string) ($ticket->fields['name'] ?? ''));
        $content = self::plainText((string) ($ticket->fields['content'] ?? ''));
        $haystack = $name . "\n" . $content;

        $host = null;
        $event = null;
        $severity = null;
        $problemId = null;
        $failureStartedAt = null;

        if (preg_match('/Problem:\s*[^|]+\|\s*([^|\r\n]+)\|\s*([^|\r\n]+)/iu', $name, $m)) {
            $host = self::sanitizeHost($m[1]);
            $event = self::sanitizeEvent($m[2]);
        }

        if ($host === null && preg_match('/\bHost:\s*([^\r\n]+)/iu', $content, $m)) {
            $host = self::sanitizeHost($m[1]);
        }

        if (preg_match('/\bSeverity:\s*([^\r\n]+)/iu', $content, $m)) {
            $severity = trim($m[1]);
        }

        if (preg_match('/Original\s+problem\s+ID:\s*([0-9]+)/iu', $content, $m)) {
            $problemId = $m[1];
        }

        if (preg_match('/Problem\s+started\s+at\s+(\d{2}:\d{2}:\d{2})\s+on\s+(\d{4})[.\/-](\d{2})[.\/-](\d{2})/iu', $content, $m)) {
            $failureStartedAt = sprintf('%s-%s-%s %s', $m[2], $m[3], $m[4], $m[1]);
        }

        $isZabbix = $host !== null && $event !== null
            && (stripos($haystack, 'Problem:') !== false || stripos($haystack, 'Original problem ID') !== false);

        return [
            'is_zabbix' => $isZabbix,
            'host' => $host,
            'event' => $event,
            'severity' => $severity,
            'problem_id' => $problemId,
            'failure_started_at' => $failureStartedAt,
        ];
    }

    private static function ensureCategoryForEvent(string $event, int $entityId): int
    {
        $path = self::CATEGORY_MAP[$event] ?? [
            (string) Settings::get('classifier_root_category', 'Monitoramento'),
            'Outros',
            $event,
        ];
        $path[0] = (string) Settings::get('classifier_root_category', 'Monitoramento');

        $parentId = 0;
        foreach ($path as $name) {
            $parentId = self::ensureCategory($name, $parentId, $entityId);
            if ($parentId <= 0) {
                break;
            }
        }
        return $parentId;
    }

    private static function ensureCategory(string $name, int $parentId, int $entityId): int
    {
        $category = new ITILCategory();
        $found = $category->find([
            'name' => $name,
            'itilcategories_id' => $parentId,
            'entities_id' => $entityId,
        ], ['id ASC'], 1);

        if ($found !== []) {
            $first = reset($found);
            return (int) ($first['id'] ?? 0);
        }

        $id = $category->add([
            'name' => $name,
            'itilcategories_id' => $parentId,
            'entities_id' => $entityId,
            'is_recursive' => 1,
            'is_incident' => 1,
            'is_request' => 0,
            'is_problem' => 1,
            'comment' => 'Criada automaticamente pelo plugin IOS Indicators.',
        ]);

        return $id ? (int) $id : 0;
    }

    private static function ensureComputer(string $host, int $entityId): int
    {
        $computer = new Computer();
        $found = $computer->find([
            'name' => $host,
            'entities_id' => $entityId,
            'is_deleted' => 0,
        ], ['id ASC'], 1);

        if ($found !== []) {
            $first = reset($found);
            return (int) ($first['id'] ?? 0);
        }

        $id = $computer->add([
            'name' => $host,
            'entities_id' => $entityId,
            'comment' => "Host monitorado criado automaticamente pelo IOS Indicators a partir de ticket de monitoramento.\nOrigem lógica: Zabbix.",
        ]);

        return $id ? (int) $id : 0;
    }

    private static function ensureTicketItemLink(int $ticketId, int $computerId): bool
    {
        $link = new Item_Ticket();
        $found = $link->find([
            'tickets_id' => $ticketId,
            'itemtype' => Computer::class,
            'items_id' => $computerId,
        ], ['id ASC'], 1);

        if ($found !== []) {
            return false;
        }

        return (bool) $link->add([
            'tickets_id' => $ticketId,
            'itemtype' => Computer::class,
            'items_id' => $computerId,
        ]);
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
        if ($value === '' || mb_strlen($value) > 255) {
            return null;
        }
        return $value;
    }

    private static function sanitizeEvent(string $value): ?string
    {
        $value = trim(mb_strtolower($value));
        $value = preg_replace('/[^a-z0-9_-]/u', '', $value) ?? '';
        return $value !== '' ? mb_substr($value, 0, 120) : null;
    }
}
