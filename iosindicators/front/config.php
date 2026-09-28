<?php

use GlpiPlugin\Iosindicators\AiRca;
use GlpiPlugin\Iosindicators\AiRcaHistory;
use GlpiPlugin\Iosindicators\Classifier;
use GlpiPlugin\Iosindicators\Settings;

include(__DIR__ . '/../../../inc/includes.php');

Session::checkRight('config', UPDATE);
Plugin::load('iosindicators');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Settings::save($_POST);

    if (isset($_POST['run_classifier_now'])) {
        $result = Classifier::runBatch();
        Session::addMessageAfterRedirect(sprintf(
            'Classificador executado: lidos=%d, Zabbix=%d, classificados=%d, ignorados=%d, erros=%d, cursor=%d.',
            $result['read'],
            $result['zabbix'],
            $result['classified'],
            $result['ignored'],
            $result['errors'],
            $result['cursor']
        ), true, $result['errors'] > 0 ? WARNING : INFO);
    } elseif (isset($_POST['run_ai_rca_now'])) {
        $result = AiRca::runBatch(null, 'manual');

        $message = sprintf(
            'IA/RCA executada: lidos=%d, elegíveis=%d, tasks criadas=%d, já analisados=%d, cooldown=%d, ignorados=%d, erros=%d.',
            $result['read'],
            $result['eligible'],
            $result['created'],
            $result['already_analyzed'],
            $result['deferred'],
            $result['ignored'],
            $result['errors']
        );

        if (!empty($result['processed'])) {
            $items = [];
            foreach ($result['processed'] as $item) {
                $items[] = sprintf('Ticket #%d → Task #%d', $item['ticket_id'], $item['task_id']);
            }
            $message .= ' Processados: ' . implode(' | ', $items) . '.';
        }

        if (!empty($result['failures'])) {
            $items = [];
            foreach (array_slice($result['failures'], 0, 10) as $failure) {
                $items[] = sprintf(
                    'Ticket #%d [%s%s]: %s',
                    $failure['ticket_id'],
                    $failure['status'],
                    !empty($failure['http_status']) ? '/HTTP ' . $failure['http_status'] : '',
                    mb_substr((string) $failure['message'], 0, 220)
                );
            }
            $message .= ' Falhas: ' . implode(' | ', $items) . '.';
        }

        Session::addMessageAfterRedirect($message, true, $result['errors'] > 0 ? WARNING : INFO);
    } elseif (isset($_POST['reset_classifier_cursor'])) {
        Session::addMessageAfterRedirect(__('Cursor do classificador reiniciado. Na próxima execução o histórico será reavaliado.', 'iosindicators'), true, INFO);
    } else {
        Session::addMessageAfterRedirect(__('Configurações salvas.', 'iosindicators'), true, INFO);
    }

    Html::redirect($_SERVER['PHP_SELF']);
}

$config = Settings::all();
$hasGeminiKey = AiRca::hasApiKey();
$history = AiRcaHistory::recent(100);
$historySummary = AiRcaHistory::summary(1000);

Html::header(
    __('IOS - Indicadores de Incidentes', 'iosindicators'),
    $_SERVER['PHP_SELF'],
    'config',
    'plugins'
);

echo '<div class="container py-3" style="max-width: 1250px">';

echo '<div class="card mb-3"><div class="card-header"><strong>Configuração dos Indicadores</strong></div><div class="card-body">';
echo '<form method="post">';
echo '<div class="row g-3">';
echo '<div class="col-md-4"><label class="form-label">Período padrão (dias)</label><input class="form-control" type="number" min="1" max="3650" name="default_period_days" value="' . (int)$config['default_period_days'] . '"></div>';
echo '<div class="col-md-4"><label class="form-label">Etiqueta da tarefa IA / RCA</label><input class="form-control" type="text" name="ai_task_tag" value="' . htmlescape((string)$config['ai_task_tag']) . '"><div class="form-text">Ex.: [IA-RCA]</div></div>';
echo '<div class="col-md-4"><label class="form-label">Etiqueta de atividade remota</label><input class="form-control" type="text" name="remote_task_tag" value="' . htmlescape((string)$config['remote_task_tag']) . '"><div class="form-text">Ex.: [REMOTO]</div></div>';
echo '<div class="col-md-6"><label class="form-label">Marcador/origem Zabbix</label><input class="form-control" type="text" name="zabbix_marker" value="' . htmlescape((string)$config['zabbix_marker']) . '"></div>';
echo '<div class="col-md-6"><label class="form-label">Fonte provisória do MBTR</label><select class="form-select" name="mbtr_source">';
$options = [
    'none' => 'Não definido — exibir “—”',
    'waiting_duration' => 'waiting_duration — tempo médio em espera',
    'close_delay_stat' => 'close_delay_stat — solução até fechamento',
    'actiontime' => 'actiontime — tempo ativo registrado',
    'solve_delay_stat' => 'solve_delay_stat — tempo até solução',
];
foreach ($options as $value => $label) {
    $selected = ((string)$config['mbtr_source'] === $value) ? ' selected' : '';
    echo '<option value="' . htmlescape($value) . '"' . $selected . '>' . htmlescape($label) . '</option>';
}
echo '</select></div>';
echo '<div class="col-12"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="show_only_incidents" id="show_only_incidents"' . ((int)$config['show_only_incidents'] === 1 ? ' checked' : '') . '><label class="form-check-label" for="show_only_incidents">Considerar somente tickets do tipo Incidente</label></div></div>';
echo '</div>';

echo '<hr class="my-4"><h3 class="h4">Classificador automático GLPI</h3><p class="text-muted">Usa somente os dados já recebidos no ticket. Não consulta nem altera a API do Zabbix.</p>';
echo '<div class="row g-3">';
echo '<div class="col-md-3"><label class="form-label">Categoria raiz</label><input class="form-control" type="text" name="classifier_root_category" value="' . htmlescape((string)$config['classifier_root_category']) . '"></div>';
echo '<div class="col-md-3"><label class="form-label">Grupo NOC raiz</label><input class="form-control" type="text" name="classifier_noc_root_group" value="' . htmlescape((string)$config['classifier_noc_root_group']) . '"></div>';
echo '<div class="col-md-3"><label class="form-label">Tickets por lote</label><input class="form-control" type="number" min="1" max="1000" name="classifier_batch_size" value="' . (int)$config['classifier_batch_size'] . '"></div>';
echo '<div class="col-md-3"><label class="form-label">Cursor atual</label><input class="form-control" type="text" readonly value="#' . (int)$config['classifier_cursor_id'] . '"></div>';
foreach ([
    'classifier_enabled' => 'Habilitar ação automática do classificador',
    'classifier_immediate' => 'Classificar tickets novos imediatamente',
    'classifier_create_categories' => 'Criar/associar categorias automaticamente',
    'classifier_create_hosts' => 'Criar/associar ativo conforme o tipo do host',
    'classifier_assign_requester' => 'Adicionar CLIENTE-XX como grupo Requester',
    'classifier_assign_noc' => 'Atribuir ao subgrupo NOC pelo tipo de equipamento',
    'classifier_overwrite_category' => 'Sobrescrever categoria existente',
] as $key => $label) {
    echo '<div class="col-md-6"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="' . htmlescape($key) . '" id="' . htmlescape($key) . '"' . ((int)$config[$key] === 1 ? ' checked' : '') . '><label class="form-check-label" for="' . htmlescape($key) . '">' . htmlescape($label) . '</label></div></div>';
}
echo '</div>';

echo '<hr class="my-4">';
echo '<div class="d-flex align-items-center justify-content-between flex-wrap gap-2"><div><h3 class="h4 mb-1">IA / RCA com Gemini</h3><p class="text-muted mb-0">Gera RCA estruturada, registra sucesso/falha e estima esforço sem alterar o actiontime real.</p></div>';
echo '<span class="badge ' . ($hasGeminiKey ? 'bg-success' : 'bg-warning text-dark') . '">' . ($hasGeminiKey ? 'GEMINI_API_KEY disponível' : 'GEMINI_API_KEY não configurada') . '</span></div>';

echo '<div class="alert alert-info mt-3"><strong>Modo econômico:</strong> recomendamos <code>gemini-3.5-flash-lite</code> para este fluxo de análise estruturada em alto volume. A rotina também aplica intervalo entre chamadas e cooldown em erros 429/503 para reduzir consumo e evitar tempestade de requisições.</div>';

echo '<div class="row g-3 mt-1">';
echo '<div class="col-md-6"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="ai_rca_enabled" id="ai_rca_enabled"' . ((int)$config['ai_rca_enabled'] === 1 ? ' checked' : '') . '><label class="form-check-label" for="ai_rca_enabled">Habilitar ação automática IOS - AI RCA</label><div class="form-text">Procura tickets Solved/Closed sem task [IA-RCA].</div></div></div>';
echo '<div class="col-md-6"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="ai_rca_redact_sensitive" id="ai_rca_redact_sensitive"' . ((int)$config['ai_rca_redact_sensitive'] === 1 ? ' checked' : '') . '><label class="form-check-label" for="ai_rca_redact_sensitive">Redigir e-mails, telefones e CPF antes do envio</label></div></div>';

echo '<div class="col-md-4"><label class="form-label">Modelo Gemini</label><select class="form-select" name="ai_rca_model">';
$modelOptions = [
    'gemini-3.5-flash-lite' => 'Gemini 3.5 Flash-Lite — econômico / recomendado',
    'gemini-3.8-flash' => 'Gemini 3.8 Flash — maior capacidade',
];
foreach ($modelOptions as $value => $label) {
    $selected = ((string)$config['ai_rca_model'] === $value) ? ' selected' : '';
    echo '<option value="' . htmlescape($value) . '"' . $selected . '>' . htmlescape($label) . '</option>';
}
echo '</select></div>';
echo '<div class="col-md-2"><label class="form-label">Tasks por lote</label><input class="form-control" type="number" min="1" max="50" name="ai_rca_batch_size" value="' . (int)$config['ai_rca_batch_size'] . '"></div>';
echo '<div class="col-md-2"><label class="form-label">Janela de busca</label><input class="form-control" type="number" min="1" max="5000" name="ai_rca_scan_limit" value="' . (int)$config['ai_rca_scan_limit'] . '"></div>';
echo '<div class="col-md-2"><label class="form-label">Contexto máx.</label><input class="form-control" type="number" min="4000" max="50000" name="ai_rca_max_context_chars" value="' . (int)$config['ai_rca_max_context_chars'] . '"><div class="form-text">Caracteres enviados.</div></div>';
echo '<div class="col-md-2"><label class="form-label">Timeout</label><input class="form-control" type="number" min="10" max="120" name="ai_rca_timeout_seconds" value="' . (int)$config['ai_rca_timeout_seconds'] . '"><div class="form-text">Segundos.</div></div>';
echo '<div class="col-md-3"><label class="form-label">Intervalo entre chamadas</label><div class="input-group"><input class="form-control" type="number" min="0" max="10000" step="100" name="ai_rca_request_delay_ms" value="' . (int)$config['ai_rca_request_delay_ms'] . '"><span class="input-group-text">ms</span></div><div class="form-text">Recomendado: 1500 ms.</div></div>';
echo '<div class="col-md-3"><label class="form-label">Cooldown após falha</label><div class="input-group"><input class="form-control" type="number" min="1" max="1440" name="ai_rca_failure_cooldown_minutes" value="' . (int)$config['ai_rca_failure_cooldown_minutes'] . '"><span class="input-group-text">min</span></div><div class="form-text">Evita repetir ticket com 429/503 em toda execução.</div></div>';
echo '</div>';

echo '<div class="alert alert-warning mt-3 mb-3"><strong>Segredo da API:</strong> a chave continua fora do banco/GitHub. O plugin usa o volume <code>/var/glpi/config</code> montado a partir de <code>/opt/glpi/glpi11/config</code>.</div>';
echo '<div class="alert alert-secondary"><strong>Histórico:</strong> cada sucesso, falha HTTP, timeout e lote executado é gravado em <code>' . htmlescape(AiRcaHistory::filePathForDisplay()) . '</code>. Tasks existentes também são retroalimentadas no histórico sem nova chamada à Gemini.</div>';

echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);
echo '<div class="d-flex flex-wrap gap-2">';
echo '<button type="submit" class="btn btn-primary"><i class="ti ti-device-floppy"></i> Salvar</button>';
echo '<button type="submit" name="run_classifier_now" value="1" class="btn btn-success"><i class="ti ti-player-play"></i> Processar classificador</button>';
echo '<button type="submit" name="run_ai_rca_now" value="1" class="btn btn-info"' . (!$hasGeminiKey ? ' disabled' : '') . '><i class="ti ti-brain"></i> Processar IA/RCA agora</button>';
echo '<button type="submit" name="reset_classifier_cursor" value="1" class="btn btn-outline-danger" onclick="return confirm(\'Reiniciar o cursor fará o histórico ser reavaliado. Continuar?\')"><i class="ti ti-refresh"></i> Reiniciar processamento histórico</button>';
echo '</div></form></div></div>';

// Histórico IA/RCA.
echo '<div class="card mb-3"><div class="card-header d-flex justify-content-between align-items-center"><strong>Histórico IA/RCA</strong><span class="text-muted small">Últimos 100 registros</span></div><div class="card-body">';
echo '<div class="d-flex flex-wrap gap-2 mb-3">';
$badges = [
    ['Sucessos', (int)$historySummary['success'], 'bg-success'],
    ['Já existentes', (int)$historySummary['existing'], 'bg-secondary'],
    ['Erros', (int)$historySummary['error'], 'bg-danger'],
    ['429 Rate Limit', (int)$historySummary['rate_limited'], 'bg-warning text-dark'],
    ['503 indisponível', (int)$historySummary['service_unavailable'], 'bg-warning text-dark'],
    ['Lotes', (int)$historySummary['batch'], 'bg-info text-dark'],
];
foreach ($badges as [$label, $count, $class]) {
    echo '<span class="badge ' . $class . '">' . htmlescape($label) . ': ' . $count . '</span>';
}
echo '</div>';

if ($history === []) {
    echo '<div class="alert alert-light border mb-0">Ainda não há registros persistentes. Eles serão criados nas próximas execuções; tickets com task [IA-RCA] já existente serão incorporados ao histórico conforme forem encontrados.</div>';
} else {
    echo '<div class="table-responsive"><table class="table table-sm table-hover align-middle"><thead><tr><th>Data</th><th>Ticket</th><th>Task</th><th>Resultado</th><th>Origem</th><th>Modelo</th><th>Duração</th><th>Estimativa</th><th>Confiança</th><th>Tokens</th><th>Mensagem</th></tr></thead><tbody>';
    $statusLabels = [
        'success' => ['Sucesso', 'bg-success'],
        'existing' => ['Já analisado', 'bg-secondary'],
        'error' => ['Erro', 'bg-danger'],
        'rate_limited' => ['429 Rate limit', 'bg-warning text-dark'],
        'service_unavailable' => ['503 indisponível', 'bg-warning text-dark'],
    ];
    foreach ($history as $row) {
        $type = (string)($row['type'] ?? 'ticket');
        $status = (string)($row['status'] ?? 'info');
        [$statusLabel, $statusClass] = $statusLabels[$status] ?? [$status, 'bg-light text-dark'];
        $ticketId = (int)($row['ticket_id'] ?? 0);
        $taskId = (int)($row['task_id'] ?? 0);
        $duration = (int)($row['duration_ms'] ?? 0);
        $tokens = $row['total_tokens'] ?? null;
        echo '<tr>';
        echo '<td class="text-nowrap">' . htmlescape((string)($row['timestamp'] ?? '—')) . '</td>';
        if ($type === 'batch') {
            echo '<td colspan="2"><span class="badge bg-info text-dark">LOTE</span></td>';
        } else {
            echo '<td>' . ($ticketId > 0 ? '<a href="' . htmlescape($CFG_GLPI['root_doc'] . '/front/ticket.form.php?id=' . $ticketId) . '">#' . $ticketId . '</a>' : '—') . '</td>';
            echo '<td>' . ($taskId > 0 ? '#' . $taskId : '—') . '</td>';
        }
        echo '<td><span class="badge ' . $statusClass . '">' . htmlescape($statusLabel) . '</span></td>';
        echo '<td>' . htmlescape((string)($row['source'] ?? '—')) . '</td>';
        echo '<td><code>' . htmlescape((string)($row['model'] ?? '—')) . '</code></td>';
        echo '<td>' . ($duration > 0 ? number_format($duration / 1000, 2, ',', '.') . 's' : '—') . '</td>';
        echo '<td>' . (isset($row['estimated_minutes']) && $row['estimated_minutes'] !== null ? (int)$row['estimated_minutes'] . ' min' : '—') . '</td>';
        echo '<td>' . (isset($row['confidence']) && $row['confidence'] !== null ? (int)$row['confidence'] . '%' : '—') . '</td>';
        echo '<td>' . ($tokens !== null ? number_format((int)$tokens, 0, ',', '.') : '—') . '</td>';
        echo '<td style="max-width:360px;white-space:normal">' . htmlescape((string)($row['message'] ?? '')) . '</td>';
        echo '</tr>';
    }
    echo '</tbody></table></div>';
}
echo '</div></div>';

// Mapas atuais.
echo '<div class="card mb-3"><div class="card-header"><strong>Mapa de equipamentos / NOC</strong></div><div class="card-body"><div class="table-responsive"><table class="table table-sm table-striped"><thead><tr><th>Código</th><th>Interpretação</th><th>Ativo GLPI</th><th>Assigned to</th></tr></thead><tbody>';
$equipmentMap = [
    'SRV' => ['Servidor', 'Computer', 'NOC > Servidores'],
    'DB'  => ['Banco de Dados', 'Computer', 'NOC > Banco de Dados'],
    'WEB' => ['Portal WEB', 'Computer', 'NOC > Portais WEB'],
    'SW'  => ['Switch', 'NetworkEquipment', 'NOC > Switches'],
    'FW'  => ['Firewall', 'NetworkEquipment', 'NOC > Firewalls'],
];
foreach ($equipmentMap as $code => $values) {
    $nocPath = preg_replace('/^NOC/u', (string)$config['classifier_noc_root_group'], $values[2]) ?? $values[2];
    echo '<tr><td><code>' . htmlescape($code) . '</code></td><td>' . htmlescape($values[0]) . '</td><td><code>' . htmlescape($values[1]) . '</code></td><td>' . htmlescape($nocPath) . '</td></tr>';
}
echo '</tbody></table></div></div></div>';

echo '<div class="card"><div class="card-header"><strong>Mapa inicial de categorias</strong></div><div class="card-body"><div class="table-responsive"><table class="table table-sm table-striped"><thead><tr><th>Evento</th><th>Categoria</th></tr></thead><tbody>';
$map = [
    'cpu_high' => 'Monitoramento > CPU > Utilização alta',
    'memory_high' => 'Monitoramento > Memória > Utilização alta',
    'disk_full' => 'Monitoramento > Armazenamento > Espaço insuficiente',
    'service_down' => 'Monitoramento > Disponibilidade > Serviço indisponível',
    'host_unavailable' => 'Monitoramento > Disponibilidade > Host indisponível',
    'packet_loss' => 'Monitoramento > Rede > Perda de pacotes',
    'latency_high' => 'Monitoramento > Rede > Latência elevada',
];
foreach ($map as $event => $category) {
    $category = preg_replace('/^Monitoramento/u', (string)$config['classifier_root_category'], $category) ?? $category;
    echo '<tr><td><code>' . htmlescape($event) . '</code></td><td>' . htmlescape($category) . '</td></tr>';
}
echo '</tbody></table></div></div></div>';

echo '</div>';
Html::footer();
