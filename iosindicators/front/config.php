<?php

use GlpiPlugin\Iosindicators\Settings;

include(__DIR__ . '/../../../inc/includes.php');

Session::checkRight('config', UPDATE);
Plugin::load('iosindicators');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Session::checkCSRF($_POST);
    Settings::save($_POST);
    Session::addMessageAfterRedirect(__('Configurações salvas.', 'iosindicators'), true, INFO);
    Html::redirect($_SERVER['PHP_SELF']);
}

$config = Settings::all();

Html::header(
    __('IOS - Indicadores de Incidentes', 'iosindicators'),
    $_SERVER['PHP_SELF'],
    'config',
    'plugins'
);

echo '<div class="container py-3" style="max-width: 980px">';
echo '<div class="card"><div class="card-header"><strong>Configuração dos Indicadores</strong></div><div class="card-body">';
echo '<form method="post">';

echo '<div class="row g-3">';
echo '<div class="col-md-4"><label class="form-label">Período padrão (dias)</label><input class="form-control" type="number" min="1" max="3650" name="default_period_days" value="' . (int)$config['default_period_days'] . '"></div>';
echo '<div class="col-md-4"><label class="form-label">Etiqueta da tarefa IA / RCA</label><input class="form-control" type="text" name="ai_task_tag" value="' . htmlescape((string)$config['ai_task_tag']) . '"><div class="form-text">Ex.: [IA-RCA]</div></div>';
echo '<div class="col-md-4"><label class="form-label">Etiqueta de atividade remota</label><input class="form-control" type="text" name="remote_task_tag" value="' . htmlescape((string)$config['remote_task_tag']) . '"><div class="form-text">Ex.: [REMOTO]</div></div>';
echo '<div class="col-md-6"><label class="form-label">Marcador/origem Zabbix</label><input class="form-control" type="text" name="zabbix_marker" value="' . htmlescape((string)$config['zabbix_marker']) . '"><div class="form-text">Reservado para o filtro de origem e evolução da integração.</div></div>';

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
echo '</select><div class="form-text">A reunião manteve a nomenclatura/fórmula do MBTR em aberto; por isso o plugin não inventa uma definição.</div></div>';

echo '<div class="col-12"><div class="form-check form-switch">';
echo '<input class="form-check-input" type="checkbox" name="show_only_incidents" id="show_only_incidents"' . ((int)$config['show_only_incidents'] === 1 ? ' checked' : '') . '>';
echo '<label class="form-check-label" for="show_only_incidents">Considerar somente tickets do tipo Incidente</label>';
echo '</div></div>';
echo '</div>';

echo '<div class="alert alert-secondary mt-3 mb-3">Para a RCA ser considerada completa, a tarefa marcada deve conter os blocos <strong>Diagnóstico</strong>, <strong>Causa</strong> e <strong>Solução</strong>. Isso prepara o dado operacional para o Post-Mortem e para a futura Base de Conhecimento inteligente.</div>';

echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);
echo '<button type="submit" class="btn btn-primary"><i class="ti ti-device-floppy"></i> Salvar</button>';
echo '</form></div></div></div>';

Html::footer();
