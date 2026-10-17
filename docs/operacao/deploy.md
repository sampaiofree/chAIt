# Deploy do chAIt

> Revisão documental em 17/10/2026, confrontada com o código e com a implementação de chat em tempo real; não representa homologação do servidor de destino.

Guia manual via SSH para um servidor já configurado. Execute os comandos na ordem, um por vez; só avance quando o anterior terminar com sucesso.

## Requisitos

- PHP 8.3+ com as extensões exigidas pelo Composer, incluindo `pdo_pgsql`, `redis`, `pcntl` e `posix` para banco e Horizon.
- Composer, Node.js 22.13+ e npm (compatíveis com o Vite 8 do projeto).
- PostgreSQL, Redis e FFmpeg com `libopus` + FFprobe disponíveis ao PHP.
- HTTPS configurado, raiz web apontando para `public/` e permissão de escrita em `storage/` e `bootstrap/cache/` para o usuário do PHP e das filas.
- Para o novo atendimento: domínio WebSocket com certificado válido (por exemplo, `ws.seu-dominio.com`), proxy para Reverb e gerenciador de processos para Horizon e Reverb. PHP-FPM, Horizon e Reverb são processos separados.
- Cron no usuário da aplicação, executado a cada minuto. Ele atende sequências, menus, conciliação de envios, recuperação da caixa de entrada e descarte do conteúdo temporário.

O primeiro deploy passa a ter duas fases: instalar e preparar a infraestrutura com `CHAIT_REALTIME_ENABLED=false`; depois validar o tempo real em homologação e ativá-lo de forma controlada. Nos dois modos, a lista vem do banco local e novas conversas dependem do webhook e da fila `inbound`. Com a opção desligada, a interface consulta periodicamente o backend; Reverb e os novos limites só passam a valer quando ela estiver habilitada.

Conecte-se ao servidor e entre na pasta do projeto, substituindo os exemplos:

```bash
ssh usuario@servidor
cd /caminho/do/chait
```

## Primeiro deploy

1. Envie os arquivos do projeto, incluindo `composer.lock` e `package-lock.json`. O banco e o usuário do PostgreSQL devem estar criados. Mantenha o acesso restrito enquanto prepara a instalação. Não envie `.env` de desenvolvimento, arquivos de teste, `public/hot` ou caches PHP gerados em `bootstrap/cache/`; preserve o `.gitignore` desse diretório.
2. Crie o `.env` somente se ele ainda não existir:

```bash
cp -n .env.example .env
```

3. Edite o `.env` antes de continuar:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://seu-dominio.com

DB_CONNECTION=pgsql
DB_SCHEMA=public
DB_URL=
QUEUE_CONNECTION=redis
CACHE_STORE=redis
SESSION_DRIVER=redis
REDIS_QUEUE_RETRY_AFTER=240

CHAIT_REALTIME_ENABLED=false
BROADCAST_CONNECTION=reverb
REVERB_APP_ID=chait
REVERB_APP_KEY=CHAVE_PUBLICA_PROPRIA
REVERB_APP_SECRET=SEGREDO_PROPRIO
REVERB_HOST=ws.seu-dominio.com
REVERB_PORT=443
REVERB_SCHEME=https
REVERB_SERVER_HOST=127.0.0.1
REVERB_SERVER_PORT=8080
REVERB_ALLOWED_ORIGINS=seu-dominio.com

CHAT_WORKERS_INBOUND=3
CHAT_WORKERS_AUTOMATIONS=3
CHAT_WORKERS_AI=2
CHAT_WORKERS_MEDIA=1
CHAT_WORKERS_DEFAULT=1

LOG_CHANNEL=stack
LOG_STACK=daily
LOG_LEVEL=warning
LOG_DAILY_DAYS=7
```

Preencha `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME` e `DB_PASSWORD` com os dados do servidor; configure também `REDIS_HOST`, `REDIS_PORT` e `REDIS_PASSWORD`. Defina `UAZAPI_BASE_URL` e `UAZAPI_ADMIN_TOKEN`. Ajuste `VOICE_FFMPEG_PATH` e `VOICE_FFPROBE_PATH` se os executáveis exigirem caminhos absolutos.

Use esse perfil `APP_ENV=production` também na instalação isolada de homologação, com banco, filas/cache Redis e credenciais próprios, separados da produção. O Horizon atual não define um perfil `staging`; trocar somente `APP_ENV` para esse nome deixaria o processo sem os supervisores esperados.

Substitua a chave e o segredo Reverb por valores aleatórios distintos, próprios desse ambiente. `REVERB_HOST` é o endereço público; `REVERB_SERVER_HOST` e `REVERB_SERVER_PORT` são o endereço interno de escuta. `REVERB_ALLOWED_ORIGINS` recebe o hostname da aplicação que abre o WebSocket, sem `https://`; vários hosts são separados por vírgula, sem espaços. Não usar `*`. O exemplo `.env.example` contém valores de desenvolvimento, incluindo broadcaster `log` e logs `debug`; substitua-os pelos valores acima.

4. Instale as dependências e coloque a aplicação em manutenção:

```bash
composer install --no-dev --prefer-dist --optimize-autoloader
composer check-platform-reqs --no-dev
php artisan config:clear
php artisan down
```

Em uma instalação nova, com `APP_KEY` vazia e sem dados criptografados anteriores, gere a chave uma única vez:

```bash
php artisan key:generate --force
```

Se estiver transferindo uma instalação ou importando dados existentes, use a `APP_KEY` original; não execute o comando de geração. Preserve essa chave em backup protegido: ela permite ler credenciais e conteúdo temporário criptografado.

Prepare o build e o banco:

```bash
npm ci --include=dev
npm run build
php artisan migrate --force
php artisan storage:link
php artisan optimize
```

O build precisa das dependências de desenvolvimento do npm. Reverb e Echo já estão nos arquivos de lock: não executar instaladores interativos de broadcasting no servidor. A migration `2026_10_16_000001_create_chat_runtime` entra pelo comando normal, mesmo com a opção desligada; ela adicionou projeção da conversa, metadados legados de importação, caixa de entrada criptografada e operações assíncronas. A migração posterior de ativação interrompe o uso dos metadados legados.

5. Configure um gerenciador de processos, como Supervisor ou systemd, para executar os dois serviços abaixo na pasta do projeto, com reinício automático, mesmo ambiente e acesso ao Redis e ao armazenamento privado:

```bash
php artisan horizon
php artisan reverb:start --host=127.0.0.1 --port=8080
```

Cada comando é um serviço independente; ambos devem continuar ativos após desconectar o SSH. Configure pelo menos 240 segundos para encerramento gracioso do Horizon. Os timeouts atuais são 180 segundos nos jobs pesados, 210 no supervisor e 240 no `retry_after` Redis; preserve essa ordem. Exemplos de systemd e Nginx estão no [guia de tempo real](chat-realtime.md#exemplo-de-serviço-e-proxy).

Com `CHAIT_REALTIME_ENABLED=false`, o Horizon consome `inbound`, `automations` e `default`. Com a opção habilitada, o mesmo Horizon gerencia cinco supervisores, inicialmente até dez workers: 3 `inbound`, 3 `automations`, 2 `ai`, 1 `media` e 1 `default`. Não é necessário iniciar cinco comandos Horizon. Os limites são configuráveis e precisam ser medidos no servidor; não representam capacidade homologada.

6. Configure o domínio WebSocket com HTTPS e proxy HTTP/1.1 para `127.0.0.1:8080`, preservando `/app/`, `/apps/` e os headers `Upgrade` e `Connection`. A porta interna 8080 fica privada. O navegador usa `wss://ws.seu-dominio.com:443`; a autenticação dos canais privados usa a sessão da aplicação em `/broadcasting/auth`. Não habilite logs de corpos ou debug do Reverb. Redija os segredos das URLs de webhooks nos logs de acesso.

7. Instale o cron no usuário da aplicação, substituindo os caminhos pelo diretório e PHP CLI corretos:

```cron
* * * * * cd /caminho/do/chait && /usr/bin/php artisan schedule:run >> /dev/null 2>&1
```

Confirme `php artisan schedule:list`: `chat:recover` e `chat:prune` executam a cada minuto, e `horizon:snapshot` a cada cinco minutos. O cron é necessário desde o primeiro deploy; não é exclusivo das sequências. Configure retenção e persistência do Redis para preservar filas e locks, sem descarte arbitrário de chaves. O armazenamento privado em `storage/app/private` deve persistir entre releases e ser acessível aos workers; não exponha essa pasta pelo servidor web.

O scheduler do Laravel não executa essas tarefas durante o modo de manutenção. Confira a execução efetiva do cron após `php artisan up`; `schedule:list` confirma o cadastro, não que as tarefas já rodaram.

8. Valide a infraestrutura e os cenários do novo caminho em homologação. Para habilitá-lo nesse ambiente, altere `CHAIT_REALTIME_ENABLED=true` no `.env`, mantenha `BROADCAST_CONNECTION=reverb` e execute:

```bash
php artisan optimize
php artisan horizon:terminate
php artisan reverb:restart
php artisan horizon:status
php artisan up
```

O gerenciador precisa reiniciar os serviços. Siga a seção de verificação abaixo. Mantenha acesso restrito durante a homologação; liberar o modo de manutenção não substitui esse controle.

A lista vem do banco nos dois modos e permanece vazia em um workspace sem conversas locais. A migração `2026_10_17_000002_track_uazapi_activation` marca as conexões existentes no instante do deploy, sem excluir dados; confira `activated_at` e `last_webhook_at` em `php artisan chat:metrics` após uma mensagem nova de teste. Nenhuma abertura de tela ou reconexão inicia importação. Valide que a lista permanece acessível durante indisponibilidade da UAZAPI e que o histórico de uma conversa conhecida retorna apenas de `/message/find`. Mantenha `QUEUE_CONNECTION=redis`, `CACHE_STORE=redis`, Horizon e o cron de `schedule:run` ativos nos dois modos; a fila `inbound` processa os webhooks. Reverb é necessário apenas quando o tempo real está habilitado.

9. Após homologação, repita a ativação controlada no servidor de produção e confirme os processos antes de liberar o acesso. Caso a instalação de produção ainda esteja na fase de infraestrutura, mantenha `CHAIT_REALTIME_ENABLED=false` e o acesso restrito. Preservar a opção desligada não homologa o novo atendimento.

## Atualizações

1. Na pasta da aplicação atual, ative o modo de manutenção:

```bash
php artisan down
```

2. Pause o consumo antes de substituir arquivos e aguarde a conclusão dos jobs em andamento:

```bash
php artisan horizon:pause
```

Confirme no Horizon que os jobs ativos terminaram; o modo de manutenção não interrompe um job já em execução. Se houver troca de diretório/release, coordene também a parada dos serviços no gerenciador antes de remover arquivos usados por eles.

3. Envie os arquivos atualizados, incluindo os dois arquivos de lock. Preserve o `.env`, a `APP_KEY` e o conteúdo persistente de `storage/`, inclusive o arquivo de manutenção. Ajuste o `.env` apenas se a versão exigir novas configurações.
4. Execute, um comando por vez:

```bash
composer install --no-dev --prefer-dist --optimize-autoloader
npm ci --include=dev
npm run build
php artisan config:clear
php artisan migrate --force
php artisan optimize
php artisan horizon:terminate
php artisan reverb:restart
php artisan horizon:status
php artisan horizon:continue
php artisan up
```

O gerenciador de processos deve reiniciar Horizon e Reverb automaticamente com o código atualizado. Se o Reverb ainda não tiver sido instalado e o novo caminho permanecer desligado, omita apenas `reverb:restart`. Aguarde o Horizon ficar ativo antes de `horizon:continue`; não libere a aplicação enquanto os serviços necessários estiverem indisponíveis. Se uma etapa falhar, interrompa a sequência, corrija o erro e retome dessa etapa; execute `php artisan up` somente após concluir o deploy.

Ao mudar a opção de ativação, recarregue as abas abertas de atendimento e configurações para que usem o mesmo contrato de operações `202`. Não repetir envios `confirmation_pending`, limpar filas ou executar rollback das novas tabelas como rotina de atualização. O [guia de tempo real](chat-realtime.md) descreve a reversão por configuração e a revisão de operações pendentes.

## Verificação

```bash
php artisan horizon:status
php artisan schedule:list
php artisan chat:metrics
curl --fail --show-error https://seu-dominio.com/up
```

Substitua o domínio pelo valor de `APP_URL`. Confirme que o Horizon está ativo e que `/up` responde HTTP 200. Abra também a aplicação no navegador para conferir login e carregamento da interface; `/up` verifica a inicialização da aplicação, sem garantir o funcionamento das integrações.

Com o novo caminho habilitado, confirme em homologação:

- Horizon com os cinco supervisores e Reverb ativo no gerenciador, sem reinícios contínuos; publicação HTTPS e assinatura de canal privado funcionando. Usuários sem permissão não recebem conteúdo da conversa.
- Lista obtida somente do banco local, vazia em workspace novo até a primeira mensagem recebida elegível; sem chamada a `/chat/find`, importação ou aviso de resultados parciais.
- Uma aba parada não consulta periodicamente conexão, lista ou histórico na UAZAPI. A abertura de uma conversa consulta as mensagens; envio e atualização da lista não recarregam o histórico.
- Mensagens/recibos atualizam a conversa aberta pelo Reverb. Ao interromper o WebSocket, a interface avisa; reconectar não consulta automaticamente o histórico.
- Operações retornam `202` e concluem pelo canal privado. Falta de confirmação ou timeout não dispara novo envio automático.
- `chat:recover` e `chat:prune` constam no scheduler e o cron está executando. Acompanhe idade das filas, falhas, latência e conteúdo próximo de expirar via Horizon e `chat:metrics`; mantenha os logs sem corpos, tokens ou conteúdo de mensagens.

Use UAZAPI e IA simuladas nos testes automatizados. Não execute testes PHP/navegador contra o banco da aplicação; eles precisam de PostgreSQL isolado. Uma validação com WhatsApp real só deve ocorrer com destinatário de teste expressamente autorizado. Veja os resultados e pendências de dependências da implementação no [relatório de verificação](../../artifacts/chat-realtime/verificacao.md); os testes locais não substituem a homologação do servidor.

Para identificar um webhook aceito mas sem conversa projetada no primeiro deploy, use o [diagnóstico de recebimento UAZAPI](diagnostico-uazapi-primeiro-deploy.md). A migração `2026_10_17_000003_track_uazapi_inbox_decisions` adiciona apenas códigos de decisão e contagem de entregas, sem guardar telefone ou conteúdo.

## Scheduler e Sequências

A migration `2026_10_14_000001_create_sequences` é aplicada pelo comando normal de deploy. Ela acrescenta versões, etapas, participações e contexto exclusivo; preserva contatos, conversas e execuções existentes. O gerenciador adota uma execução legada ativa por contexto; se houver duplicidades, o início é recusado e deve ser revisado antes de liberar automações. Não apagar execuções para contornar esse diagnóstico.

O cron configurado no primeiro deploy já atende as sequências; não cadastre uma segunda entrada para o mesmo `schedule:run`.

Em desenvolvimento, `composer dev` inicia também `php artisan schedule:work`. Esse comando pode ser executado separadamente como processo supervisionado. Não é necessário outro executor de fluxos. Horizon precisa consumir `automations`; cron encontra a próxima etapa e a fila reserva/inicia o fluxo.

Verificação:

```bash
php artisan schedule:list
php artisan horizon:status
```

`sequences:tick` reconcilia estados e encaminha vencimentos reais: use ciente de que pode iniciar fluxos elegíveis. Diagnostique `sequence_enrollments` (`status`, `due_at`, `remaining_seconds`, `blocked_reasons`, `terminal_reason`), `automation_contexts`, execução ligada, `flow_events` e `audit_logs`. Participações bloqueadas podem ser legítimas; uma execução estagnada precisa de revisão de espera/IA, fila e provedor. A reconciliação recupera jobs perdidos sem antecipar atrasos persistidos.

Teste concorrente somente em PostgreSQL descartável, com `APP_ENV=testing`, `DB_CONNECTION=pgsql`, `DB_DATABASE=chait_seq_test_*`, `DB_URL` vazio, migrations aplicadas e credenciais desse banco: `php tests/Concurrency/sequences.php`. Nunca apontar esse teste ao banco da aplicação.

Homologação real: `php tests/Live/sequences.php NUMERO_AUTORIZADO ID_CONEXAO`, apenas em ambiente local. Envia duas mensagens identificadas como teste, valida uma etapa de data específica e outra com intervalo de um minuto, e desativa os artefatos ao concluir. Exige destinatário autorizado, conexão ativa e contato sem fluxo/atendimento/participação vigente. Após falha ou confirmação incerta, revisar estado e identificador antes de qualquer nova tentativa.

## Webhooks externos

Instalar dependências (`composer install`) e executar `php artisan migrate --force`. A migração normaliza telefones internacionais válidos e cria a unicidade por workspace/telefone. Se houver colisões, a migração informa apenas IDs e interrompe antes de alterar contatos: resolver os cadastros em manutenção antes de tentar novamente; não há mesclagem automática. Preservar `APP_KEY`, pois o último exemplo é criptografado.

O processamento é síncrono e usa operações locais do CRM; não depende de workers Horizon. Manter `schedule:work` ou cron do Laravel para executar `webhooks:prune-receipts` diariamente. O exemplo completo fica até substituir/apagar; somente os metadados de recebimentos expiram em 30 dias.

A URL pública é `POST /webhooks/{chave}` com `Content-Type: application/json`, sem login/CSRF ou autenticação adicional. Garantir `APP_URL` com HTTPS em produção. Suprimir ou redigir URLs `/webhooks/` nos logs de acesso do proxy e nas ferramentas de observabilidade; a chave opaca é o segredo operacional. Não habilitar captura de corpos ou bindings SQL nesses endpoints.

Limites opcionais do servidor:

```dotenv
EXTERNAL_WEBHOOK_MAX_BODY_BYTES=262144
EXTERNAL_WEBHOOK_MAX_DEPTH=32
EXTERNAL_WEBHOOK_MAX_FIELDS=1000
EXTERNAL_WEBHOOK_REQUESTS_PER_MINUTE=120
```

Configurar o proxy/PHP para aceitar esse tamanho, deixando o Laravel devolver `413` com JSON quando excedido. Em implantação com várias instâncias, usar `CACHE_STORE=redis` para compartilhar o contador por Webhook. `200` é resultado síncrono; `429` inclui Retry-After; `500` indica falha com rollback. O remetente pode reenviar: eventos não são deduplicados, embora contatos e vínculos permaneçam únicos.

Verificações: `php artisan test --compact tests/Feature/Chait/ExternalWebhookTest.php` e `npx playwright test tests/Browser/webhooks.spec.ts` após build. Concorrência: `php tests/Concurrency/external-webhooks.php`, somente em PostgreSQL descartável com `APP_ENV=testing`, `DB_CONNECTION=pgsql`, `DB_DATABASE=chait_webhook_test_*`, `DB_URL` vazio e migrations aplicadas. Nunca usar o banco da aplicação nesse teste.

## Assistente IA de Fluxos

O desenvolvimento agora utiliza PostgreSQL, com bancos e usuários separados para aplicação e testes. Consulte [configuração local, transferência do SQLite e recuperação](../desenvolvimento/postgresql.md). O comando `database:import-sqlite` permite importar dados em um destino vazio já migrado; não o utilize para substituir um banco de produção populado.

As migrations `2026_10_14_220000_create_flow_assistant` e `2026_10_14_230000_track_flow_assistant_approval` adicionam o vínculo do plano aprovado vigente, a permissão `can_use_flow_assistant` e as tabelas de sessão temporária, conversa, mensagens, execuções e operações. Execute as migrations e o build pelo procedimento normal de deploy, seguido de `php artisan horizon:terminate`; o gerenciador deve reiniciar o Horizon com o código atualizado.

`ProcessFlowAssistant` usa `default` com `CHAIT_REALTIME_ENABLED=false` e `ai` com a opção habilitada, com timeout de 180 segundos, abaixo do timeout do supervisor (210) e `retry_after` Redis (240). O progresso do assistente usa consultas periódicas e não depende de SSE ou WebSocket. O chat em tempo real exige os supervisores e o Reverb descritos em [seu guia operacional](chat-realtime.md). Não repetir jobs de escrita antigos manualmente: use **Retomar** no editor, que inicia uma nova execução a partir da prévia atual.

Cadastre a chave OpenAI própria do workspace em Configurações → OpenAI. A integração do assistente não exige transcrição, enquanto blocos IA de atendimento mantêm a verificação de transcrição na publicação. Administradores/proprietários já podem usar o assistente; agentes precisam das permissões Criar e editar fluxos e Utilizar Assistente IA em Equipe.

Diagnóstico: confira `flow_ai_runs` (`status`, `purpose`, `cycles`, `error`, `started_at`, `finished_at`), `flow_ai_operations` e a geração/revisões em `flow_draft_sessions`. A prévia só substitui `flows.graph` ao Salvar. Descartar invalida a geração compartilhada; nenhuma destas ações publica. Ausência de worker deixa o turno em fila; depois de 210 segundos, a interface permite retomar. Histórico e sessão não expiram automaticamente nesta versão; fechamento nativo do navegador preserva recuperação, e descarte explícito pela aplicação remove a prévia.

Os limites técnicos ficam em `config/flow-assistant.php`. Não há painel ou orçamento mensal de custos no MVP. Use testes com `Http::fake` para a OpenAI e banco isolado para validar sem gerar consumo ou enviar WhatsApp.

Os testes de navegador permitem `CHAIT_BROWSER_PORT=8896 npx playwright test --output /tmp/chait-browser-results` para isolar execuções simultâneas. O padrão continua sendo a porta 8895.

### Bloco Condição — dependências PHP

Comparação de texto usa Unicode consistente com `intl` e `mbstring`. Ambas são requisitos do Composer; confirme as extensões no PHP CLI e no PHP utilizado pelo servidor web/workers antes de atualizar. O bloco não requer migration. Após atualizar o código e assets, reinicie os workers conforme o procedimento existente.

## Menu — atualização de 14/10/2026

Aplicar migrations (inclui `flow_menu_waits` e `flow_menu_receipts`), atualizar build e reiniciar Horizon após o deploy. O scheduler existente executa `menus:tick` a cada minuto para todos os fluxos. Numérico está disponível; Botões/Lista ficam bloqueados até registrar homologação e limites medidos por conexão em `config/flow-menu.php`. Recarregar configuração e workers após alterar a liberação. Ver [Bloco Menu](../modulos/bloco-menu.md).

## Chat em tempo real e controle de volume — 16/10/2026

O novo caminho é liberado por configuração, com migrations aditivas, Laravel Reverb, supervisores separados e caixa de entrada temporária criptografada. Consulte [ativação, retenção, testes e retorno ao caminho anterior](chat-realtime.md). Manter `CHAIT_REALTIME_ENABLED=false` até validar a infraestrutura e os cenários de homologação. `/up` sozinho não valida Redis, filas, assinatura de canais ou entrega de eventos.
