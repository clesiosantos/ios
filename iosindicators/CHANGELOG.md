# Changelog

## 0.3.0
- Novo dashboard executivo e operacional, com organização em blocos de visão executiva, velocidade operacional, qualidade do dado e distribuição analítica.
- Novos indicadores de confiabilidade e manutenção: MTBF, MTBR, disponibilidade estimada, P50, P90 e P95 do tempo até solução.
- Indicadores de qualidade estrutural: cobertura de categoria, ativo, requester, assigned group e cobertura estruturada completa.
- Indicadores de recorrência: hosts reincidentes, percentual de tickets recorrentes, top clientes, top hosts e top eventos.
- Distribuições por status, severidade e tipo de equipamento.
- Cards com metadados explicativos e interface responsiva com barras de distribuição.
- Cálculos mantidos exclusivamente sobre dados do GLPI 11, sem consultar ou alterar a API do Zabbix.
- Disponibilidade estimada calculada como MTBF / (MTBF + MTTR).
- MTBF calculado por host entre o fim de uma ocorrência e a abertura da próxima; MTBR entre os reparos concluídos do mesmo host.

## 0.2.3
- Extração automática do cliente pelo nome do host (`CLIENTE-XX-*`) e inclusão como grupo Requester do ticket.
- Criação/reutilização do grupo raiz `NOC` e de subgrupos conforme o tipo lógico do host.
- Associação automática do ticket ao subgrupo NOC correto como Assigned group.
- Mapeamento validado na amostra de 793 tickets: `SRV`, `SW`, `DB`, `WEB` e `FW`.
- `SRV`, `DB` e `WEB` são cadastrados como `Computer`; `SW` e `FW` como `NetworkEquipment`.
- Subgrupos padrão: `NOC > Servidores`, `NOC > Banco de Dados`, `NOC > Portais WEB`, `NOC > Switches` e `NOC > Firewalls`.
- Códigos desconhecidos são tratados de forma conservadora como `Computer` e encaminhados para `NOC > Outros`.
- Ao reprocessar tickets de `SW`/`FW`, o plugin remove do ticket o vínculo legado com `Computer` criado pela v0.2.2 e mantém o ativo antigo preservado para revisão posterior.
- Tela de configuração ampliada com controles para Requester, NOC e mapa de tipos de equipamento.

## 0.2.2
- Corrigido o parser para reconhecer tickets Zabbix já solucionados com título no formato `Resolved in ...: SIM | HOST | evento | descrição`.
- Adicionado fallback pelo bloco `PROBLEM NAME: SIM | HOST | evento | descrição` presente no conteúdo/follow-up dos chamados solucionados.
- O classificador agora aceita como evidência de origem Zabbix `Original problem ID`, `Link to problem in Zabbix`, `Problem name`, títulos `Problem:` e títulos `Resolved in`.
- A classificação imediata de tickets novos passou a ser independente da Ação Automática: pode ficar habilitada mesmo durante testes com o cron desativado.

## 0.2.1
- Corrigida a tela de configuração/execução manual no GLPI 11 que retornava `The action you have requested is not allowed`.
- Removida a validação CSRF duplicada do `front/config.php`; o GLPI 11 já valida requisições POST no `CheckCsrfListener` antes de carregar a página legada.
- Mantido o token CSRF no formulário, que continua sendo validado normalmente pelo núcleo do GLPI.

## 0.2.0
- Adicionado classificador automático de tickets de monitoramento usando apenas os dados já presentes no GLPI.
- Parser do padrão `Problem: SIM | HOST | evento | descrição`.
- Criação automática da árvore de categorias de monitoramento.
- Criação automática de hosts como ativos do tipo `Computer`.
- Associação automática entre ticket e host por `Item_Ticket`.
- Classificação retroativa em lotes, incluindo tickets solucionados e fechados.
- Hook para classificar tickets novos imediatamente.
- Nova Ação Automática `Classifier`, registrada para execução a cada 5 minutos.
- Cursor persistente para processamento histórico sem reler todo o banco a cada execução.
- Tela de configuração com habilitação do classificador, tamanho do lote, categoria raiz, execução manual e reinício do cursor.
- Categorias iniciais para `cpu_high`, `memory_high`, `disk_full`, `service_down`, `host_unavailable`, `packet_loss` e `latency_high`.
- O classificador é idempotente: reutiliza categorias, hosts e vínculos já existentes.
- A rotina não consulta nem altera a API do Zabbix.

## 0.1.2
- Corrigida a incompatibilidade do GLPI 11 que bloqueia `DBmysql->query()` com a mensagem `Executing direct queries is not allowed!`.
- Todas as leituras do plugin passaram a usar o Query Builder/iterator oficial por meio de `$DB->request()`.
- As agregações dos indicadores passaram a ser calculadas em PHP sobre o conjunto de tickets retornado pelo GLPI.
- As tarefas de RCA/IA são consultadas em lotes de até 1.000 tickets para evitar listas `IN` excessivamente grandes.
- Mantidas as validações de schema e a página de diagnóstico.

## 0.1.1
- Compatibilidade defensiva com GLPI 11.
- Registro explícito da classe do plugin.
- Validação de tabelas e campos antes das consultas.
- Consultas isoladas: um indicador com erro não derruba todo o painel.
- Página `front/diagnostics.php` para Super-Admin.
- Captura de exceções na página principal para evitar a tela genérica "An unexpected error occurred".
- Implementado cálculo de tempo total médio (abertura até solução/fechamento).

## 0.1.0
- Primeira versão do painel de indicadores.
