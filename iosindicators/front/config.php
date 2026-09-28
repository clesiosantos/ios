<?php

use GlpiPlugin\Iosindicators\Classifier;
use GlpiPlugin\Iosindicators\Settings;

include(__DIR__ . '/../../../inc/includes.php');

Session::checkRight('config', UPDATE);
Plugin::load('iosindicators');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Session::checkCSRF($_POST);
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
    } elseif (isset($_POST['reset_classifier_cursor'])) {
        Session::addMessageAfterRedirect(__('Cursor do classificador reiniciado. Na próxima execução o histórico será reavaliado.', 'iosindicators'), true, INFO);
    } else {
        Session::addMessageAfterRedirect(__('Configurações salvas.', 'iosindicators'), true, INFO);
    }

    Html::redirect($_SERVER['PHP_SELF']);
}

$config = Settings::all();

Html::header(
    __('IOS - Indicadores de Incidentes', 'iosindicators'),
    $_SERVER['PHP_SELF'],
    'config',
    'plugins'
);

echo '<div class="container py-3" style="max-width: 1100px">';

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
echo '<div class="col-md-4"><label class="form-label">Categoria raiz</label><input class="form-control" type="text" name="classifier_root_category" value="' . htmlescape((string)$config['classifier_root_category']) . '"></div>';
echo '<div class="col-md-4"><label class="form-label">Tickets por lote</label><input class="form-control" type="number" min="1" max="1000" name="classifier_batch_size" value="' . (int)$config['classifier_batch_size'] . '"></div>';
echo '<div class="col-md-4"><label class="form-label">Cursor atual</label><input class="form-control" type="text" readonly value="#' . (int)$config['classifier_cursor_id'] . '"><div class="form-text">Último ticket avaliado pelo processamento retroativo.</div></div>';

echo '<div class="col-md-6"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="classifier_enabled" id="classifier_enabled"' . ((int)$config['classifier_enabled'] === 1 ? ' checked' : '') . '><label class="form-check-label" for="classifier_enabled">Habilitar ação automática do classificador</label></div></div>';
echo '<div class="col-md-6"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="classifier_immediate" id="classifier_immediate"' . ((int)$config['classifier_immediate'] === 1 ? ' checked' : '') . '><label class="form-check-label" for="classifier_immediate">Classificar tickets novos imediatamente</label></div></div>';
echo '<div class="col-md-6"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="classifier_create_categories" id="classifier_create_categories"' . ((int)$config['classifier_create_categories'] === 1 ? ' checked' : '') . '><label class="form-check-label" for="classifier_create_categories">Criar/associar categorias automaticamente</label></div></div>';
echo '<div class="col-md-6"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="classifier_create_hosts" id="classifier_create_hosts"' . ((int)$config['classifier_create_hosts'] === 1 ? ' checked' : '') . '><label class="form-check-label" for="classifier_create_hosts">Criar host como Computador e associar ao ticket</label></div></div>';
echo '<div class="col-12"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="classifier_overwrite_category" id="classifier_overwrite_category"' . ((int)$config['classifier_overwrite_category'] === 1 ? ' checked' : '') . '><label class="form-check-label" for="classifier_overwrite_category">Sobrescrever categoria existente</label><div class="form-text">Deixe desligado para preservar classificações manuais.</div></div></div>';
echo '</div>';

echo '<div class="alert alert-info mt-3">O classificador reconhece o formato atual <code>Problem: SIM | HOST | evento | descrição</code>. O histórico inclui tickets New, Assigned, Planned, Pending, Solved e Closed; o status não é alterado.</div>';

echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);
echo '<div class="d-flex flex-wrap gap-2">';
echo '<button type="submit" class="btn btn-primary"><i class="ti ti-device-floppy"></i> Salvar</button>';
echo '<button type="submit" name="run_classifier_now" value="1" class="btn btn-success"><i class="ti ti-player-play"></i> Processar um lote agora</button>';
echo '<button type="submit" name="reset_classifier_cursor" value="1" class="btn btn-outline-danger" onclick="return confirm(\'Reiniciar o cursor fará o histórico ser reavaliado. Continuar?\')"><i class="ti ti-refresh"></i> Reiniciar processamento histórico</button>';
echo '</div>';
echo '</form></div></div>';

echo '<div class="card"><div class="card-header"><strong>Mapa inicial de classificação</strong></div><div class="card-body">';
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
