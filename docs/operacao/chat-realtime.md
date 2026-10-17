# Chat em tempo real e controle de volume

Implementação de 16/10/2026. Ativação controlada por `CHAIT_REALTIME_ENABLED`. Não representa homologação de capacidade do servidor contratado.

Para a instalação inicial, siga a ordem completa de [Deploy do chAIt](deploy.md#primeiro-deploy), revisada em 17/10/2026. Este guia complementa o procedimento com detalhes de persistência e operação.

## Persistência e regras do produto

A lista vem de `threads`, contatos e estados locais nos dois modos. `GET /workspaces/{workspace}/conversations` não consulta a UAZAPI e retorna `data`, `selected_thread` e cursores de paginação local. A leitura local continua disponível quando o WhatsApp estiver desconectado. Um workspace novo começa sem conversas, mesmo que o número tenha chats anteriores no provedor.

Somente mensagens recebidas depois de `uazapi_connections.activated_at` descobrem contatos e conversas. A migração marca as conexões já existentes no instante da implantação; uma conexão nova recebe o marco na primeira confirmação de conexão. Reconectar o mesmo número não altera esse horário nem apaga conversas. Eventos `chats` atualizam apenas conversas já conhecidas. Mensagens enviadas, grupos, eventos históricos e mensagens com horário inválido ou anterior ao marco não descobrem contatos.

`/chat/find`, a importação inicial e a varredura periódica foram retirados da operação. `POST /workspaces/{workspace}/uazapi/import` responde `410` para clientes antigos, e jobs de importação ainda enfileirados terminam sem consultar o provedor. `chat:recover` recupera somente webhooks e operações já aceitos localmente; não busca eventos perdidos na UAZAPI. `chat_last_webhook_at` e `activated_at` são exibidos por `chat:metrics` sem conteúdo de mensagens.

A conversa mantém ID, direção, tipo e horário da última mensagem e uma prévia textual de até 200 caracteres. Mídias mantêm apenas tipo/metadados. Campos ausentes ou snapshots antigos não devem apagar uma prévia mais recente. Isso substitui a proibição anterior de persistir qualquer prévia; não autoriza armazenar o histórico completo.

Conteúdo de webhook necessário ao processamento é armazenado criptografado na caixa de entrada `uazapi_inbox`. Mensagens e operações não trafegam como corpos de jobs: jobs levam IDs. O conteúdo é apagado depois de todos os consumidores necessários concluírem; falhas/pêndencias expiram após 24 horas, com metadados técnicos preservados. Arquivos temporários ficam no disco privado `local`, sem base64 persistido, com a mesma retenção máxima. Backups dessa base devem respeitar a política de retenção da operação: a exclusão na base ativa não apaga backups anteriores.

Não há IndexedDB. O histórico é consultado ao abrir uma conversa e fica na memória da tela. Mensagens e recibos posteriores chegam pelo Reverb. Atualizar a lista, atribuição ou enviar uma mensagem não recarrega o histórico. Uma interrupção do canal é sinalizada; reconectar não dispara recuperação automática. Reabrir o chat consulta novamente as mensagens recentes disponíveis no provedor. Não se promete histórico completo ou recuperação de gatilhos nunca recebidos.

## Recebimento e processamento

O endpoint existente autentica a conexão/segredo, limita o corpo a 256 KiB e aceita `messages`, `messages_update`, `chats` e `connection`. Tokens são removidos antes da persistência. `200` indica aceitação durável; falha de despacho posterior é recuperável pela caixa de entrada. Eventos de mensagens são deduplicados por conexão e identidade. Recibos possuem identidade própria e podem afetar múltiplas mensagens; a ordem dos IDs não altera sua identidade. Snapshots de conexão/chats sem identificador de evento são aceitos novamente, pois um conteúdo igual pode representar uma transição posterior legítima.

A ordem de aceitação é respeitada por contato/conexão; o horário da mensagem deve ser igual ou posterior ao marco de ativação. Timestamps protegem a prévia contra eventos antigos. O frontend mescla histórico/eventos por ID e `track_id`, sem regredir entrega/leitura. Canais privados são individuais por usuário; destinatários são calculados novamente pelo servidor conforme o acesso vigente ao workspace/conversa. Uma transferência remove a conversa da tela dos usuários sem acesso. Nenhum token UAZAPI é entregue ao navegador.

Operações assíncronas retornam `202` com `data.operation_id`. O resultado chega como `operation.finished`; históricos grandes são enviados em partes transitórias. Envios com timeout ou execução interrompida ficam `confirmation_pending`, sem reenvio automático. Jobs atrasados revalidam responsável/permissão e estado da automação. Operações de conexão guardam a etapa confirmada para não recriar instâncias durante espera por cota.

## Ativação e infraestrutura

1. Fazer backup e aplicar as migrations pelo deploy normal. Preservar `APP_KEY` e arquivos privados.
2. Instalar Composer/npm pelos locks e gerar build. São necessários Redis, PostgreSQL, FFmpeg/FFprobe e extensões existentes do projeto.
3. Configurar credenciais próprias do Reverb, endpoint público seguro e origens permitidas. Nunca usar o broadcaster `log` com o chat ativo.

```dotenv
CHAIT_REALTIME_ENABLED=true
BROADCAST_CONNECTION=reverb
QUEUE_CONNECTION=redis
CACHE_STORE=redis
SESSION_DRIVER=redis
REDIS_QUEUE_RETRY_AFTER=240
REVERB_APP_ID=chait
REVERB_APP_KEY=CHAVE_PUBLICA_PROPRIA
REVERB_APP_SECRET=SEGREDO_PROPRIO
REVERB_HOST=ws.seu-dominio.com
REVERB_PORT=443
REVERB_SCHEME=https
REVERB_SERVER_HOST=127.0.0.1
REVERB_SERVER_PORT=8080
REVERB_ALLOWED_ORIGINS=seu-dominio.com
LOG_STACK=daily
LOG_LEVEL=warning
LOG_DAILY_DAYS=7
```

4. Supervisionar `php artisan reverb:start --host=127.0.0.1 --port=8080` e `php artisan horizon`, com reinício automático. Reverb é um serviço adicional, não ocupa workers PHP-FPM por conexão.
5. No domínio WebSocket, configurar proxy HTTPS para `127.0.0.1:8080`, preservando `/app/` e `/apps/`, HTTP/1.1, headers `Upgrade`, `Connection: upgrade`, host e tempo de leitura suficiente. Publicar somente a porta HTTPS; manter 8080 privada. Não habilitar logs de corpos, query strings sensíveis ou debug do Reverb.
   Em uma zona Cloudflare com configuração DNS completa, o certificado Universal SSL cobre apenas o domínio raiz e subdomínios de primeiro nível. Para um host mais profundo, confirme um certificado de borda específico ou use um host já coberto; uma falha de TLS na borda impede o navegador de chegar ao Reverb. Consulte as [limitações do Universal SSL](https://developers.cloudflare.com/ssl/edge-certificates/universal-ssl/limitations/).
6. Manter cron `schedule:run` a cada minuto. Executar `php artisan optimize`, `php artisan horizon:terminate` e `php artisan reverb:restart` após alterações, sob os gerenciadores de processos.
7. Validar sessão, assinatura de canal privado e tráfego ponta a ponta em homologação antes da liberação.

A configuração inicial mantém até dez workers: inbound 3, automations 3, ai 2, media 1 e default 1, ajustáveis por `CHAT_WORKERS_INBOUND`, `CHAT_WORKERS_AUTOMATIONS`, `CHAT_WORKERS_AI`, `CHAT_WORKERS_MEDIA` e `CHAT_WORKERS_DEFAULT`. Limites iniciais por workspace: inbound 2 e uma tarefa por categoria pesada. Esses valores precisam de medição; não certificam capacidade de produção.

Todas as chamadas UAZAPI compartilham espaçamento de 8 chamadas/s e até duas simultâneas por conexão. Administração usa uma cota independente por servidor. O limitador requer cache Redis compartilhado entre instâncias. Cota indisponível reage com reagendamento, sem dormir nos workers. Atendimento/envios têm prioridade, com oportunidades para consultas secundárias. Leituras temporariamente indisponíveis têm até três tentativas com atraso; `429` respeita `Retry-After`. Um timeout de mutação não autoriza repetir a chamada.

## Operação e validação

- `chat:recover`: reencaminha eventos e operações já aceitos localmente; não importa chats nem busca mensagens perdidas na UAZAPI.
- `chat:prune`: descarta conteúdo/arquivos temporários expirados; agendado a cada minuto.
- `chat:metrics`: contagens e idade dos pendentes, falhas e conteúdos próximos da expiração, sem corpos.
- Horizon registra espera em inbound/automations/ai/media/default; snapshots são coletados a cada cinco minutos. Encaminhar alertas da infraestrutura conforme os limites operacionais da instalação.
- Acompanhar idade da fila, falhas, latência, memória por processo, conexões do PostgreSQL, capacidade do Redis e reconexões do Reverb. A política de memória do Redis deve preservar filas/locks; não usar descarte arbitrário de chaves operacionais.

Testes novos: `tests/Feature/Chait/ChatRuntimeTest.php`, `tests/Frontend/chat-state.test.mjs` e `tests/Browser/chat-realtime.spec.ts`. Os testes PHP exigem `chait_test`; os de navegador criam schema descartável próprio. O teste de navegador usa `CHAIT_BROWSER_REALTIME=true CHAIT_BROWSER_PORT=8896 npx playwright test tests/Browser/chat-realtime.spec.ts` e simula WebSocket/UAZAPI. Não usa WhatsApp ou OpenAI reais.

Para retorno ao caminho anterior, desligar `CHAIT_REALTIME_ENABLED`, recarregar configuração e reiniciar Horizon/Reverb. Antes de desligar, concluir operações pendentes ou revisar seus estados; não reenfileirar envios `confirmation_pending`. Preservar as novas tabelas e a chave de criptografia. O comando de descarte continua necessário enquanto houver conteúdo temporário. Não executar o rollback da migration em uma base populada como mecanismo de reversão.

O teste `APP_ENV=testing php tests/Concurrency/chat-gate.php` valida espaçamento e concorrência Redis entre processos, sem SQL, chamadas externas ou `FLUSH`. Usa somente chaves com identificador aleatório e exige `chait_test`. Em ambientes cujo CLI limita memória a 128 MB, a suíte completa usa `php -d memory_limit=512M vendor/bin/pest --compact`; isso ajusta somente o executor de testes, sem alterar PHP-FPM.

### Exemplo de serviço e proxy

Adaptar usuário, caminhos, domínio e certificados ao servidor. Este exemplo não instala nem altera serviços automaticamente.

```ini
# /etc/systemd/system/chait-reverb.service
[Unit]
Description=CHAIT Reverb
After=network.target

[Service]
Type=simple
User=www-data
WorkingDirectory=/var/www/chait
ExecStart=/usr/bin/php /var/www/chait/artisan reverb:start --host=127.0.0.1 --port=8080
Restart=always
RestartSec=3
KillSignal=SIGTERM
TimeoutStopSec=240

[Install]
WantedBy=multi-user.target
```

O serviço Horizon deve usar o mesmo diretório/usuário, `ExecStart=/usr/bin/php /var/www/chait/artisan horizon`, reinício automático e pelo menos 240 segundos para encerramento gracioso.

```nginx
server {
    listen 443 ssl;
    server_name ws.seu-dominio.com;
    ssl_certificate /caminho/fullchain.pem;
    ssl_certificate_key /caminho/privkey.pem;

    location / {
        proxy_pass http://127.0.0.1:8080;
        proxy_http_version 1.1;
        proxy_set_header Host $host;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_read_timeout 3600s;
        proxy_send_timeout 3600s;
    }
}
```

Ao ativar o novo contrato de operações `202`, recarregar as abas do atendimento e de configuração já abertas. A confirmação final de uma operação é recebida pelo canal autenticado do navegador; se ele perder a conexão, não repetir envios por ausência de confirmação.
