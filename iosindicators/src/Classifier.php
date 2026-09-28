<?php

namespace GlpiPlugin\Iosindicators;

use CommonGLPI;
use CommonITILActor;
use Computer;
use Config;
use CronTask;
use Group;
use Group_Ticket;
use ITILCategory;
use Item_Ticket;
use NetworkEquipment;
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

    /**
     * Mapeamento validado a partir da amostra atual de 793 tickets.
     * Códigos não conhecidos permanecem seguros como Computer / NOC > Outros.
     */
    private const EQUIPMENT_MAP = [
        'SRV' => [
            'label' => 'Servidor',
            'itemtype' => Computer::class,
            'noc_group' => 'Servidores',
        ],
        'DB' => [
            'label' => 'Banco de Dados',
            'itemtype' => Computer::class,
            'noc_group' => 'Banco de Dados',
        ],
        'WEB' => [
            'label' => 'Portal WEB',
            'itemtype' => Computer::class,
            'noc_group' => 'Portais WEB',
        ],
        'SW' => [
            'label' => 'Switch',
            'itemtype' => NetworkEquipment::class,
            'noc_group' => 'Switches',
        ],
        'FW' => [
            'label' => 'Firewall',
            'itemtype' => NetworkEquipment::class,
            'noc_group' => 'Firewalls',
        ],
    ];

    public static function cronInfo($name): array
    {
        if ($name === 'Classifier') {
            return [
                'description' => __('Classifica tickets de monitoramento, cria ativos, requester e grupos NOC automaticamente.', 'iosindicators'),
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
        // O processamento imediato é independente do cron. Assim podemos manter a
        // ação automática desabilitada durante os testes sem perder tickets novos.
        if ((int) Settings::get('classifier_immediate', 1) !== 1) {
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
                'client' => null,
                'equipment_code' => null,
                'itemtype' => null,
                'item_id' => 0,
                'category_id' => 0,
                'requester_group_id' => 0,
                'assigned_group_id' => 0,
            ];
        }

        $changed = false;
        $entityId = (int) ($ticket->fields['entities_id'] ?? 0);
        $categoryId = 0;
        $itemId = 0;
        $requesterGroupId = 0;
        $assignedGroupId = 0;

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

        $equipment = self::equipmentProfile((string) $parsed['host']);

        if ((int) Settings::get('classifier_create_hosts', 1) === 1 && $parsed['host'] !== null) {
            $itemId = self::ensureAsset(
                $parsed['host'],
                $entityId,
                $equipment['itemtype'],
                $equipment['label'],
                $equipment['code']
            );

            if ($itemId > 0 && self::ensureTicketItemLink(
                (int) $ticket->getID(),
                $equipment['itemtype'],
                $itemId
            )) {
                $changed = true;
            }

            // Corrige os vínculos criados pela v0.2.2, que tratava todos os hosts
            // como Computer. O ativo antigo é preservado; somente o vínculo do ticket
            // é removido quando o tipo correto passou a ser NetworkEquipment.
            if ($equipment['itemtype'] === NetworkEquipment::class) {
                self::removeLegacyComputerLink((int) $ticket->getID(), $parsed['host'], $entityId);
            }
        }

        if ((int) Settings::get('classifier_assign_requester', 1) === 1 && $parsed['client'] !== null) {
            $requesterGroupId = self::ensureGroup(
                $parsed['client'],
                0,
                $entityId,
                true,
                false
            );
            if ($requesterGroupId > 0 && self::ensureTicketGroupLink(
                (int) $ticket->getID(),
                $requesterGroupId,
                CommonITILActor::REQUESTER
            )) {
                $changed = true;
            }
        }

        if ((int) Settings::get('classifier_assign_noc', 1) === 1) {
            $nocRoot = (string) Settings::get('classifier_noc_root_group', 'NOC');
            $nocRootId = self::ensureGroup($nocRoot, 0, $entityId, false, true);
            if ($nocRootId > 0) {
                $assignedGroupId = self::ensureGroup(
                    $equipment['noc_group'],
                    $nocRootId,
                    $entityId,
                    false,
                    true
                );
                if ($assignedGroupId > 0 && self::ensureTicketGroupLink(
                    (int) $ticket->getID(),
                    $assignedGroupId,
                    CommonITILActor::ASSIGN
                )) {
                    $changed = true;
                }
            }
        }

        return [
            'is_zabbix' => true,
            'changed' => $changed,
            'host' => $parsed['host'],
            'event' => $parsed['event'],
            'client' => $parsed['client'],
            'equipment_code' => $equipment['code'],
            'itemtype' => $equipment['itemtype'],
            'item_id' => $itemId,
            'category_id' => $categoryId,
            'requester_group_id' => $requesterGroupId,
            'assigned_group_id' => $assignedGroupId,
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

        // Ticket ainda aberto: Problem: SIM | HOST | evento | descrição
        if (preg_match('/Problem:\s*[^|]+\|\s*([^|\r\n]+)\|\s*([^|\r\n]+)/iu', $name, $m)) {
            $host = self::sanitizeHost($m[1]);
            $event = self::sanitizeEvent($m[2]);
        }

        // Ticket já solucionado pelo Zabbix: Resolved in 1h 20m 0s: SIM | HOST | evento | descrição
        if (($host === null || $event === null)
            && preg_match('/Resolved\s+in\s+[^:]+:\s*[^|]+\|\s*([^|\r\n]+)\|\s*([^|\r\n]+)/iu', $name, $m)) {
            $host = self::sanitizeHost($m[1]);
            $event = self::sanitizeEvent($m[2]);
        }

        // O follow-up/descrição de tickets solucionados traz "PROBLEM NAME".
        if (($host === null || $event === null)
            && preg_match('/Problem\s+name:\s*[^|\r\n]+\|\s*([^|\r\n]+)\|\s*([^|\r\n]+)/iu', $content, $m)) {
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

        $zabbixEvidence = stripos($haystack, 'Original problem ID') !== false
            || stripos($haystack, 'Link to problem in Zabbix') !== false
            || stripos($haystack, 'Problem name:') !== false
            || stripos($name, 'Problem:') !== false
            || stripos($name, 'Resolved in ') !== false;

        $isZabbix = $host !== null && $event !== null && $zabbixEvidence;
        $identity = self::parseHostIdentity($host);

        return [
            'is_zabbix' => $isZabbix,
            'host' => $host,
            'event' => $event,
            'severity' => $severity,
            'problem_id' => $problemId,
            'failure_started_at' => $failureStartedAt,
            'client' => $identity['client'],
            'equipment_code' => $identity['equipment_code'],
        ];
    }

    private static function parseHostIdentity(?string $host): array
    {
        if ($host === null) {
            return ['client' => null, 'equipment_code' => null];
        }

        if (preg_match('/^(CLIENTE-[0-9]+)-([A-Z0-9]+)-/iu', $host, $m)) {
            return [
                'client' => mb_strtoupper($m[1]),
                'equipment_code' => mb_strtoupper($m[2]),
            ];
        }

        return ['client' => null, 'equipment_code' => null];
    }

    private static function equipmentProfile(string $host): array
    {
        $identity = self::parseHostIdentity($host);
        $code = (string) ($identity['equipment_code'] ?? '');
        $profile = self::EQUIPMENT_MAP[$code] ?? [
            'label' => $code !== '' ? $code : 'Host monitorado',
            'itemtype' => Computer::class,
            'noc_group' => 'Outros',
        ];

        return [
            'code' => $code !== '' ? $code : 'OTHER',
            'label' => $profile['label'],
            'itemtype' => $profile['itemtype'],
            'noc_group' => $profile['noc_group'],
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

    private static function ensureAsset(
        string $host,
        int $entityId,
        string $itemtype,
        string $logicalType,
        string $code
    ): int {
        $asset = $itemtype === NetworkEquipment::class
            ? new NetworkEquipment()
            : new Computer();

        $found = $asset->find([
            'name' => $host,
            'entities_id' => $entityId,
            'is_deleted' => 0,
        ], ['id ASC'], 1);

        if ($found !== []) {
            $first = reset($found);
            return (int) ($first['id'] ?? 0);
        }

        $id = $asset->add([
            'name' => $host,
            'entities_id' => $entityId,
            'comment' => sprintf(
                "Host monitorado criado automaticamente pelo IOS Indicators.\nOrigem lógica: Zabbix.\nCódigo: %s.\nTipo lógico: %s.",
                $code,
                $logicalType
            ),
        ]);

        return $id ? (int) $id : 0;
    }

    private static function ensureTicketItemLink(int $ticketId, string $itemtype, int $itemId): bool
    {
        $link = new Item_Ticket();
        $found = $link->find([
            'tickets_id' => $ticketId,
            'itemtype' => $itemtype,
            'items_id' => $itemId,
        ], ['id ASC'], 1);

        if ($found !== []) {
            return false;
        }

        return (bool) $link->add([
            'tickets_id' => $ticketId,
            'itemtype' => $itemtype,
            'items_id' => $itemId,
        ]);
    }

    private static function removeLegacyComputerLink(int $ticketId, string $host, int $entityId): void
    {
        $computer = new Computer();
        $found = $computer->find([
            'name' => $host,
            'entities_id' => $entityId,
            'is_deleted' => 0,
        ], ['id ASC'], 1);

        if ($found === []) {
            return;
        }

        $first = reset($found);
        $computerId = (int) ($first['id'] ?? 0);
        if ($computerId <= 0 || !$computer->getFromDB($computerId)) {
            return;
        }

        $comment = (string) ($computer->fields['comment'] ?? '');
        if (stripos($comment, 'IOS Indicators') === false) {
            return;
        }

        $link = new Item_Ticket();
        $link->deleteByCriteria([
            'tickets_id' => $ticketId,
            'itemtype' => Computer::class,
            'items_id' => $computerId,
        ]);
    }

    private static function ensureGroup(
        string $name,
        int $parentId,
        int $entityId,
        bool $requester,
        bool $assign
    ): int {
        $group = new Group();
        $found = $group->find([
            'name' => $name,
            'groups_id' => $parentId,
            'entities_id' => $entityId,
        ], ['id ASC'], 1);

        if ($found !== []) {
            $first = reset($found);
            $groupId = (int) ($first['id'] ?? 0);
            if ($groupId > 0 && $group->getFromDB($groupId)) {
                $update = ['id' => $groupId];
                $needsUpdate = false;
                if ($requester && (int) ($group->fields['is_requester'] ?? 0) !== 1) {
                    $update['is_requester'] = 1;
                    $needsUpdate = true;
                }
                if ($assign && (int) ($group->fields['is_assign'] ?? 0) !== 1) {
                    $update['is_assign'] = 1;
                    $needsUpdate = true;
                }
                if ($assign && (int) ($group->fields['is_task'] ?? 0) !== 1) {
                    $update['is_task'] = 1;
                    $needsUpdate = true;
                }
                if ($needsUpdate) {
                    $group->update($update);
                }
            }
            return $groupId;
        }

        $id = $group->add([
            'name' => $name,
            'groups_id' => $parentId,
            'entities_id' => $entityId,
            'is_recursive' => 1,
            'is_requester' => $requester ? 1 : 0,
            'is_watcher' => 0,
            'is_assign' => $assign ? 1 : 0,
            'is_task' => $assign ? 1 : 0,
            'comment' => 'Grupo criado/gerenciado automaticamente pelo plugin IOS Indicators.',
        ]);

        return $id ? (int) $id : 0;
    }

    private static function ensureTicketGroupLink(int $ticketId, int $groupId, int $actorType): bool
    {
        $link = new Group_Ticket();
        $found = $link->find([
            'tickets_id' => $ticketId,
            'groups_id' => $groupId,
            'type' => $actorType,
        ], ['id ASC'], 1);

        if ($found !== []) {
            return false;
        }

        return (bool) $link->add([
            'tickets_id' => $ticketId,
            'groups_id' => $groupId,
            'type' => $actorType,
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
        return mb_strtoupper($value);
    }

    private static function sanitizeEvent(string $value): ?string
    {
        $value = trim(mb_strtolower($value));
        $value = preg_replace('/[^a-z0-9_-]/u', '', $value) ?? '';
        return $value !== '' ? mb_substr($value, 0, 120) : null;
    }
}
