# Homologação local com Cloudflare Tunnel

Este procedimento valida a integração UAZAPI e o chat em tempo real no Mac de desenvolvimento. Ele não valida capacidade nem disponibilidade do servidor definitivo.

## Preparação

1. Faça backup do PostgreSQL e aplique as migrations pendentes. Preserve `APP_KEY` e os arquivos privados.
2. Configure `CHAIT_REALTIME_ENABLED=true`, `BROADCAST_CONNECTION=reverb`, `QUEUE_CONNECTION=redis`, `CACHE_STORE=redis`, `SESSION_DRIVER=redis` e `REDIS_QUEUE_RETRY_AFTER=240` no `.env` local. Gere `REVERB_APP_KEY` e `REVERB_APP_SECRET` próprios. Mantenha `APP_URL=https://chait.test`.
3. Inicie Horizon, `schedule:work` e `reverb:start --host=127.0.0.1 --port=8080`. Defina `REVERB_ALLOWED_ORIGINS=chait.test`.
4. Inicie o proxy restrito com `NODE_EXTRA_CA_CERTS="$HOME/Library/Application Support/Herd/config/valet/CA/LaravelValetCASelfSigned.pem" node tests/Manual/webhook-tunnel-proxy.mjs`. Ele escuta apenas em `127.0.0.1:8877`, aceita somente `POST /webhooks/uazapi/{connection}/{secret}` e limita o corpo a 256 KiB. O log privado `storage/app/private/homologation-webhooks.jsonl` contém apenas hashes e metadados, sem corpos.
5. Inicie dois Quick Tunnels: um para `http://127.0.0.1:8877`, outro para `http://127.0.0.1:8080`. Configure `REVERB_HOST` com o host do segundo, `REVERB_PORT=443` e `REVERB_SCHEME=https`; reinicie Reverb e Horizon após alterar o `.env`.
6. Registre na UAZAPI a URL do primeiro túnel mais o caminho da rota `webhooks.uazapi`, gerado pelo Laravel com o segredo da conexão. Nunca imprima ou compartilhe o caminho completo. Verifique que `GET /` no túnel de webhook devolve 404 e que um POST de prova no endpoint válido recebe 200 e é processado.

### Inicialização reproduzível no `chait.test`

Execute cada processo em um terminal próprio, a partir da raiz do projeto:

```bash
php artisan horizon
php artisan schedule:work
php artisan reverb:start --host=127.0.0.1 --port=8080
NODE_EXTRA_CA_CERTS="$HOME/Library/Application Support/Herd/config/valet/CA/LaravelValetCASelfSigned.pem" node tests/Manual/webhook-tunnel-proxy.mjs
cloudflared tunnel --url http://127.0.0.1:8877 --protocol http2 --no-autoupdate
cloudflared tunnel --url http://127.0.0.1:8080 --protocol http2 --no-autoupdate
```

Copie apenas o **host** do túnel de Reverb para `REVERB_HOST` no `.env`, mantenha `REVERB_PORT=443` e `REVERB_SCHEME=https`, execute `php artisan config:clear` e reinicie Horizon e Reverb. O túnel do webhook fornece a origem para o comando abaixo; ele calcula o caminho assinado internamente e não o imprime:

```bash
php artisan chat:register-local-webhook https://ORIGEM-DO-WEBHOOK.trycloudflare.com
```

Use `php artisan horizon:status`, `php artisan schedule:list`, `php artisan chat:metrics` e `curl -o /dev/null -w '%{http_code}\n' https://ORIGEM-DO-WEBHOOK.trycloudflare.com/` para conferir os serviços. O `GET /` deve retornar 404. Ao reiniciar qualquer Quick Tunnel, atualize o respectivo host no `.env` ou registre novamente o webhook e reinicie os processos que leem a configuração. Não reutilize uma URL de túnel encerrado.

A tela de Conversas consulta a lista no banco antes de receber o status da conexão. A importação inicial ou a reconciliação vencida segue na fila; apenas o histórico e os avatares ainda sem URL local consultam a UAZAPI. As fotos visíveis são buscadas automaticamente, e a URL obtida fica salva no perfil do contato. Um erro de imagem força uma renovação; enquanto isso, as iniciais aparecem.

## Evidência de aceite

- Tempo real: um evento de conexão enviado ao webhook deve mudar a “Última confirmação” na página de conversas aberta, sem recarregar. Confirme a assinatura do canal privado e o processamento da caixa de entrada.
- Menu: envie Botões e Lista com `track_id` único. Confirme entrega, apresentação, seleção pelo cliente real e `buttonOrListid` com o tipo esperado no webhook. Texto digitado igual ao rótulo não pode ser interpretado como clique. Use os limites efetivamente observados, sem presumir o máximo do provedor.
- Conteúdo com Botão: valide URL, Resposta e Copiar separadamente. URL/Copiar exigem conferência visual da ação; Resposta também exige o evento explícito no webhook.
- Chips: use valores sintéticos e compare a string final preparada pelo Laravel com o conteúdo visto pelo destinatário, inclusive padrões nativos literais da UAZAPI, Unicode, legenda, URL, rótulo e cópia. Registre apenas igualdade, tipo e hashes das amostras.

Os perfis de liberação local são lidos de `CHAIT_LOCAL_FLOW_MENU_CONNECTIONS`, `CHAIT_LOCAL_FLOW_CONTENT_BUTTON_CONNECTIONS` e `CHAIT_LOCAL_FLOW_CONTACT_TEMPLATE_CONNECTIONS` no `.env`. Cada variável é um objeto JSON indexado pelo ID da conexão, no formato das respectivas configurações em `config/`. Esses valores são ignorados quando `APP_ENV` não é `local`; os padrões continuam vazios. Preencha somente formatos comprovados na conexão real.

Os scripts em `tests/Manual/start-live-*.php` e `send-live-*.php` documentam os ensaios. Eles usam IDs de conexão/contato desta sessão e enviam mensagens reais; confira o JID normalizado antes de reutilizá-los. Os scripts sem tipo obrigatório exigem `--send` explícito. Os fluxos de ensaio devem ficar inativos após a sessão.

## Encerramento

Antes de parar os túneis, retire os perfis locais, limpe a configuração e reinicie Horizon/Reverb. Reponha na UAZAPI o webhook anterior de `chait.test`, ou registre uma URL pública permanente antes de continuar a operação. Não deixe fluxos interativos dependentes de uma URL Quick Tunnel encerrada. Preserve as evidências sem credenciais e descarte dados temporários pelos comandos de manutenção existentes.

O ensaio de 16/10/2026 foi encerrado em 17/10/2026 com o `.env` anterior restaurado e os perfis locais vazios. O [resultado](../../artifacts/tunnel-homologacao/resultado.md) registra o que passou e a falha dos chips. A URL `chait.test` repõe o estado local anterior; ela não fornece recebimento público fora deste Mac.
