# Changelog

## 0.7.1
- Corrigido o erro `404 Not Found` dos arquivos `iosindicators.css` e `iosindicators-modern.css` em instalações GLPI 11 cujo webroot não publica diretamente os assets em `/plugins/<plugin>/css`.
- O dashboard passa a carregar os dois arquivos CSS no lado do servidor e injetá-los inline na página, eliminando a dependência da rota pública dos assets.
- Removido o hook global `add_css` do plugin para evitar novas requisições 404 desnecessárias.
- Mantidos os arquivos CSS no plugin como fonte única de estilo, porém consumidos pelo PHP via filesystem.
- Simplificado o JavaScript da página para navegação entre abas, persistência da aba ativa, tooltips e pulso do Tempo Real, reduzindo risco de falhas visuais durante o carregamento.
- Versão incrementada para `0.7.1`.

## 0.5.0
- Redesign visual completo inspirado no mockup validado para o dashboard operacional.
- Nova paleta neutra baseada em `#f8fafc`, cards brancos, bordas `#e2e8f0` e tipografia em tons de slate.
- Abas passam a usar navegação segmentada com estado ativo em azul claro e menor peso visual.
- Barra de filtros passa a ter tratamento de card compacto com labels em caixa alta, foco azul e botão Aplicar em laranja.
- Banner de período recebe fundo neutro e destaque lateral azul.
- Hero/contexto das abas passa a ter visual de card executivo plano, removendo gradientes decorativos pesados.
- KPIs passam a usar cards brancos com ícones em blocos de cor suaves, números escuros em destaque e rodapé separado por linha tracejada.
- Tons dos KPIs deixam de colorir o card inteiro e passam a ser usados como acento visual nos ícones.
- Cards e painéis ganham hover discreto, borda sutil, sombra leve e melhor hierarquia visual.
- Painéis analíticos, distribuições, tabelas e matriz de completude recebem o mesmo design system.
- Responsividade revisada para desktop, notebook, tablet e mobile.
- CSS permanece isolado no escopo `.iosindicators-wrapper` para reduzir interferência nos estilos globais do GLPI.
- Versão incrementada para `0.5.0` para invalidar cache do CSS versionado.

## 0.4.1
- KPIs principais passam a ser encapsulados visualmente em cards individuais, com borda, sombra, fundo, espaçamento interno e comportamento responsivo.
- Reforçada a especificidade do CSS para evitar que estilos globais do GLPI sobrescrevam os cards do plugin.
- Grade dos indicadores passa a usar `display: grid` de forma explícita, com adaptação automática conforme a largura disponível.
- Ícones recebem área própria dentro do card e o botão de informações foi redesenhado para ficar discreto e consistente.
- Adicionado efeito de hover nos cards para melhorar a leitura e sensação de profundidade do dashboard.
- Ajustado o tamanho dos números, rótulos e metadados para dar mais destaque aos KPIs.
- Melhorada a responsividade das grades de Visão Executiva, Velocidade Operacional, Qualidade do Dado e Tempo Real.
- Versão incrementada para forçar atualização do CSS versionado no navegador.

## 0.4.0
- Dashboard reorganizado em abas: **Visão executiva**, **Velocidade operacional**, **Qualidade do dado** e **Tempo Real**.
- Nova navegação por abas com persistência da aba ativa entre recarregamentos da página.
- Layout fluido e responsivo com cards modernos, hero panel, grades adaptativas e painéis analíticos.
- Tooltips ricos via mouse over para explicar KPIs, painéis e distribuições.
- Nova aba **Tempo Real** com foco em tickets ainda abertos, clientes impactados, hosts impactados, severidade ativa e últimos tickets classificados.
- Melhor leitura visual das distribuições com barras, badges e tabela operacional.
- CSS carregado explicitamente no dashboard com versionamento para minimizar efeito de cache.
- Melhorias no parser do conteúdo do ticket para extrair **Host**, **Evento** e **Severity** com mais precisão.
- Ajustados rótulos amigáveis para tipos de ativo e eventos mais comuns.

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
