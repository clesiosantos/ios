# Changelog

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
