<?php

namespace GlpiPlugin\Iosindicators;

use CommonGLPI;
use CronTask;
use Planning;
use Ticket;
use TicketTask;
use Throwable;

final class AiRca extends CommonGLPI
{
    private const DEFAULT_MODEL = 'gemini-3.5-flash-lite';

    public static function cronInfo($name): array
    {
        if ($name === 'AiRca') {
            return [
                'description' => __('Analisa tickets solucionados com Gemini, cria task [IA-RCA] e registra histórico detalhado de sucesso/falha.', 'iosindicators'),
            ];
        }

        return [];
    }

    public static function cronAiRca(CronTask $task): int
    {
        if ((int) Settings::get('ai_rca_enabled', 0) !== 1) {
            $task->log('IOS Indicators: IA/RCA desabilitada.');
            return 0;
        }

        $result = self::runBatch(null, 'cron');
        $task->setVolume((int) $result['created']);

        $message = sprintf(
            'IOS Indicators IA/RCA: lidos=%d, elegíveis=%d, criados=%d, já_analisados=%d, cooldown=%d, ignorados=%d, erros=%d',
            $result['read'],
            $result['eligible'],
            $result['created'],
            $result['already_analyzed'],
            $result['deferred'],
            $result['ignored'],
            $result['errors']
        );

        if (!empty($result['processed'])) {
            $ids = array_map(
                static fn(array $row): string => sprintf('#%d→task#%d', (int) $row['ticket_id'], (int) $row['task_id']),
                array_slice($result['processed'], 0, 20)
            );
            $message .= ' | processados=' . implode(',', $ids);
        }

        $task->log($message);
        return $result['created'] > 0 ? 1 : 0;
    }

    public static function runBatch(?int $limit = null, string $source = 'manual'): array
    {
        global $DB;

        $limit = $limit ?? (int) Settings::get('ai_rca_batch_size', 5);
        $limit = max(1, min(50, $limit));
        $scanLimit = max($limit, min(5000, (int) Settings::get('ai_rca_scan_limit', 500)));
        $delayMs = max(0, min(10000, (int) Settings::get('ai_rca_request_delay_ms', 1500)));
        $model = self::configuredModel();
        $batchStarted = microtime(true);

        $stats = [
            'read' => 0,
            'eligible' => 0,
            'created' => 0,
            'already_analyzed' => 0,
            'deferred' => 0,
            'ignored' => 0,
            'errors' => 0,
            'processed' => [],
            'failures' => [],
        ];

        $apiKey = self::getApiKey();
        if ($apiKey === '') {
            $stats['errors'] = 1;
            $message = 'Gemini API key ausente. Configure GEMINI_API_KEY no ambiente ou no volume /var/glpi/config.';
            self::logError($message);
            AiRcaHistory::append([
                'type' => 'batch',
                'status' => 'error',
                'source' => $source,
                'model' => $model,
                'message' => $message,
            ]);
            return $stats;
        }

        $where = [
            'is_deleted' => 0,
            'status' => [Ticket::SOLVED, Ticket::CLOSED],
        ];

        if ((int) Settings::get('show_only_incidents', 1) === 1) {
            $where['type'] = Ticket::INCIDENT_TYPE;
        }

        try {
            $iterator = $DB->request([
                'SELECT' => ['id'],
                'FROM' => 'glpi_tickets',
                'WHERE' => $where,
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

                $existingTaskId = self::findAiRcaTaskId($ticketId);
                if ($existingTaskId > 0) {
                    $stats['already_analyzed']++;
                    if (!AiRcaHistory::hasSuccess($ticketId)) {
                        AiRcaHistory::append([
                            'status' => 'existing',
                            'ticket_id' => $ticketId,
                            'task_id' => $existingTaskId,
                            'source' => $source,
                            'model' => $model,
                            'message' => 'Task IA/RCA já existente; histórico retroalimentado sem nova chamada à API.',
                        ]);
                    }
                    continue;
                }

                if (AiRcaHistory::isCoolingDown($ticketId)) {
                    $stats['deferred']++;
                    continue;
                }

                $ticket = new Ticket();
                if (!$ticket->getFromDB($ticketId)) {
                    $stats['ignored']++;
                    continue;
                }

                $stats['eligible']++;
                $attemptStarted = microtime(true);
                $apiAttempted = false;

                try {
                    $context = self::buildTicketContext($ticket);
                    if ($context === '') {
                        $stats['ignored']++;
                        AiRcaHistory::append([
                            'status' => 'error',
                            'ticket_id' => $ticketId,
                            'source' => $source,
                            'model' => $model,
                            'message' => 'Ticket sem contexto suficiente para análise.',
                        ]);
                        continue;
                    }

                    $apiAttempted = true;
                    $analysis = self::analyzeWithGemini($context, $apiKey);
                    self::validateAnalysis($analysis);
                    $taskId = self::createAiTask($ticket, $analysis);
                    $stats['created']++;

                    $processed = [
                        'ticket_id' => $ticketId,
                        'task_id' => $taskId,
                        'estimated_minutes' => (int) $analysis['esforco_estimado_minutos'],
                        'confidence' => (int) $analysis['confianca'],
                        'model' => (string) ($analysis['_model'] ?? $model),
                    ];
                    $stats['processed'][] = $processed;

                    $usage = is_array($analysis['_usage'] ?? null) ? $analysis['_usage'] : [];
                    AiRcaHistory::append([
                        'status' => 'success',
                        'ticket_id' => $ticketId,
                        'task_id' => $taskId,
                        'source' => $source,
                        'model' => $processed['model'],
                        'duration_ms' => (int) round((microtime(true) - $attemptStarted) * 1000),
                        'estimated_minutes' => $processed['estimated_minutes'],
                        'confidence' => $processed['confidence'],
                        'input_tokens' => $usage['input_tokens'] ?? null,
                        'output_tokens' => $usage['output_tokens'] ?? null,
                        'total_tokens' => $usage['total_tokens'] ?? null,
                        'message' => 'Análise concluída e task IA/RCA criada.',
                    ]);
                } catch (Throwable $e) {
                    $stats['errors']++;
                    $httpStatus = (int) $e->getCode();
                    $status = match ($httpStatus) {
                        429 => 'rate_limited',
                        503 => 'service_unavailable',
                        default => 'error',
                    };

                    $failure = [
                        'ticket_id' => $ticketId,
                        'status' => $status,
                        'http_status' => $httpStatus,
                        'message' => self::limitText($e->getMessage(), 1200),
                    ];
                    $stats['failures'][] = $failure;

                    AiRcaHistory::append([
                        'status' => $status,
                        'ticket_id' => $ticketId,
                        'source' => $source,
                        'model' => $model,
                        'http_status' => $httpStatus,
                        'duration_ms' => (int) round((microtime(true) - $attemptStarted) * 1000),
                        'message' => $failure['message'],
                    ]);

                    self::logError(sprintf('Ticket #%d: %s', $ticketId, $e->getMessage()), $e);

                    // Em 429/503 paramos o lote para evitar uma tempestade de requisições.
                    if (in_array($httpStatus, [429, 503], true)) {
                        break;
                    }
                } finally {
                    if ($apiAttempted && $delayMs > 0) {
                        usleep($delayMs * 1000);
                    }
                }
            }
        } catch (Throwable $e) {
            $stats['errors']++;
            self::logError('Falha ao selecionar tickets para IA/RCA: ' . $e->getMessage(), $e);
            AiRcaHistory::append([
                'type' => 'batch',
                'status' => 'error',
                'source' => $source,
                'model' => $model,
                'message' => 'Falha ao selecionar tickets: ' . self::limitText($e->getMessage(), 1200),
            ]);
        }

        AiRcaHistory::append([
            'type' => 'batch',
            'status' => $stats['errors'] > 0 ? 'error' : 'success',
            'source' => $source,
            'model' => $model,
            'duration_ms' => (int) round((microtime(true) - $batchStarted) * 1000),
            'message' => sprintf(
                'Lote finalizado: lidos=%d, elegíveis=%d, criados=%d, já_analisados=%d, cooldown=%d, ignorados=%d, erros=%d.',
                $stats['read'],
                $stats['eligible'],
                $stats['created'],
                $stats['already_analyzed'],
                $stats['deferred'],
                $stats['ignored'],
                $stats['errors']
            ),
        ]);

        return $stats;
    }

    public static function analyzeTicketNow(int $ticketId): array
    {
        $ticket = new Ticket();
        if (!$ticket->getFromDB($ticketId)) {
            throw new \RuntimeException('Ticket não encontrado.');
        }

        if (!in_array((int) ($ticket->fields['status'] ?? 0), [Ticket::SOLVED, Ticket::CLOSED], true)) {
            throw new \RuntimeException('A análise IA/RCA só é executada para tickets solucionados ou fechados.');
        }

        $existingTaskId = self::findAiRcaTaskId($ticketId);
        if ($existingTaskId > 0) {
            throw new \RuntimeException('Este ticket já possui uma tarefa IA/RCA (#' . $existingTaskId . ').');
        }

        if (AiRcaHistory::isCoolingDown($ticketId)) {
            throw new \RuntimeException('Este ticket está em cooldown após falha recente da API.');
        }

        $apiKey = self::getApiKey();
        if ($apiKey === '') {
            throw new \RuntimeException('Gemini API key ausente. Configure GEMINI_API_KEY no ambiente ou no volume /var/glpi/config.');
        }

        $started = microtime(true);
        $model = self::configuredModel();

        try {
            $analysis = self::analyzeWithGemini(self::buildTicketContext($ticket), $apiKey);
            self::validateAnalysis($analysis);
            $taskId = self::createAiTask($ticket, $analysis);
            $usage = is_array($analysis['_usage'] ?? null) ? $analysis['_usage'] : [];

            AiRcaHistory::append([
                'status' => 'success',
                'ticket_id' => $ticketId,
                'task_id' => $taskId,
                'source' => 'manual-ticket',
                'model' => (string) ($analysis['_model'] ?? $model),
                'duration_ms' => (int) round((microtime(true) - $started) * 1000),
                'estimated_minutes' => (int) $analysis['esforco_estimado_minutos'],
                'confidence' => (int) $analysis['confianca'],
                'input_tokens' => $usage['input_tokens'] ?? null,
                'output_tokens' => $usage['output_tokens'] ?? null,
                'total_tokens' => $usage['total_tokens'] ?? null,
                'message' => 'Análise individual concluída.',
            ]);

            return [
                'ticket_id' => $ticketId,
                'task_id' => $taskId,
                'analysis' => $analysis,
            ];
        } catch (Throwable $e) {
            $httpStatus = (int) $e->getCode();
            AiRcaHistory::append([
                'status' => $httpStatus === 429 ? 'rate_limited' : ($httpStatus === 503 ? 'service_unavailable' : 'error'),
                'ticket_id' => $ticketId,
                'source' => 'manual-ticket',
                'model' => $model,
                'http_status' => $httpStatus,
                'duration_ms' => (int) round((microtime(true) - $started) * 1000),
                'message' => self::limitText($e->getMessage(), 1200),
            ]);
            throw $e;
        }
    }

    public static function hasApiKey(): bool
    {
        return self::getApiKey() !== '';
    }

    private static function findAiRcaTaskId(int $ticketId): int
    {
        global $DB;

        if (!$DB->tableExists('glpi_tickettasks')) {
            return 0;
        }

        $tag = trim((string) Settings::get('ai_task_tag', '[IA-RCA]'));
        if ($tag === '') {
            $tag = '[IA-RCA]';
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
                if (mb_stripos($content, $tag) !== false || mb_stripos($content, '[IOS-AI-RCA-V1]') !== false) {
                    return (int) ($row['id'] ?? 0);
                }
            }
        } catch (Throwable $e) {
            self::logError('Falha ao verificar tarefa IA/RCA existente: ' . $e->getMessage(), $e);
        }

        return 0;
    }

    private static function buildTicketContext(Ticket $ticket): string
    {
        global $DB;

        $ticketId = (int) $ticket->getID();
        $maxChars = max(4000, min(50000, (int) Settings::get('ai_rca_max_context_chars', 12000)));

        $parts = [];
        $parts[] = 'TICKET #' . $ticketId;
        $parts[] = 'Título: ' . self::cleanText((string) ($ticket->fields['name'] ?? ''));
        $parts[] = 'Descrição: ' . self::cleanText((string) ($ticket->fields['content'] ?? ''));
        $parts[] = 'Data de abertura: ' . (string) ($ticket->fields['date'] ?? '');
        $parts[] = 'Data de solução: ' . (string) ($ticket->fields['solvedate'] ?? '');
        $parts[] = 'Data de fechamento: ' . (string) ($ticket->fields['closedate'] ?? '');
        $parts[] = 'Prioridade: ' . (string) ($ticket->fields['priority'] ?? '');
        $parts[] = 'Urgência: ' . (string) ($ticket->fields['urgency'] ?? '');
        $parts[] = 'Impacto: ' . (string) ($ticket->fields['impact'] ?? '');

        if ($DB->tableExists('glpi_itilfollowups')) {
            try {
                $iterator = $DB->request([
                    'SELECT' => ['content', 'date'],
                    'FROM' => 'glpi_itilfollowups',
                    'WHERE' => [
                        'items_id' => $ticketId,
                        'itemtype' => Ticket::class,
                    ],
                    'ORDER' => ['date ASC'],
                    'LIMIT' => 20,
                ]);

                $i = 1;
                foreach ($iterator as $row) {
                    $text = self::cleanText((string) ($row['content'] ?? ''));
                    if ($text !== '') {
                        $parts[] = sprintf('Follow-up %d (%s): %s', $i++, (string) ($row['date'] ?? ''), $text);
                    }
                }
            } catch (Throwable $e) {
                self::logError('Falha ao coletar follow-ups do ticket #' . $ticketId . ': ' . $e->getMessage(), $e);
            }
        }

        if ($DB->tableExists('glpi_tickettasks')) {
            try {
                $iterator = $DB->request([
                    'SELECT' => ['content', 'actiontime', 'date'],
                    'FROM' => 'glpi_tickettasks',
                    'WHERE' => ['tickets_id' => $ticketId],
                    'ORDER' => ['date ASC'],
                    'LIMIT' => 20,
                ]);

                $i = 1;
                foreach ($iterator as $row) {
                    $text = self::cleanText((string) ($row['content'] ?? ''));
                    if ($text === '' || mb_stripos($text, '[IA-RCA]') !== false || mb_stripos($text, '[IOS-AI-RCA-V1]') !== false) {
                        continue;
                    }
                    $parts[] = sprintf(
                        'Tarefa humana %d (%s, actiontime=%ds): %s',
                        $i++,
                        (string) ($row['date'] ?? ''),
                        (int) ($row['actiontime'] ?? 0),
                        $text
                    );
                }
            } catch (Throwable $e) {
                self::logError('Falha ao coletar tarefas do ticket #' . $ticketId . ': ' . $e->getMessage(), $e);
            }
        }

        $context = implode("\n\n", $parts);
        $context = self::redactSensitiveData($context);
        return mb_substr($context, 0, $maxChars);
    }

    private static function analyzeWithGemini(string $context, string $apiKey): array
    {
        $model = self::configuredModel();

        $prompt = <<<'PROMPT'
Você é um analista sênior de NOC/ITSM. Analise o ticket GLPI abaixo apenas com as evidências fornecidas.

Objetivo:
1. Produzir um diagnóstico operacional conciso.
2. Identificar a causa provável, deixando claro quando for inferência.
3. Descrever a ação corretiva/recomendada que um analista executaria.
4. Classificar a complexidade como Baixa, Média ou Alta.
5. Estimar, de forma simulada e plausível, quantos minutos de atuação técnica seriam necessários para diagnosticar, corrigir e validar o incidente.
6. Informar uma confiança entre 0 e 100.

Regras:
- Não invente evidências, comandos executados ou fatos que não estejam no ticket.
- O tempo estimado é uma SIMULAÇÃO de esforço técnico, não tempo real trabalhado.
- Para alertas simples e recorrentes, use tempos menores; para investigação, correlação e recuperação complexa, use tempos maiores.
- A estimativa deve considerar diagnóstico, intervenção e validação.
- Seja conciso para reduzir tokens de saída.
- Retorne somente o JSON solicitado pelo schema.

TICKET:
PROMPT;
        $prompt .= "\n" . $context;

        $payload = [
            'model' => $model,
            'input' => $prompt,
            'response_format' => [
                'type' => 'text',
                'mime_type' => 'application/json',
                'schema' => [
                    'type' => 'object',
                    'properties' => [
                        'diagnostico' => ['type' => 'string'],
                        'causa_provavel' => ['type' => 'string'],
                        'acao_recomendada' => ['type' => 'string'],
                        'classificacao' => ['type' => 'string'],
                        'complexidade' => [
                            'type' => 'string',
                            'enum' => ['Baixa', 'Média', 'Alta'],
                        ],
                        'esforco_estimado_minutos' => [
                            'type' => 'integer',
                            'minimum' => 1,
                            'maximum' => 1440,
                        ],
                        'confianca' => [
                            'type' => 'integer',
                            'minimum' => 0,
                            'maximum' => 100,
                        ],
                        'evidencias' => [
                            'type' => 'array',
                            'items' => ['type' => 'string'],
                        ],
                    ],
                    'required' => [
                        'diagnostico',
                        'causa_provavel',
                        'acao_recomendada',
                        'classificacao',
                        'complexidade',
                        'esforco_estimado_minutos',
                        'confianca',
                        'evidencias',
                    ],
                ],
            ],
        ];

        $response = self::httpPostJson(
            'https://generativelanguage.googleapis.com/v1beta/interactions',
            $payload,
            [
                'Content-Type: application/json',
                'x-goog-api-key: ' . $apiKey,
            ]
        );

        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Resposta inválida da Gemini API.');
        }

        if (!empty($decoded['error'])) {
            $message = is_array($decoded['error']) ? (string) ($decoded['error']['message'] ?? 'Erro Gemini API') : (string) $decoded['error'];
            throw new \RuntimeException($message);
        }

        $text = self::extractInteractionText($decoded);
        if ($text === '') {
            throw new \RuntimeException('Gemini API retornou resposta sem conteúdo textual.');
        }

        $analysis = json_decode($text, true);
        if (!is_array($analysis)) {
            throw new \RuntimeException('Gemini retornou conteúdo que não pôde ser interpretado como JSON estruturado.');
        }

        $analysis['_model'] = $model;
        $analysis['_usage'] = self::extractUsage($decoded);
        return $analysis;
    }

    private static function extractInteractionText(array $decoded): string
    {
        if (!empty($decoded['output_text']) && is_string($decoded['output_text'])) {
            return trim($decoded['output_text']);
        }

        if (!empty($decoded['steps']) && is_array($decoded['steps'])) {
            $chunks = [];
            foreach ($decoded['steps'] as $step) {
                if (($step['type'] ?? '') !== 'model_output' || empty($step['content']) || !is_array($step['content'])) {
                    continue;
                }
                foreach ($step['content'] as $content) {
                    if (($content['type'] ?? '') === 'text' && isset($content['text'])) {
                        $chunks[] = (string) $content['text'];
                    }
                }
            }
            if ($chunks !== []) {
                return trim(implode("\n", $chunks));
            }
        }

        if (!empty($decoded['outputs']) && is_array($decoded['outputs'])) {
            $chunks = [];
            foreach ($decoded['outputs'] as $output) {
                if (($output['type'] ?? '') === 'text' && isset($output['text'])) {
                    $chunks[] = (string) $output['text'];
                }
            }
            if ($chunks !== []) {
                return trim(implode("\n", $chunks));
            }
        }

        return '';
    }

    private static function extractUsage(array $decoded): array
    {
        $usage = $decoded['usage_metadata'] ?? $decoded['usageMetadata'] ?? $decoded['usage'] ?? [];
        if (!is_array($usage)) {
            return [];
        }

        $input = $usage['input_token_count'] ?? $usage['promptTokenCount'] ?? $usage['input_tokens'] ?? null;
        $output = $usage['output_token_count'] ?? $usage['candidatesTokenCount'] ?? $usage['output_tokens'] ?? null;
        $total = $usage['total_token_count'] ?? $usage['totalTokenCount'] ?? $usage['total_tokens'] ?? null;

        return [
            'input_tokens' => is_numeric($input) ? (int) $input : null,
            'output_tokens' => is_numeric($output) ? (int) $output : null,
            'total_tokens' => is_numeric($total) ? (int) $total : null,
        ];
    }

    private static function validateAnalysis(array &$analysis): void
    {
        $required = [
            'diagnostico',
            'causa_provavel',
            'acao_recomendada',
            'classificacao',
            'complexidade',
            'esforco_estimado_minutos',
            'confianca',
        ];

        foreach ($required as $key) {
            if (!array_key_exists($key, $analysis)) {
                throw new \RuntimeException('Resposta IA incompleta: campo ausente ' . $key . '.');
            }
        }

        $analysis['diagnostico'] = self::limitText((string) $analysis['diagnostico'], 2500);
        $analysis['causa_provavel'] = self::limitText((string) $analysis['causa_provavel'], 2500);
        $analysis['acao_recomendada'] = self::limitText((string) $analysis['acao_recomendada'], 3000);
        $analysis['classificacao'] = self::limitText((string) $analysis['classificacao'], 255);

        $complexity = (string) $analysis['complexidade'];
        if (!in_array($complexity, ['Baixa', 'Média', 'Alta'], true)) {
            $complexity = 'Média';
        }
        $analysis['complexidade'] = $complexity;

        $minutes = (int) $analysis['esforco_estimado_minutos'];
        $analysis['esforco_estimado_minutos'] = max(1, min(1440, $minutes));
        $analysis['confianca'] = max(0, min(100, (int) $analysis['confianca']));

        $evidence = $analysis['evidencias'] ?? [];
        if (!is_array($evidence)) {
            $evidence = [];
        }
        $analysis['evidencias'] = array_slice(array_values(array_filter(array_map(
            static fn($value): string => self::limitText((string) $value, 400),
            $evidence
        ))), 0, 6);
    }

    private static function createAiTask(Ticket $ticket, array $analysis): int
    {
        $ticketId = (int) $ticket->getID();
        $tag = trim((string) Settings::get('ai_task_tag', '[IA-RCA]'));
        if ($tag === '') {
            $tag = '[IA-RCA]';
        }

        $minutes = (int) $analysis['esforco_estimado_minutos'];
        $seconds = $minutes * 60;
        $confidence = (int) $analysis['confianca'];
        $model = (string) ($analysis['_model'] ?? self::configuredModel());

        $evidenceHtml = '<em>Sem evidências adicionais estruturadas.</em>';
        if (!empty($analysis['evidencias'])) {
            $evidenceHtml = '<ul>';
            foreach ($analysis['evidencias'] as $item) {
                $evidenceHtml .= '<li>' . htmlescape((string) $item) . '</li>';
            }
            $evidenceHtml .= '</ul>';
        }

        $content = '<p><strong>' . htmlescape($tag) . ' [IOS-AI-RCA-V1]</strong></p>'
            . '<p><strong>Ticket analisado:</strong> #' . $ticketId . '<br>'
            . '<strong>Data da análise IA:</strong> ' . htmlescape(date('d/m/Y H:i:s')) . '</p>'
            . '<p><strong>Diagnóstico:</strong><br>' . nl2br(htmlescape((string) $analysis['diagnostico'])) . '</p>'
            . '<p><strong>Causa provável:</strong><br>' . nl2br(htmlescape((string) $analysis['causa_provavel'])) . '</p>'
            . '<p><strong>Ação recomendada:</strong><br>' . nl2br(htmlescape((string) $analysis['acao_recomendada'])) . '</p>'
            . '<p><strong>Classificação:</strong> ' . htmlescape((string) $analysis['classificacao']) . '<br>'
            . '<strong>Complexidade:</strong> ' . htmlescape((string) $analysis['complexidade']) . '<br>'
            . '<strong>Tempo estimado de atuação (IA):</strong> ' . $minutes . ' minutos<br>'
            . '<strong>Tempo estimado IA (segundos):</strong> ' . $seconds . '<br>'
            . '<strong>Confiança da análise:</strong> ' . $confidence . '%<br>'
            . '<strong>Modelo:</strong> ' . htmlescape($model) . '</p>'
            . '<p><strong>Evidências consideradas:</strong></p>' . $evidenceHtml
            . '<p><em>Estimativa simulada por IA para fins analíticos. Não representa tempo real trabalhado e não é gravada no actiontime do GLPI.</em></p>';

        $task = new TicketTask();
        $taskId = (int) $task->add([
            'tickets_id' => $ticketId,
            'content' => $content,
            'actiontime' => 0,
            'state' => Planning::DONE,
            'is_private' => 0,
        ]);

        if ($taskId <= 0) {
            throw new \RuntimeException('Não foi possível criar a tarefa IA/RCA no GLPI.');
        }

        return $taskId;
    }

    private static function httpPostJson(string $url, array $payload, array $headers): string
    {
        if (!function_exists('curl_init')) {
            throw new \RuntimeException('Extensão PHP cURL não está disponível no servidor.');
        }

        $timeout = max(10, min(120, (int) Settings::get('ai_rca_timeout_seconds', 45)));
        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('Não foi possível inicializar cURL.');
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            CURLOPT_CONNECTTIMEOUT => min(15, $timeout),
            CURLOPT_TIMEOUT => $timeout,
        ]);

        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false) {
            throw new \RuntimeException('Falha de comunicação com Gemini API: ' . $curlError);
        }

        if ($statusCode < 200 || $statusCode >= 300) {
            $body = mb_substr((string) $response, 0, 1500);
            throw new \RuntimeException(sprintf('Gemini API retornou HTTP %d: %s', $statusCode, $body), $statusCode);
        }

        return (string) $response;
    }

    private static function configuredModel(): string
    {
        $model = trim((string) Settings::get('ai_rca_model', self::DEFAULT_MODEL));
        if ($model === '' || $model === 'gemini-3.8-flash') {
            // Migração econômica: a análise de RCA é estruturada e não exige o modelo Flash mais caro.
            return self::DEFAULT_MODEL;
        }
        return $model;
    }

    private static function getApiKey(): string
    {
        $key = trim((string) getenv('GEMINI_API_KEY'));
        if ($key !== '') {
            return $key;
        }

        if (isset($_ENV['GEMINI_API_KEY'])) {
            $key = trim((string) $_ENV['GEMINI_API_KEY']);
            if ($key !== '') {
                return $key;
            }
        }

        $candidates = [
            '/var/glpi/config/iosindicators.env',
            '/var/glpi/config/.env',
            '/opt/glpi/glpi11/config/iosindicators.env',
            '/opt/glpi/glpi11/config/.env',
        ];
        foreach (['/var/glpi/config/*.env', '/opt/glpi/glpi11/config/*.env'] as $pattern) {
            foreach (glob($pattern) ?: [] as $file) {
                if (!in_array($file, $candidates, true)) {
                    $candidates[] = $file;
                }
            }
        }

        foreach ($candidates as $file) {
            if (!is_readable($file)) {
                continue;
            }
            $values = @parse_ini_file($file, false, INI_SCANNER_RAW);
            if (is_array($values) && !empty($values['GEMINI_API_KEY'])) {
                $key = trim((string) $values['GEMINI_API_KEY'], " \t\n\r\0\x0B\"'");
                if ($key !== '') {
                    return $key;
                }
            }
        }

        return '';
    }

    private static function cleanText(string $html): string
    {
        $text = str_ireplace(['<br>', '<br/>', '<br />', '</p>', '</div>', '</li>'], ["\n", "\n", "\n", "\n", "\n", "\n"], $html);
        $text = preg_replace('/<(p|div|li|ul|ol)[^>]*>/i', "\n", $text) ?? $text;
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[\t ]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/\R{3,}/u', "\n\n", $text) ?? $text;
        return trim($text);
    }

    private static function redactSensitiveData(string $text): string
    {
        if ((int) Settings::get('ai_rca_redact_sensitive', 1) !== 1) {
            return $text;
        }

        $text = preg_replace('/\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b/iu', '[EMAIL-REDACTED]', $text) ?? $text;
        $text = preg_replace('/(?<!\d)(?:\+?55\s*)?(?:\(?\d{2}\)?\s*)?9?\d{4}[-\s]?\d{4}(?!\d)/u', '[TELEFONE-REDACTED]', $text) ?? $text;
        $text = preg_replace('/\b\d{3}\.\d{3}\.\d{3}-\d{2}\b/u', '[CPF-REDACTED]', $text) ?? $text;
        return $text;
    }

    private static function limitText(string $text, int $limit): string
    {
        $text = trim($text);
        return mb_strlen($text) <= $limit ? $text : mb_substr($text, 0, $limit - 1) . '…';
    }

    private static function logError(string $message, ?Throwable $e = null): void
    {
        if (isset($GLOBALS['PHPLOGGER'])) {
            $context = $e !== null ? ['exception' => $e] : [];
            $GLOBALS['PHPLOGGER']->error('IOS Indicators AI/RCA: ' . $message, $context);
        }
    }
}
