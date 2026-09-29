# Governança de IA — IOS Indicators 1.0

## Papel da IA

A IA no IOS Indicators não substitui o registro operacional do GLPI. Ela atua como uma camada assistiva para:

- resumir o contexto do incidente;
- estruturar diagnóstico, causa provável e ação recomendada;
- estimar esforço técnico;
- apoiar RCA e base de conhecimento;
- gerar insumos para comparação de eficiência potencial.

O agente de IA da solução é:

```text
IOS NORA — Núcleo Operacional de Resposta Assistida
```

## Separação entre dado real e estimativa

A solução diferencia claramente:

| Tipo | Origem | Onde fica |
|---|---|---|
| Tempo histórico | GLPI, abertura → solução | Task `[IOS-ACTIONTIME-SOLUTION-V2]` com actiontime |
| Estimativa IA | Gemini / IOS NORA | Task `[IA-RCA]`, sem actiontime |

A task IA/RCA mantém `actiontime = 0` para não contaminar relatórios reais do GLPI.

## Chave Gemini

A chave deve ficar fora do GitHub e fora do banco:

```text
GEMINI_API_KEY=...
```

Locais aceitos:

```text
/var/glpi/config/iosindicators.env
/var/glpi/config/.env
/var/glpi/config/*.env
/opt/glpi/glpi11/config/*.env
```

No Docker atual:

```text
/opt/glpi/glpi11/config:/var/glpi/config:rw
```

## Modelo recomendado

Para análise em alto volume, a configuração padrão da versão 1.0 recomenda:

```text
gemini-3.5-flash-lite
```

Motivo:

- menor custo operacional;
- baixa latência;
- adequado para respostas estruturadas;
- suficiente para gerar diagnóstico, causa provável, ação recomendada e estimativa.

## Redação de dados sensíveis

Quando habilitado, o plugin redige antes do envio:

- e-mails;
- telefones;
- CPF;
- outros padrões configurados no código.

A recomendação é manter esta opção habilitada.

## Histórico auditável

Cada execução relevante da IA/RCA é registrada em:

```text
/var/glpi/config/iosindicators-logs/ai-rca.jsonl
```

O registro pode conter:

- timestamp;
- ticket;
- task;
- origem manual ou cron;
- modelo;
- status;
- duração;
- estimativa em minutos;
- confiança;
- tokens;
- mensagem de erro;
- código HTTP, quando aplicável.

## Controle de erros e consumo

Para evitar excesso de chamadas, a rotina possui:

- lote configurável;
- timeout configurável;
- intervalo entre chamadas;
- cooldown após falha;
- tratamento especial para `429 TooManyRequests`;
- tratamento especial para `503 ServiceUnavailable`.

Quando ocorre 429 ou 503, o plugin registra a falha e evita repetir o mesmo ticket imediatamente.

## Limites de interpretação

A IOS NORA gera uma análise assistida. O resultado deve ser lido como:

- sugestão técnica;
- estimativa operacional;
- insumo para RCA;
- insumo para governança.

Não deve ser tratado como:

- diagnóstico definitivo sem validação;
- substituto de análise humana;
- tempo real trabalhado por técnico;
- evidência isolada de SLA.

## Uso em apresentação executiva

A narrativa recomendada é:

```text
A IA não altera o histórico real.
Ela cria uma camada comparativa para estimar o potencial de automação,
priorizar melhorias e gerar RCA estruturada sobre dados reais do GLPI.
```

Assim, a solução preserva governança, rastreabilidade e separação entre operação real e estimativa de IA.
