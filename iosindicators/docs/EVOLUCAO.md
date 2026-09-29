# Evolução do IOS Indicators até a versão 1.0

## 0.1.x — Base técnica GLPI 11

- Criação do painel inicial de indicadores.
- Compatibilidade defensiva com GLPI 11.
- Substituição de SQL direto por `$DB->request()`.
- Página de diagnóstico técnico.
- Isolamento de falhas para evitar tela genérica do GLPI.

## 0.2.x — Classificação automática

- Criação do classificador de tickets de monitoramento.
- Reconhecimento de títulos `Problem:` e `Resolved in`.
- Criação de categorias.
- Criação e associação de ativos.
- Processamento histórico em lotes.
- Classificação imediata de tickets novos.
- Extração de cliente pelo host.
- Criação de grupos requester e grupos NOC.
- Mapeamento inicial de `SRV`, `DB`, `WEB`, `SW` e `FW`.

## 0.3.x — Indicadores operacionais

- Introdução de MTBF, MTBR e disponibilidade estimada.
- Indicadores de percentil P50, P90 e P95.
- Indicadores de completude estrutural.
- Recorrência por host.
- Distribuição por cliente, host, evento, severidade e tipo de ativo.

## 0.4.x — Organização em abas

- Dashboard dividido em Visão Executiva, Velocidade Operacional, Qualidade do Dado e Tempo Real.
- Persistência da aba ativa.
- Tooltips de indicadores.
- Tabelas e distribuições visuais.
- Cards de KPI mais legíveis e responsivos.

## 0.5.x a 0.7.x — Refinamento visual e estabilidade

- Redesign visual completo do dashboard.
- Padronização de cores, cards, bordas e hierarquia visual.
- CSS escopado no plugin.
- Correção definitiva de 404 dos assets CSS usando carregamento inline pelo PHP.

## 0.8.x — IA/RCA com Gemini

- Criação da ação `AiRca`.
- Integração com Gemini.
- Criação de task `[IA-RCA]`.
- Registro de diagnóstico, causa provável, ação recomendada, complexidade, estimativa e confiança.
- Histórico persistente em JSONL.
- Modelo econômico `gemini-3.5-flash-lite`.
- Controle de 429, 503, cooldown e intervalo entre chamadas.
- Configuração da chave via `/var/glpi/config`.

## 0.9.x — ActionTime e eficiência IA

- Criação da ação `ActionTimeBackfill`.
- Registro de task `[IOS-ACTIONTIME-SOLUTION-V2]`.
- Regra abertura → solução.
- Migração de task legada abertura → fechamento.
- Criação da página e depois da aba **Eficiência IA**.
- Comparação entre ActionTime histórico e estimativa IOS NORA.
- Correção da disponibilidade para não exibir 0,00% quando não há dados suficientes.
- Consolidação dos indicadores de governança.

## 1.0.0 — Versão de apresentação

A versão 1.0 consolida o plugin como uma solução demonstrável de governança operacional com IA:

- dashboard pronto para apresentação;
- classificação automática validada;
- IA/RCA operacional;
- ActionTime histórico auditável;
- eficiência potencial com IOS NORA;
- documentação embarcada no plugin;
- visão executiva para demonstrar valor, governança e evolução operacional.

## Próximas evoluções sugeridas

- exportação PDF/PowerPoint diretamente do dashboard;
- drill-down por cliente, host e evento;
- metas configuráveis de SLA, MTTR e disponibilidade;
- painel de custos operacionais estimados;
- curadoria humana da RCA gerada pela IA;
- publicação automática de artigos de base de conhecimento;
- score de maturidade operacional por cliente;
- modo multiambiente/multientidade com visão consolidada.
