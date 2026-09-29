# ActionTime histórico e eficiência potencial IA

## Agente sugerido

**IOS NORA** — Núcleo Operacional de Resposta Assistida.

O nome é usado como referência conceitual para o agente de IA do fluxo de RCA e comparação de eficiência.

## ActionTime histórico

A rotina `ActionTimeBackfill` processa tickets com status **Closed** e calcula:

`actiontime = closedate - date`

O valor é gravado por meio de uma task auditável marcada com:

`[IOS-ACTIONTIME-CYCLE-V1]`

A task registra abertura, fechamento, segundos calculados e origem da execução. O GLPI recebe o `actiontime` da task e o plugin faz um fallback pelo objeto `Ticket` quando o ticket ainda permanece com `actiontime = 0` após a criação da task.

### Importante

Este valor representa **tempo de ciclo operacional entre abertura e fechamento**. Ele não deve ser interpretado como apontamento humano real de esforço.

Por padrão, `actiontime_fill_only_zero = 1`, preservando tickets que já possuem `actiontime > 0`.

## Processamento em lote

Configurações padrão:

- `actiontime_batch_size = 100`
- `actiontime_scan_limit = 5000`
- `actiontime_backfill_enabled = 0`
- `actiontime_fill_only_zero = 1`

É possível executar manualmente em **Configuração do IOS Indicators > Processar ActionTime agora** ou habilitar a Ação Automática `ActionTimeBackfill`.

## Eficiência potencial IA

A página:

`/plugins/iosindicators/front/efficiency.php`

compara tickets que possuem os dois registros:

1. task `[IOS-ACTIONTIME-CYCLE-V1]` com o tempo de ciclo;
2. task `[IOS-AI-RCA-V1]` com `Tempo estimado IA (segundos)`.

Fórmula do indicador:

`Eficiência potencial IA = (tempo de ciclo - tempo estimado IA) / tempo de ciclo × 100`

Também são calculados:

- tempo médio de ciclo;
- TMA estimado pelo agente;
- economia média potencial;
- tempo total potencialmente economizado;
- cobertura de tickets comparáveis;
- comparativo individual por ticket.

O indicador é uma **simulação de potencial** e não representa produtividade real de pessoas ou garantia de redução de tempo.
