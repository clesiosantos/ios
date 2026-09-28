# IOS - Indicadores de Incidentes para GLPI 11

Plugin para consolidar indicadores operacionais do ciclo de vida de incidentes no GLPI 11 e preparar o histórico de tratativas para RCA, Post-Mortem e futura Base de Conhecimento assistida por IA.

## Escopo da versão 0.1.1

- Dashboard próprio com filtros por período.
- Widget nativo para o Dashboard do GLPI.
- Cards no estilo executivo/operacional: Incidentes, Novos, Pendentes, Atribuídos, Planejados, Solucionados e Fechados.
- TTO médio a partir de `glpi_tickets.takeintoaccount_delay_stat`.
- TTS médio a partir de `glpi_tickets.solve_delay_stat`.
- MTTR como média do tempo até solução dos tickets solucionados no recorte.
- TMA como média de `glpi_tickets.actiontime`.
- Tempo total médio do incidente entre abertura e solução/fechamento.
- Tempo médio em espera.
- MBTR configurável, pois a reunião não fechou a fórmula/nomenclatura operacional.
- Cobertura de tarefas marcadas para IA/RCA.
- Percentual de RCA completa, exigindo na tarefa marcada os termos Diagnóstico, Causa e Solução.
- Contagem de atividades remotas pela etiqueta configurada.
- Respeita as entidades ativas da sessão GLPI.
- Por segurança, a consolidação exige a permissão **Ver todos os tickets (READALL)**.

## Padrão recomendado para a tarefa de IA

Exemplo de tarefa dentro do ticket:

```text
[IA-RCA]
Diagnóstico: serviço nginx sem resposta.
Evidências: timeout na porta 443 e erro no upstream.
Causa: processo php-fpm indisponível.
Solução: reinicialização controlada do php-fpm.
Comandos: systemctl restart php-fpm
Recomendação: criar alerta preventivo de saturação.
```

Quando houver execução remota, incluir também a etiqueta configurada, por padrão:

```text
[REMOTO]
```

## Instalação

1. Copie a pasta `iosindicators` para `GLPI_ROOT/plugins/iosindicators`.
2. Garanta que o diretório esteja disponível/persistido no volume do contêiner.
3. Acesse **Configuração > Plugins**.
4. Instale e habilite **IOS - Indicadores de Incidentes**.
5. Em **Configuração > Plugins > IOS - Indicadores**, ajuste as etiquetas e o período padrão.
6. Acesse **Plugins > Indicadores de Incidentes**.
7. No Dashboard nativo, adicione o card **Indicadores Operacionais - Incidentes**.

## Diretório em Docker

Para desenvolvimento e persistência do plugin, o volume mais importante é o diretório de plugins do GLPI, normalmente:

```text
/var/www/html/glpi/plugins
```

ou o caminho equivalente da imagem utilizada. O diretório `iosindicators` deve existir como subdiretório direto de `plugins`.

## Fórmulas / interpretação

| Indicador | Fonte inicial |
|---|---|
| TTO | `takeintoaccount_delay_stat` |
| TTS | `solve_delay_stat` |
| MTTR | média do `solve_delay_stat` dos tickets resolvidos |
| TMA | `actiontime` |
| MBTR | configurável; não definido no projeto até o momento |
| RCA completa | tarefa `[IA-RCA]` contendo Diagnóstico + Causa + Solução |
| Ações remotas | tarefas contendo `[REMOTO]` |

### Observação sobre TTS x MTTR

Na versão 0.1, TTS médio e MTTR usam a mesma base temporal do GLPI (`solve_delay_stat`). Isso é intencional até o projeto fechar uma definição distinta de MTTR (por exemplo, tempo técnico ativo, tempo após início de atendimento ou tempo de indisponibilidade confirmado pelo Zabbix).

## Próximas evoluções

- Separar `data/hora do evento Zabbix`, `data/hora de criação no GLPI`, `início da tratativa` e `normalização` quando o payload/integração do Zabbix for padronizado.
- Filtro explícito “Origem Zabbix”.
- Correlação de incidentes repetidos por CI/serviço/categoria.
- Post-Mortem automático.
- Índice de incidentes conhecidos.
- Sugestão de solução com base em incidentes anteriores.
- Endpoint/serviço para IA consultar o pacote estruturado do incidente.
- Automação somente com procedimentos previamente homologados.

## Compatibilidade

- GLPI >= 11.0.0 e < 11.0.99
- PHP >= 8.2

## Licença

GPLv3+


## Diagnóstico

Se o painel apresentar erro, acesse `plugins/iosindicators/front/diagnostics.php` como Super-Admin. A página valida o schema do GLPI e exibe avisos das consultas sem exigir acesso direto ao banco.
