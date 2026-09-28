# IOS Indicators — IA / RCA com Gemini

## Fluxo

1. O ticket é classificado normalmente pelo IOS Indicators.
2. Quando o ticket está `Solved` ou `Closed`, a ação automática `AiRca` o considera elegível.
3. O plugin ignora tickets que já possuam uma task contendo `[IA-RCA]` ou `[IOS-AI-RCA-V1]`.
4. O contexto do ticket é consolidado com título, descrição, follow-ups e tasks humanas existentes.
5. E-mails, telefones e CPF podem ser redigidos antes do envio.
6. A Gemini API retorna JSON estruturado com diagnóstico, causa provável, ação recomendada, classificação, complexidade, esforço estimado e confiança.
7. O plugin cria uma `TicketTask` concluída contendo a análise.

## Importante sobre tempo

O tempo produzido pela IA é uma **estimativa simulada de esforço técnico**.

A task criada possui:

- `actiontime = 0`
- campo textual `Tempo estimado de atuação (IA)`
- campo textual `Tempo estimado IA (segundos)`

Dessa forma a estimativa de IA não altera o tempo real de trabalho registrado pelo GLPI.

## Configuração da chave

A chave nunca deve ser versionada.

Opção recomendada:

```bash
sudo install -o root -g www-data -m 640 /dev/null /etc/glpi/iosindicators.env
sudo nano /etc/glpi/iosindicators.env
```

Conteúdo:

```ini
GEMINI_API_KEY="SUA_CHAVE"
```

O plugin também aceita `GEMINI_API_KEY` disponibilizada diretamente ao processo PHP/Apache/PHP-FPM.

## Modelo

O modelo é configurável na tela do plugin. O padrão da versão 0.8.0 é:

```text
gemini-3.8-flash
```

## Ação automática

Após atualizar o plugin, verifique em:

```text
Setup > Automatic actions
```

A ação esperada é:

```text
AiRca
```

Frequência registrada pelo plugin: 300 segundos.

## Segurança de dados

Com `Redigir dados sensíveis` habilitado, o plugin substitui antes do envio:

- endereços de e-mail;
- números de telefone reconhecíveis;
- CPF no formato `000.000.000-00`.

Hosts, códigos de clientes e informações técnicas permanecem disponíveis porque são necessários para a análise operacional.
