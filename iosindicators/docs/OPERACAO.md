# Operação — IOS Indicators 1.0

## Atualização do plugin no servidor

```bash
cd /opt/glpi/repos/ios
git pull origin main

rsync -av --delete \
  iosindicators/ \
  /opt/glpi/glpi11/plugins/iosindicators/
```

Validar versão:

```bash
grep PLUGIN_IOSINDICATORS_VERSION \
  /opt/glpi/glpi11/plugins/iosindicators/setup.php
```

Esperado na versão 1.0:

```text
define('PLUGIN_IOSINDICATORS_VERSION', '1.0.0');
```

Depois, acesse **Configuração > Plugins** e execute a atualização do plugin quando solicitado.

## Ordem recomendada de implantação

1. Atualizar arquivos do plugin.
2. Atualizar o plugin no GLPI.
3. Validar dashboard.
4. Executar classificador manualmente em lote pequeno.
5. Validar categorias, ativos, requester e assigned group.
6. Executar IA/RCA manualmente em lote pequeno.
7. Validar task `[IA-RCA]` e histórico IA/RCA.
8. Executar ActionTime manualmente em lote pequeno.
9. Validar task `[IOS-ACTIONTIME-SOLUTION-V2]`.
10. Habilitar ações automáticas.

## Configuração recomendada

### Classificador

| Campo | Valor inicial sugerido |
|---|---|
| Habilitar ação automática | Sim, após validação manual |
| Classificar tickets novos imediatamente | Sim |
| Criar categorias | Sim |
| Criar hosts | Sim |
| Adicionar requester | Sim |
| Atribuir NOC | Sim |
| Sobrescrever categoria existente | Não, salvo necessidade |
| Tickets por lote | 100 a 500 após validação |

### IA/RCA

| Campo | Valor inicial sugerido |
|---|---|
| Modelo Gemini | `gemini-3.5-flash-lite` |
| Tasks por lote | 15 |
| Janela de busca | 5000 |
| Contexto máximo | 12000 a 18000 caracteres |
| Timeout | 60 segundos |
| Intervalo entre chamadas | 1500 ms |
| Cooldown após falha | 60 min |
| Redigir dados sensíveis | Sim |

### ActionTime histórico

| Campo | Valor inicial sugerido |
|---|---|
| Habilitar automático | Sim, após validação manual |
| Tickets por lote | 100 a 500 |
| Janela de busca | 5000 a 10000 |
| Criar somente quando actiontime atual for zero | Desmarcar quando quiser cobrir todo histórico |

## Ações automáticas

Acesse **Configuração > Ações automáticas** e valide:

- `Classifier`;
- `AiRca`;
- `ActionTimeBackfill`.

Recomendação:

- modo CLI quando possível;
- frequência de 5 minutos para `Classifier` e `AiRca`;
- frequência de 5 minutos ou superior para `ActionTimeBackfill`, dependendo do volume.

## Validações pós-processamento

### Classificador

Abrir alguns tickets e verificar:

- categoria preenchida;
- item/ativo vinculado;
- grupo requester do cliente;
- grupo NOC atribuído;
- status preservado.

### IA/RCA

Abrir tickets processados e verificar task contendo:

```text
[IA-RCA] [IOS-AI-RCA-V1]
```

A task deve conter diagnóstico, causa provável, ação recomendada, complexidade, estimativa e confiança.

### ActionTime

Abrir tickets processados e verificar task contendo:

```text
[IOS-ACTIONTIME-SOLUTION-V2]
```

A task deve conter abertura, solução e actiontime histórico calculado.

## Histórico IA/RCA

Arquivo persistente:

```text
/var/glpi/config/iosindicators-logs/ai-rca.jsonl
```

Ele registra:

- data/hora;
- ticket;
- task;
- origem manual ou cron;
- modelo Gemini;
- status;
- duração;
- estimativa;
- confiança;
- tokens, quando disponíveis;
- mensagem de erro, quando houver.

## Diagnóstico técnico

Página:

```text
/plugins/iosindicators/front/diagnostics.php
```

Use como Super-Admin para validar tabelas, campos, permissões e registros do plugin.

## Problemas comuns

### CSS 404

A partir da versão 0.7.1, o CSS é carregado inline pelo filesystem. Se o visual aparecer cru, valide se os arquivos existem:

```bash
ls -lah /opt/glpi/glpi11/plugins/iosindicators/css
```

### Gemini indisponível ou 429

Reduza:

- tasks por lote;
- contexto máximo;
- frequência da ação automática.

Aumente:

- intervalo entre chamadas;
- cooldown após falha.

### Eficiência IA zerada

A aba só calcula tickets que tenham os dois lados:

```text
[IOS-ACTIONTIME-SOLUTION-V2]
+
[IA-RCA]
```

Rode ActionTime e IA/RCA sobre o mesmo universo de tickets solucionados/fechados.

### Disponibilidade exibida como travessão

Significa falta de dados suficientes para MTBF ou MTTR. O plugin não mostra mais `0,00%` quando não consegue calcular.
