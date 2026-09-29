# IOS Indicators 1.0 — Visão Geral

## Propósito

O IOS Indicators é um plugin para GLPI 11 criado para consolidar indicadores operacionais de incidentes, estruturar tickets de monitoramento e demonstrar um modelo de governança com apoio de IA.

A versão 1.0 está pronta para apresentação como uma prova de conceito funcional: os dados nascem no GLPI, são classificados, enriquecidos, analisados e transformados em indicadores executivos e operacionais.

## Conceito de governança

O plugin organiza o fluxo abaixo:

```text
Monitoramento → GLPI → Classificação → Tratativa → Normalização → RCA → Indicadores → Governança
```

A governança é construída em quatro pilares:

1. **Estruturação do dado** — tickets passam a ter categoria, ativo, cliente requester e grupo NOC.
2. **Indicadores operacionais** — TTO, MTTR, MTBF, MTBR, disponibilidade, reincidência e cobertura.
3. **IA auditável** — a IOS NORA gera RCA e estimativa de esforço, com logs e histórico.
4. **Eficiência potencial** — comparação entre tempo histórico real e esforço estimado por IA.

## Componentes principais

| Componente | Função |
|---|---|
| Dashboard | Visões executiva, operacional, qualidade, eficiência IA e tempo real |
| Classifier | Classifica tickets GLPI sem consultar a API do Zabbix |
| AiRca | Integra com Gemini para gerar RCA estruturada |
| ActionTimeBackfill | Registra actiontime histórico por abertura → solução |
| EfficiencyMetrics | Compara ActionTime histórico com estimativa IOS NORA |
| Logs IA/RCA | Mantém histórico de sucesso, falha, token, task e ticket |

## Público-alvo

- Gestores de operação e NOC;
- gestores de contrato;
- governança de TI;
- equipes de observabilidade;
- times de melhoria contínua e RCA;
- áreas que precisam demonstrar ganhos de automação e IA sobre dados reais.

## Resultado esperado

A versão 1.0 permite apresentar:

- a situação operacional dos incidentes;
- a qualidade estrutural dos dados no GLPI;
- a evolução de tickets brutos para tickets governados;
- o potencial de ganho com IA;
- os pontos de recorrência e oportunidade de automação;
- uma narrativa executiva para transformação do GLPI em plataforma de inteligência operacional.
