# IOS - Indicadores de Incidentes para GLPI 11

**Versão 1.0.0** — plugin de governança operacional para GLPI 11, criado para transformar tickets de monitoramento em uma base estruturada de indicadores, RCA, eficiência e automação assistida por IA.

O objetivo do plugin é apoiar a gestão executiva e operacional de incidentes, mantendo os dados dentro do GLPI como fonte principal e usando IA de forma controlada, auditável e separada dos indicadores reais.

## Conceito

O IOS Indicators organiza o ciclo:

```text
Monitoramento → GLPI → Classificação → Tratativa → Normalização → RCA → Indicadores → Governança
```

A proposta da versão 1.0 é demonstrar como um ambiente GLPI pode evoluir de um repositório de tickets para uma camada de governança com:

- classificação automática dos incidentes;
- estruturação de cliente, ativo, grupo solicitante e grupo NOC;
- métricas executivas e operacionais;
- análise de RCA com IA;
- histórico de actiontime baseado em abertura → solução;
- comparação entre tempo histórico e tempo estimado pela IA;
- rastreabilidade de sucesso, falha, consumo e execução das rotinas.

## Funcionalidades principais

### Dashboard moderno

O dashboard principal fica em:

```text
/plugins/iosindicators/front/dashboard.php
```

Ele possui abas:

- **Visão executiva**: volumes, normalização, cobertura estruturada, disponibilidade e reincidência;
- **Velocidade operacional**: TTO, MTTR, percentis, MTBF, MTBR, espera média e TMA;
- **Qualidade do dado**: categoria, ativo, requester, assigned group, IA/RCA e completude;
- **Eficiência IA**: comparação entre ActionTime histórico e estimativa da IOS NORA;
- **Tempo Real**: tickets ainda em tratamento, clientes/hosts impactados e últimos tickets classificados.

Os estilos são carregados inline pelo PHP para evitar erro 404 de CSS em ambientes GLPI 11 cujo webroot não publica diretamente `/plugins/<plugin>/css`.

### Classificador automático

A rotina lê tickets de monitoramento já existentes no GLPI, sem consultar a API do Zabbix.

Formato típico reconhecido:

```text
Problem: SIM | CLIENTE-19-SRV-003 | cpu_high | CPU utilization high
Resolved in 1h 4m 0s: SIM | CLIENTE-01-SRV-003 | service_down | Critical service stopped
```

O classificador pode:

- criar e associar categorias ITIL;
- criar ativos fictícios ou reaproveitar ativos existentes;
- associar o item ao ticket;
- criar grupo cliente como requester;
- criar ou reaproveitar grupos NOC por tipo de equipamento;
- atribuir o ticket ao grupo NOC correto;
- classificar histórico e novos tickets;
- preservar classificações manuais quando configurado.

### Mapa inicial de eventos

| Evento | Categoria GLPI |
|---|---|
| `cpu_high` | Monitoramento > CPU > Utilização alta |
| `memory_high` | Monitoramento > Memória > Utilização alta |
| `disk_full` | Monitoramento > Armazenamento > Espaço insuficiente |
| `service_down` | Monitoramento > Disponibilidade > Serviço indisponível |
| `host_unavailable` | Monitoramento > Disponibilidade > Host indisponível |
| `packet_loss` | Monitoramento > Rede > Perda de pacotes |
| `latency_high` | Monitoramento > Rede > Latência elevada |

Eventos desconhecidos são enviados para:

```text
Monitoramento > Outros > <evento>
```

### Mapa inicial de equipamentos

| Código | Interpretação | Tipo GLPI | Grupo de atendimento |
|---|---|---|---|
| `SRV` | Servidor | `Computer` | NOC > Servidores |
| `DB` | Banco de Dados | `Computer` | NOC > Banco de Dados |
| `WEB` | Portal / serviço web | `Computer` | NOC > Portais WEB |
| `SW` | Switch | `NetworkEquipment` | NOC > Switches |
| `FW` | Firewall | `NetworkEquipment` | NOC > Firewalls |

Códigos desconhecidos são tratados de forma conservadora como `Computer` e encaminhados para `NOC > Outros`.

### IA/RCA com Gemini

A ação `AiRca` processa tickets solucionados ou fechados que ainda não possuem task `[IA-RCA]`.

A integração:

- usa a chave `GEMINI_API_KEY` fora do banco e fora do GitHub;
- lê o segredo no volume `/var/glpi/config`, montado a partir de `/opt/glpi/glpi11/config`;
- utiliza por padrão o modelo econômico `gemini-3.5-flash-lite`;
- redige dados sensíveis antes do envio quando configurado;
- registra diagnóstico, causa provável, ação recomendada, classificação, complexidade, estimativa e confiança;
- cria uma task `[IA-RCA] [IOS-AI-RCA-V1]` no ticket;
- mantém o `actiontime` da task IA como zero para não contaminar indicadores reais;
- grava histórico persistente em JSONL.

### IOS NORA

O agente de IA da solução é apresentado como:

```text
IOS NORA — Núcleo Operacional de Resposta Assistida
```

A NORA é usada para análise estruturada e estimativa de esforço, não para substituir o registro real de trabalho técnico.

### ActionTime histórico

A ação `ActionTimeBackfill` cria ou corrige tasks históricas com a tag:

```text
[IOS-ACTIONTIME-SOLUTION-V2]
```

Regra:

```text
ActionTime histórico = data/hora da solução − data/hora da abertura
```

Esse valor é registrado na task e sincronizado no actiontime agregado do ticket, permitindo comparar o histórico real do GLPI com a estimativa de IA.

Tasks legadas `[IOS-ACTIONTIME-CYCLE-V1]`, que usavam abertura → fechamento, são migradas para abertura → solução sem duplicar registros.

### Eficiência potencial com IA

A aba **Eficiência IA** compara somente tickets que possuem os dois lados:

```text
[IOS-ACTIONTIME-SOLUTION-V2] + [IA-RCA]
```

Fórmula:

```text
Eficiência potencial IA =
(ActionTime histórico - Tempo estimado IOS NORA) / ActionTime histórico × 100
```

Exemplo:

```text
Histórico: 120 min
IOS NORA:  30 min
Ganho:     90 min
Eficiência potencial: 75%
```

Esse indicador deve ser apresentado como **eficiência potencial**, pois compara um histórico real com uma estimativa de IA.

## Ações automáticas

| Ação | Finalidade | Observação |
|---|---|---|
| `Classifier` | Classifica tickets de monitoramento | Mantém cursor próprio |
| `AiRca` | Gera análise IA/RCA com Gemini | Idempotente; usa cooldown em 429/503 |
| `ActionTimeBackfill` | Cria/corrige ActionTime histórico | Usa abertura → solução |

## Logs e histórico

Histórico persistente da IA/RCA:

```text
/var/glpi/config/iosindicators-logs/ai-rca.jsonl
```

Como `/var/glpi/config` está montado a partir de `/opt/glpi/glpi11/config`, os logs sobrevivem à recriação do container.

## Fórmulas principais

| Indicador | Interpretação |
|---|---|
| TTO | Tempo até o ticket ser assumido |
| MTTR | Tempo médio para reparar/restabelecer serviço |
| MTBF | Tempo médio entre o fim de uma falha e a próxima falha do mesmo host |
| MTBR | Tempo médio entre reparos concluídos do mesmo host |
| Disponibilidade estimada | `MTBF / (MTBF + MTTR)` |
| TMA | Tempo médio de atuação registrada |
| Cobertura estruturada | Categoria + ativo + requester + assigned group |
| RCA completa | Task IA/RCA com diagnóstico + causa + solução |
| Eficiência IA | Redução potencial entre histórico e IOS NORA |

Quando não há dados suficientes para MTBF ou MTTR, a disponibilidade é exibida como `—`, e não como `0,00%`.

## Documentação

A documentação completa da versão 1.0 está no diretório:

```text
iosindicators/docs/
```

Arquivos principais:

- [`docs/VISAO_GERAL.md`](docs/VISAO_GERAL.md)
- [`docs/ARQUITETURA.md`](docs/ARQUITETURA.md)
- [`docs/OPERACAO.md`](docs/OPERACAO.md)
- [`docs/INDICADORES.md`](docs/INDICADORES.md)
- [`docs/GOVERNANCA_IA.md`](docs/GOVERNANCA_IA.md)
- [`docs/EVOLUCAO.md`](docs/EVOLUCAO.md)

## Atualização pelo Git

No servidor:

```bash
cd /opt/glpi/repos/ios
git pull origin main

rsync -av --delete \
  iosindicators/ \
  /opt/glpi/glpi11/plugins/iosindicators/
```

Depois valide a versão:

```bash
grep PLUGIN_IOSINDICATORS_VERSION \
  /opt/glpi/glpi11/plugins/iosindicators/setup.php
```

Esperado:

```text
define('PLUGIN_IOSINDICATORS_VERSION', '1.0.0');
```

Em seguida, acesse **Configuração > Plugins** e execute a atualização do plugin quando necessário, para registrar ou atualizar ações automáticas.

## Compatibilidade

- GLPI >= 11.0.0 e < 11.0.99
- PHP >= 8.2

## Diagnóstico

Se o painel apresentar erro, acesse como Super-Admin:

```text
/plugins/iosindicators/front/diagnostics.php
```

## Licença

GPLv3+
