# Joinotify Bling ERP Integration

Este plugin integra o [Joinotify](https://meumouse.com/plugins/joinotify/) com o sistema ERP [Bling](https://www.bling.com.br/) para permitir o envio de notificações automáticas quando ocorrem eventos de Nota Fiscal Eletrônica (NFe) no Bling.

## Funcionalidades

- **Autenticação OAuth2 com o Bling:** Conecte sua conta do Bling ao WordPress para receber um `access_token` que permite ao plugin se comunicar com a API do Bling.
- **Recebimento de Webhooks de NFe:** O plugin registra endpoints para receber eventos do Bling (nota fiscal criada, atualizada, cancelada, etc.).
- **Gatilhos no Joinotify:** Novos acionamentos ("triggers") são adicionados ao Joinotify, correspondentes a eventos do Bling, como NFe autorizada, cancelada, rejeitada, etc. Você pode criar fluxos de automação no Joinotify usando esses acionamentos.
- **Placeholders de Nota Fiscal:** Variáveis de texto (placeholders) adicionais são disponibilizadas para usar nas mensagens do Joinotify, por exemplo: número da nota fiscal, status, valor total, nome do cliente, etc.
- **Renovação de Token:** Uma interface para renovar manualmente o token de acesso do Bling a partir do painel WordPress.

## Instalação

1. Certifique-se de que o plugin **Joinotify** (versão 1.4.0 ou superior) está instalado e ativo.
2. Instale este plugin **Joinotify Bling ERP Integration** e ative-o.
3. No WordPress, navegue até **Ferramentas > Bling ERP** para configurar as credenciais:
   - Informe o **Client ID** e **Client Secret** do seu aplicativo Bling.
   - Salve as credenciais e então clique em **Conectar ao Bling** para autorizar o acesso (você será redirecionado para o Bling e deverá permitir a autorização do aplicativo).
4. Após a autenticação, o token de acesso do Bling será armazenado. A página mostrará o status da conexão e quando o token expira.
5. No painel do Bling (em Desenvolvedor > Aplicativos > Webhooks), configure os webhooks para **Nota Fiscal (invoice)** nos eventos desejados (created, updated, deleted) apontando para o endpoint: `{URL do seu site}/wp-json/bling/v1/webhook`  
   Certifique-se de incluir os escopos de `invoice` no seu aplicativo Bling para habilitar esses webhooks.
6. No Joinotify, acesse **Configurações > Integrações** e ative a integração "Bling ERP". Após ativar, você verá os novos acionamentos disponíveis ao criar ou editar um fluxo de automação.
7. Crie fluxos no Joinotify utilizando os novos acionamentos do Bling (por exemplo: *Nota fiscal autorizada (Bling)*). Você poderá usar as variáveis de texto como **{{ bling_invoice_number }}**, **{{ bling_invoice_status }}**, etc., em suas mensagens.

## Uso

- **Acionamentos Disponíveis:**
  - **Nota fiscal criada (Bling):** Disparado quando uma NFe é criada no Bling (estado pendente).
  - **Nota fiscal autorizada (Bling):** Disparado quando uma NFe é autorizada pela SEFAZ (situação 5 ou 6 no Bling).
  - **Nota fiscal cancelada (Bling):** Disparado quando uma NFe autorizada é cancelada (situação 2).
  - **Nota fiscal rejeitada (Bling):** Disparado quando uma NFe é rejeitada durante a autorização (situação 4).
  - **Nota fiscal denegada (Bling):** Disparado quando uma NFe é denegada pela SEFAZ (situação 9).
  - **Nota fiscal excluída (Bling):** Disparado quando uma nota fiscal é excluída definitivamente no Bling.
- **Placeholders de Mensagem:** Ao montar mensagens nos fluxos, você pode usar:
  - `{{ bling_invoice_number }}` – Número da nota fiscal.
  - `{{ bling_invoice_status }}` – Situação atual da nota (por exemplo, Autorizada, Cancelada).
  - `{{ bling_invoice_total }}` – Valor total da nota fiscal.
  - `{{ bling_client_name }}` – Nome do cliente/destinatário da nota.
- **Renovação de Token:** O token de acesso do Bling expira periodicamente. O plugin irá tentar renová-lo automaticamente usando o *refresh token*. Você também pode, a qualquer momento, clicar em **Atualizar Token Agora** na página de configurações para renovar manualmente.

## Requisitos

- PHP 7.4 ou superior.
- Plugin Joinotify ativo.
- Uma conta no Bling com um aplicativo registrado (para obter Client ID/Secret e configurar webhooks).

## Segurança

- As requisições de webhook do Bling são validadas usando a assinatura HMAC (cabeçalho `X-Bling-Signature-256`) gerada com o *Client Secret*. As mensagens não autenticadas serão descartadas.
- Certifique-se de manter seu Client Secret seguro e não divulgá-lo.

## Suporte

Este plugin foi desenvolvido pela equipe **MeuMouse.com**. Para dúvidas ou suporte, entre em contato através dos canais oficiais.
