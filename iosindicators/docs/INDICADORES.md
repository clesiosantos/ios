# Indicadores — IOS Indicators 1.0

## Regras gerais

- As abas operacionais usam a data de abertura do ticket.
- A aba **Eficiência IA** usa a data da solução.
- Indicadores são calculados com dados do GLPI.
- A IA gera estimativas separadas e não altera o actiontime real da task IA/RCA.
- Quando não há dados suficientes, o indicador deve exibir `—` em vez de `0,00%`.

## Visão executiva

| Indicador | Significado |
|---|---|
| Incidentes no período | Total de tickets de incidente no recorte |
| Em tratamento | Tickets ainda não solucionados ou fechados |
| Normalizados | Tickets solucionados ou fechados |
| Cobertura estruturada | Categoria + item + requester + assigned group |
| Disponibilidade estimada | `MTBF / (MTBF + MTTR)` |
| Hosts reincidentes | Hosts com duas ou mais ocorrências no período |

## Velocidade operacional

| Indicador | Fonte / regra |
|---|---|
| TTO médio | `takeintoaccount_delay_stat` |
| MTTR | Tempo médio de solução/reparo no GLPI |
| P50 solução | Mediana do tempo até solução |
| P90 solução | 90% dos tickets resolvidos abaixo deste tempo |
| P95 solução | 95% dos tickets resolvidos abaixo deste tempo |
| MTBF | Tempo médio entre o fim de uma ocorrência e a abertura da próxima falha do mesmo host |
| MTBR | Tempo médio entre reparos concluídos do mesmo host |
| Espera média | `waiting_duration` |
| TMA | ActionTime médio registrado |

## Qualidade do dado

| Indicador | Significado |
|---|---|
| Categoria preenchida | Tickets com categoria ITIL |
| Ativo vinculado | Tickets com item/ativo associado |
| Requester preenchido | Tickets com grupo cliente solicitante |
| Assigned to preenchido | Tickets com grupo NOC atribuído |
| Tickets marcados para IA/RCA | Tickets com task `[IA-RCA]` |
| RCA completa | Task com diagnóstico, causa e solução |

## Tempo Real

| Indicador | Significado |
|---|---|
| Abertos agora | Tickets ainda não solucionados ou fechados |
| Novos | Tickets em status novo |
| Atribuídos | Tickets já direcionados para atendimento |
| Pendentes | Tickets em espera |
| Clientes impactados | Clientes distintos com tickets abertos |
| Hosts impactados | Hosts distintos com tickets abertos |

## Eficiência IA

A comparação usa dois registros do mesmo ticket:

```text
[IOS-ACTIONTIME-SOLUTION-V2]
+
[IA-RCA]
```

Fórmula:

```text
Eficiência potencial IA =
(ActionTime histórico - Tempo estimado IOS NORA) / ActionTime histórico × 100
```

Interpretação:

| Resultado | Leitura |
|---|---|
| Maior que 0% | IA estimou esforço menor que o histórico |
| Igual a 0% | IA estimou esforço equivalente ao histórico |
| Menor que 0% | IA estimou esforço maior que o histórico |

Este indicador deve ser apresentado como **potencial**, pois compara uma estimativa com um histórico real.

## Disponibilidade estimada

Fórmula:

```text
Disponibilidade estimada = MTBF / (MTBF + MTTR) × 100
```

Interpretação:

- **MTBF** representa confiabilidade: quanto maior, melhor.
- **MTTR** representa capacidade de recuperação: quanto menor, melhor.
- **Disponibilidade** combina os dois.

Se MTBF ou MTTR não forem calculáveis, o plugin exibe `—`.

## Diagnóstico de cobertura

A aba de eficiência mostra quatro números importantes:

| Campo | Significado |
|---|---|
| Solucionados/fechados | Base de tickets elegíveis no período |
| Com ActionTime histórico | Tickets com task `[IOS-ACTIONTIME-SOLUTION-V2]` |
| Com IA/RCA | Tickets com task `[IA-RCA]` |
| Comparáveis | Tickets que possuem ambos os registros |

A eficiência só é calculada sobre os tickets comparáveis.
