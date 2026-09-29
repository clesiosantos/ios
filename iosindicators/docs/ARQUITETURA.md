# Arquitetura — IOS Indicators 1.0

## Visão lógica

```text
GLPI Tickets
   ↓
Classifier
   ↓
Categorias + Ativos + Requester + NOC
   ↓
Dashboard operacional
   ↓
AiRca / IOS NORA
   ↓
Task IA/RCA + Histórico JSONL
   ↓
ActionTimeBackfill
   ↓
Task ActionTime histórico
   ↓
Eficiência IA
```

## Princípios técnicos

- O GLPI é a fonte de verdade dos tickets.
- O plugin não consulta nem altera a API do Zabbix.
- As rotinas são idempotentes: se a task ou vínculo já existe, não duplica.
- Dados de IA ficam claramente identificados como estimativa.
- A task IA/RCA não soma actiontime fictício no GLPI.
- O actiontime histórico é registrado em task própria e auditável.
- Logs e segredos ficam fora do GitHub.

## Estrutura de diretórios

```text
iosindicators/
├── css/
│   ├── iosindicators.css
│   └── iosindicators-modern.css
├── docs/
│   ├── VISAO_GERAL.md
│   ├── ARQUITETURA.md
│   ├── OPERACAO.md
│   ├── INDICADORES.md
│   ├── GOVERNANCA_IA.md
│   └── EVOLUCAO.md
├── front/
│   ├── dashboard.php
│   ├── config.php
│   ├── diagnostics.php
│   └── efficiency.php
├── src/
│   ├── ActionTimeBackfill.php
│   ├── AiRca.php
│   ├── AiRcaHistory.php
│   ├── Classifier.php
│   ├── Dashboard.php
│   ├── EfficiencyMetrics.php
│   ├── Metrics.php
│   └── Settings.php
├── hook.php
├── setup.php
└── iosindicators.xml
```

## Fluxos principais

### 1. Classificação de tickets

Entrada típica:

```text
Problem: SIM | CLIENTE-19-SRV-003 | cpu_high | CPU utilization high
```

O plugin extrai:

- cliente: `CLIENTE-19`;
- tipo de ativo: `SRV`;
- host: `CLIENTE-19-SRV-003`;
- evento: `cpu_high`;
- severidade, quando disponível no corpo do ticket.

Depois associa:

- categoria ITIL;
- ativo GLPI;
- grupo requester do cliente;
- grupo NOC responsável.

### 2. IA/RCA

A ação `AiRca` seleciona tickets `Solved` ou `Closed` ainda sem `[IA-RCA]`, monta um contexto e envia para Gemini.

A resposta esperada é estruturada em:

- diagnóstico;
- causa provável;
- ação recomendada;
- classificação;
- complexidade;
- esforço estimado;
- confiança;
- evidências consideradas.

A task criada recebe:

```text
[IA-RCA] [IOS-AI-RCA-V1]
```

### 3. ActionTime histórico

A ação `ActionTimeBackfill` seleciona tickets solucionados ou fechados e cria/corrige a task:

```text
[IOS-ACTIONTIME-SOLUTION-V2]
```

Regra:

```text
ActionTime histórico = solvedate - date
```

### 4. Eficiência IA

A eficiência só compara tickets que possuem:

```text
[IOS-ACTIONTIME-SOLUTION-V2]
+
[IA-RCA]
```

Assim, a análise não mistura bases incompletas.

## Segredos e logs

A chave Gemini deve existir no volume de configuração:

```text
/var/glpi/config/iosindicators.env
/var/glpi/config/.env
/var/glpi/config/*.env
```

No ambiente Docker atual:

```text
/opt/glpi/glpi11/config:/var/glpi/config:rw
```

Histórico IA/RCA:

```text
/var/glpi/config/iosindicators-logs/ai-rca.jsonl
```

## Segurança operacional

- A chave de API não é gravada no banco.
- A chave de API não é versionada no GitHub.
- O plugin pode redigir e-mail, telefone e CPF antes do envio à IA.
- Erros `429` e `503` geram cooldown para evitar tempestade de requisições.
- O histórico IA/RCA registra sucesso e falha para auditoria.
