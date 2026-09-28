# IOS - Indicadores de Incidentes para GLPI 11

Plugin para consolidar indicadores operacionais do ciclo de vida de incidentes no GLPI 11, classificar tickets de monitoramento e preparar o histórico para RCA, Post-Mortem, recorrência e indicadores como MTTR/MTBF/MTBR.

## Escopo da versão 0.2.0

### Dashboard
- Dashboard próprio com filtros por período.
- Widget nativo para o Dashboard do GLPI.
- Cards de volume/status e indicadores operacionais existentes.
- Compatível com a restrição do GLPI 11 contra SQL direto: usa `$DB->request()`/DB iterator.

### Classificador automático
A versão 0.2.0 começa a estruturar os tickets que já chegam do Zabbix sem consultar a API do Zabbix.

Formato reconhecido atualmente:

```text
Problem: SIM | CLIENTE-19-SRV-003 | cpu_high | CPU utilization high
```

Dados adicionais lidos do conteúdo quando presentes:

```text
Problem started at 09:03:22 on 2026.09.28
Host: CLIENTE-19-SRV-003
Severity: Average
Original problem ID: 1686
```

O classificador pode:
- criar/associar categorias ITIL;
- criar hosts como ativos GLPI do tipo `Computer`;
- associar o ativo ao ticket com `Item_Ticket`;
- processar tickets novos imediatamente;
- processar o histórico em lotes, inclusive `Solved` e `Closed`;
- preservar categorias manuais quando a opção de sobrescrita estiver desligada.

## Mapa inicial de categorias

| Evento | Categoria GLPI |
|---|---|
| `cpu_high` | Monitoramento > CPU > Utilização alta |
| `memory_high` | Monitoramento > Memória > Utilização alta |
| `disk_full` | Monitoramento > Armazenamento > Espaço insuficiente |
| `service_down` | Monitoramento > Disponibilidade > Serviço indisponível |
| `host_unavailable` | Monitoramento > Disponibilidade > Host indisponível |
| `packet_loss` | Monitoramento > Rede > Perda de pacotes |
| `latency_high` | Monitoramento > Rede > Latência elevada |

Eventos desconhecidos são classificados em:

```text
Monitoramento > Outros > <evento>
```

## Ação Automática

Na instalação/atualização do plugin é registrada a ação:

```text
Classifier
```

Frequência padrão registrada: 300 segundos (5 minutos).

A ação lê tickets em lotes e mantém um cursor interno (`classifier_cursor_id`) para não reprocessar todo o histórico em cada execução.

Além do cron, existe um hook de criação de ticket para classificação imediata quando habilitado.

## Configuração

Em **Configuração > Plugins > IOS - Indicadores**, a versão 0.2.0 adiciona:

- habilitar/desabilitar classificador;
- classificação imediata de tickets novos;
- criação automática de categorias;
- criação automática de hosts;
- sobrescrita ou preservação de categoria existente;
- tamanho do lote;
- categoria raiz;
- botão para processar um lote manualmente;
- botão para reiniciar o cursor e reavaliar o histórico.

O classificador vem desabilitado por padrão após a atualização. Recomenda-se primeiro executar um lote manual pequeno, validar os objetos criados e depois habilitar a ação automática.

## Atualização pelo Git

No servidor de desenvolvimento:

```bash
cd /opt/glpi/repos/ios
git pull origin main

rsync -av --delete \
  iosindicators/ \
  /opt/glpi/glpi11/plugins/iosindicators/
```

Depois acesse **Configuração > Plugins** e execute a atualização do plugin para que a Ação Automática seja registrada.

## Fórmulas / interpretação atuais

| Indicador | Fonte inicial |
|---|---|
| TTO | `takeintoaccount_delay_stat` |
| TTS | `solve_delay_stat` |
| MTTR | média do `solve_delay_stat` dos tickets resolvidos na versão atual do dashboard |
| TMA | `actiontime` |
| RCA completa | tarefa `[IA-RCA]` contendo Diagnóstico + Causa + Solução |
| Ações remotas | tarefas contendo `[REMOTO]` |

## Próxima etapa de indicadores

Com host e categoria estruturados, a próxima versão deve calcular:

- MTTR por host/categoria/evento;
- MTBF por host;
- MTBR por host;
- disponibilidade estimada;
- número de falhas por host;
- recorrência por host + evento;
- ranking de ativos com menor MTBF e maior MTTR.

O horário `Problem started at ...` já é extraído pelo parser e será usado na próxima etapa após validarmos o tratamento de timezone entre a origem do evento e o GLPI.

## Compatibilidade

- GLPI >= 11.0.0 e < 11.0.99
- PHP >= 8.2

## Diagnóstico

Se o painel apresentar erro, acesse:

```text
plugins/iosindicators/front/diagnostics.php
```

como Super-Admin.

## Licença

GPLv3+
