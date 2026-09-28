<?php

use GlpiPlugin\Iosindicators\AiRca;
use GlpiPlugin\Iosindicators\Classifier;
use GlpiPlugin\Iosindicators\Settings;

include(__DIR__ . '/../../../inc/includes.php');

Session::checkRight('config', UPDATE);
Plugin::load('iosindicators');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // GLPI 11 valida o token CSRF no CheckCsrfListener antes de carregar
    // este arquivo legado. Revalidar aqui consumiria o mesmo token duas vezes.
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
        $result = AiRca::runBatch();
        Session::addMessageAfterRedirect(sprintf(
            'IA/RCA executada: lidos=%d, elegíveis=%d, tasks criadas=%d, já analisados=%d, ignorados=%d, erros=%d.',
            $result['read'],
            $result['eligible'],
            $result['created'],
            $result['already_analyzed'],
            $result['ignored'],
            $result['errors']
        ), true, $result['errors'] > 0 ? WARNING : INFO);
    } elseif (isset($_POST['reset_classifier_cursor'])) {
        Session::addMessageAfterRedirect(__('Cursor do classificador reiniciado. Na próxima execução o histórico será reavaliado.', 'iosindicators'), true, INFO);
    } else {
        Session::addMessageAfterRedirect(__('Configurações salvas.', 'iosindicators'), true, INFO);
    }

    Html::redirect($_SERVER['PHP_SELF']);
}

$config = Settings::all();
$hasGeminiKey = AiRca::hasApiKey();

Html::header(
    __('IOS - Indicadores de Incidentes', 'iosindicators'),
    $_SERVER['PHP_SELF'],
    'config',
    'plugins'
);

echo '<div class="container py-3" style="max-width: 1150px">';

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
echo '<div class="col-12"><div class="form-check form-switch">';
echo '<input class="form-check-input" type="checkbox" name="show_only_incidents" id="show_only_incidents"' . ((int)$config['show_only_incidents'] === 1 ? ' checked' : '') . '>';
echo '<label class="form-check-label" for="show_only_incidents">Considerar somente tickets do tipo Incidente</label>';
echo '</div></div>';
echo '</div>';

echo '<hr class="my-4">';
echo '<h3 class="h4">Classificador automático GLPI</h3>';
echo '<p class="text-muted">Usa somente os dados já recebidos no ticket. Não consulta nem altera a API do Zabbix.</p>';

echo '<div class="row g-3">';
echo '<div class="col-md-3"><label class="form-label">Categoria raiz</label><input class="form-control" type="text" name="classifier_root_category" value="' . htmlescape((string)$config['classifier_root_category']) . '"></div>';
echo '<div class="col-md-3"><label class="form-label">Grupo NOC raiz</label><input class="form-control" type="text" name="classifier_noc_root_group" value="' . htmlescape((string)$config['classifier_noc_root_group']) . '"><div class="form-text">Ex.: NOC</div></div>';
echo '<div class="col-md-3"><label class="form-label">Tickets por lote</label><input class="form-control" type="number" min="1" max="1000" name="classifier_batch_size" value="' . (int)$config['classifier_batch_size'] . '"></div>';
echo '<div class="col-md-3"><label class="form-label">Cursor atual</label><input class="form-control" type="text" readonly value="#' . (int)$config['classifier_cursor_id'] . '"><div class="form-text">Último ticket avaliado.</div></div>';

echo '<div class="col-md-6"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="classifier_enabled" id="classifier_enabled"' . ((int)$config['classifier_enabled'] === 1 ? ' checked' : '') . '><label class="form-check-label" for="classifier_enabled">Habilitar ação automática do classificador</label></div></div>';
echo '<div class="col-md-6"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="classifier_immediate" id="classifier_immediate"' . ((int)$config['classifier_immediate'] === 1 ? ' checked' : '') . '><label class="form-check-label" for="classifier_immediate">Classificar tickets novos imediatamente</label></div></div>';
echo '<div class="col-md-6"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="classifier_create_categories" id="classifier_create_categories"' . ((int)$config['classifier_create_categories'] === 1 ? ' checked' : '') . '><label class="form-check-label" for="classifier_create_categories">Criar/associar categorias automaticamente</label></div></div>';
echo '<div class="col-md-6"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="classifier_create_hosts" id="classifier_create_hosts"' . ((int)$config['classifier_create_hosts'] === 1 ? ' checked' : '') . '><label class="form-check-label" for="classifier_create_hosts">Criar/associar ativo conforme o tipo do host</label></div></div>';
echo '<div class="col-md-6"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="classifier_assign_requester" id="classifier_assign_requester"' . ((int)$config['classifier_assign_requester'] === 1 ? ' checked' : '') . '><label class="form-check-label" for="classifier_assign_requester">Adicionar CLIENTE-XX como grupo Requester</label><div class="form-text">Evita criar usuários fictícios; o cliente é representado como grupo solicitante.</div></div></div>';
echo '<div class="col-md-6"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="classifier_assign_noc" id="classifier_assign_noc"' . ((int)$config['classifier_assign_noc'] === 1 ? ' checked' : '') . '><label class="form-check-label" for="classifier_assign_noc">Atribuir ao subgrupo NOC pelo tipo de equipamento</label></div></div>';
echo '<div class="col-12"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="classifier_overwrite_category" id="classifier_overwrite_category"' . ((int)$config['classifier_overwrite_category'] === 1 ? ' checked' : '') . '><label class="form-check-label" for="classifier_overwrite_category">Sobrescrever categoria existente</label><div class="form-text">Deixe desligado para preservar classificações manuais.</div></div></div>';
echo '</div>';

echo '<div class="alert alert-info mt-3">O classificador reconhece tickets abertos e solucionados, extrai <strong>CLIENTE</strong>, <strong>host</strong>, <strong>tipo de equipamento</strong> e <strong>evento</strong>, cria os atores e associa o ativo. Tickets Solved/Closed também podem ser enriquecidos; o status não é alterado.</div>';

echo '<hr class="my-4">';
echo '<div class="d-flex align-items-center justify-content-between flex-wrap gap-2">';
echo '<div><h3 class="h4 mb-1">IA / RCA com Gemini</h3><p class="text-muted mb-0">Analisa tickets solucionados/fechados, gera RCA estruturada e estima esforço técnico sem alterar o actiontime real.</p></div>';
echo '<span class="badge ' . ($hasGeminiKey ? 'bg-success' : 'bg-warning text-dark') . '">' . ($hasGeminiKey ? 'GEMINI_API_KEY disponível' : 'GEMINI_API_KEY não configurada') . '</span>';
echo '</div>';

echo '<div class="row g-3 mt-1">';
echo '<div class="col-md-6"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="ai_rca_enabled" id="ai_rca_enabled"' . ((int)$config['ai_rca_enabled'] === 1 ? ' checked' : '') . '><label class="form-check-label" for="ai_rca_enabled">Habilitar ação automática IOS - AI RCA</label><div class="form-text">A ação procura tickets Solved/Closed sem task [IA-RCA].</div></div></div>';
echo '<div class="col-md-6"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="ai_rca_redact_sensitive" id="ai_rca_redact_sensitive"' . ((int)$config['ai_rca_redact_sensitive'] === 1 ? ' checked' : '') . '><label class="form-check-label" for="ai_rca_redact_sensitive">Redigir e-mails, telefones e CPF antes do envio</label></div></div>';
echo '<div class="col-md-4"><label class="form-label">Modelo Gemini</label><input class="form-control" type="text" name="ai_rca_model" value="' . htmlescape((string)$config['ai_rca_model']) . '"><div class="form-text">Padrão atual: gemini-3.8-flash</div></div>';
echo '<div class="col-md-2"><label class="form-label">Tasks por lote</label><input class="form-control" type="number" min="1" max="50" name="ai_rca_batch_size" value="' . (int)$config['ai_rca_batch_size'] . '"></div>';
echo '<div class="col-md-2"><label class="form-label">Janela de busca</label><input class="form-control" type="number" min="1" max="5000" name="ai_rca_scan_limit" value="' . (int)$config['ai_rca_scan_limit'] . '"><div class="form-text">Últimos resolvidos.</div></div>';
echo '<div class="col-md-2"><label class="form-label">Contexto máx.</label><input class="form-control" type="number" min="4000" max="50000" name="ai_rca_max_context_chars" value="' . (int)$config['ai_rca_max_context_chars'] . '"><div class="form-text">Caracteres.</div></div>';
echo '<div class="col-md-2"><label class="form-label">Timeout</label><input class="form-control" type="number" min="10" max="120" name="ai_rca_timeout_seconds" value="' . (int)$config['ai_rca_timeout_seconds'] . '"><div class="form-text">Segundos.</div></div>';
echo '</div>';

echo '<div class="alert alert-warning mt-3 mb-3"><strong>Segredo da API:</strong> não é salvo no banco nem no GitHub. No Docker atual o host <code>/opt/glpi/glpi11/config</code> está montado em <code>/var/glpi/config</code>. O plugin procura primeiro <code>GEMINI_API_KEY</code> no ambiente e depois em <code>/var/glpi/config/iosindicators.env</code> ou <code>/var/glpi/config/.env</code>.</div>';
echo '<div class="alert alert-secondary"><strong>Tempo IA:</strong> a task criada contém <code>Tempo estimado IA (segundos)</code>, porém o campo <code>actiontime</code> da task fica em zero. O dashboard usa a estimativa separadamente para não contaminar o tempo real de trabalho do GLPI.</div>';

echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);
echo '<div class="d-flex flex-wrap gap-2">';
echo '<button type="submit" class="btn btn-primary"><i class="ti ti-device-floppy"></i> Salvar</button>';
echo '<button type="submit" name="run_classifier_now" value="1" class="btn btn-success"><i class="ti ti-player-play"></i> Processar classificador</button>';
echo '<button type="submit" name="run_ai_rca_now" value="1" class="btn btn-info"' . (!$hasGeminiKey ? ' disabled' : '') . '><i class="ti ti-brain"></i> Processar IA/RCA agora</button>';
echo '<button type="submit" name="reset_classifier_cursor" value="1" class="btn btn-outline-danger" onclick="return confirm(\'Reiniciar o cursor fará o histórico ser reavaliado. Continuar?\')"><i class="ti ti-refresh"></i> Reiniciar processamento histórico</button>';
echo '</div>';
echo '</form></div></div>';

echo '<div class="card mb-3"><div class="card-header"><strong>Mapa de equipamentos / NOC</strong></div><div class="card-body">';
echo '<p class="text-muted">Mapeamento baseado nos códigos encontrados na amostra atual de tickets. Códigos desconhecidos vão para <code>NOC &gt; Outros</code> e são criados como Computer até revisão.</p>';
echo '<div class="table-responsive"><table class="table table-sm table-striped"><thead><tr><th>Código</th><th>Interpretação</th><th>Ativo GLPI</th><th>Assigned to</th></tr></thead><tbody>';
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

echo '<div class="card"><div class="card-header"><strong>Mapa inicial de categorias</strong></div><div class="card-body">';
echo '<div class="table-responsive"><table class="table table-sm table-striped"><thead><tr><th>Evento</th><th>Categoria</th></tr></thead><tbody>';
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