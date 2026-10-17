# PostgreSQL no desenvolvimento

O ambiente local usa PostgreSQL 15 em `127.0.0.1:5433`, banco e usuário `chait`. Redis processa as filas pelo Horizon; Herd serve o Laravel. Sessões e cache mantêm seus drivers configurados. O banco `chait_test`, pertencente ao usuário `chait_test`, é exclusivo dos testes. Ambos os usuários locais têm `NOSUPERUSER NOCREATEDB NOCREATEROLE`.

## Configuração

Configure no `.env`: `DB_CONNECTION=pgsql`, `DB_HOST=127.0.0.1`, `DB_PORT=5433`, `DB_DATABASE=chait`, `DB_USERNAME=chait`, `DB_PASSWORD` local, `DB_SCHEMA=public`, `DB_URL` vazio e `QUEUE_CONNECTION=redis`. Preserve `APP_KEY`: tokens e chaves OpenAI dependem dela. Não execute `key:generate` em um banco com credenciais existentes.

Depois de mudar o ambiente:

```bash
php artisan config:clear
php artisan horizon:terminate
php artisan horizon
```

Mantenha o último comando ativo em um terminal ou gerenciador de processos; confira em outro terminal com `php artisan horizon:status`. Reinicie somente os processos deste projeto, sem interromper outros Horizons ou instâncias PostgreSQL do Mac.

## Importação do SQLite

1. Coloque o Laravel em manutenção (`php artisan down`) e encerre o Horizon deste projeto. Aguarde requisições e workers em andamento terminarem; não execute scheduler, comandos de escrita ou testes contra o banco local durante a transferência.
2. Guarde `.env` e um backup consistente pela API SQLite de backup em `storage/app/database-backups/`. Use permissões `700` no diretório e `600` nos arquivos; este diretório é ignorado pelo controle de versão. Copiar apenas o arquivo SQLite durante gravações não garante consistência.
3. Crie um PostgreSQL vazio, configure o `.env` e execute `php artisan config:clear` e `php artisan migrate --force`.
4. Execute `php artisan database:import-sqlite /caminho/do/backup.sqlite --force`.
5. Confirme as verificações, a leitura das credenciais criptografadas, a prévia e as versões publicadas. Reinicie o Horizon, confira o estado e reabra o Laravel com `php artisan up`.

O importador abre a origem somente para leitura, exige correspondência de tabelas, colunas e migrations e recusa um destino com dados. Mantém o registro de migrations do destino. A cópia roda em uma transação: adia chaves estrangeiras para permitir referências circulares, compara todos os registros após normalizar booleanos, JSON e datas, verifica as referências e restaura a configuração original das restrições. Ajusta sequências de IDs antes de concluir. IDs, ciphertext, histórico, rascunhos, prévias e versões publicadas são preservados; arquivos de mídia continuam no storage existente.

Uma falha reverte os dados importados. A saída do comando mostra contagens e categorias de erro, sem payloads ou bindings. Conserve o SQLite original e o `.env` anterior. Antes de liberar novas gravações, uma falha de aceite permite voltar à configuração SQLite; depois de novas gravações no PostgreSQL, reconcilie essas alterações antes de qualquer retorno. Não recoloque uma cópia antiga em uso silenciosamente.

Os três jobs antigos na tabela SQL `jobs` permanecem como registros. O driver ativo é Redis. Não consumir a fila `database` nem repetir esses jobs: use **Retomar** para criar uma execução nova do assistente.

## Testes isolados

Copie `.env.testing.example` para `.env.testing`, configure a senha do usuário de teste e uma `APP_KEY` válida, mantenha `DB_DATABASE=chait_test`, `DB_SCHEMA=public` e `DB_URL` vazio. O arquivo é privado e ignorado. A classe base verifica ambiente, driver, banco e schema antes de permitir o `RefreshDatabase`; os testes podem recriar as tabelas de `chait_test` e nunca devem apontar para `chait`.

```bash
php artisan test --compact
APP_ENV=testing php tests/Concurrency/flow-assistant.php
npm run test:voice
npm run types:check
npm run lint:check
npm run format:check
npm run build
CHAIT_BROWSER_PORT=8896 npx playwright test --output /tmp/chait-browser-results
```

Playwright carrega `.env.testing` e cria um schema `chait_browser_<identificador>` exclusivo em `chait_test`, aplica migrations e fixtures e o remove ao terminar. Servidor e fixtures recusam outro banco ou um schema fora desse padrão. Execuções simultâneas precisam de portas diferentes. Após encerramento forçado que impeça a limpeza, remova somente o schema correspondente à execução, nunca `public`.

O teste de concorrência cria e remove um schema próprio `chait_concurrency_<identificador>`. Usa processos e conexões independentes para verificar conflito de revisão, fluxos independentes, retry após deadlock, idempotência, bloqueio durante IA, salvamento e rejeição de gerações antigas. Nenhuma chamada externa é permitida nesses testes. Os testes PHP também bloqueiam requisições não simuladas e desativam SSR. A CI provisiona seu próprio PostgreSQL 15 e usuário de teste sem privilégios administrativos.

## Diagnóstico do assistente

Confira `flow_ai_runs` e `flow_draft_sessions` usando os IDs do fluxo e da execução. Transações do assistente podem repetir até três vezes em falhas transitórias reconhecidas pelo Laravel; chamadas à OpenAI ficam fora delas e o job é despachado após a confirmação. O contexto de ferramentas permanece em memória: `provider_items` não é necessário para retomada e permanece como coluna por compatibilidade.

Falhas SQL são apresentadas com uma mensagem segura. O log `Flow assistant database failure` contém somente `run_id`, `flow_id` e SQLSTATE. Evite habilitar captura de SQL/bindings ou registrar a exceção completa: ela pode conter contexto privado. Os lotes anteriores permanecem na prévia e **Retomar** reconstrói o contexto a partir do estado confirmado. Salvar e publicar seguem manuais.

## Validação local de 14/10/2026

Transferência conferida em todas as 65 tabelas de dados, além do registro de migrations. Preservados os três fluxos, quatro versões publicadas, uma prévia e as credenciais criptografadas; a chave OpenAI foi lida com sucesso após a troca. Backup anterior em `storage/app/database-backups/20261014-125612/` e SQLite original mantido em `database/database.sqlite`.

Passaram 351 testes PHP (1.945 assertions), 40 testes Playwright e 17 testes frontend, além da verificação concorrente, tipos, lint, formatação e build. A proteção de testes recusou explicitamente `DB_DATABASE=chait` antes de qualquer assertion. A retomada real criou a execução 6, concluída em três ciclos sem erro SQL, e apresentou um novo plano aguardando Construir; o rascunho salvo e as versões publicadas permaneceram intactos.
