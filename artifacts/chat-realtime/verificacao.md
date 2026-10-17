# Verificação da implementação de chat em tempo real

Entrega local de 16/10/2026. O caminho novo é controlado por `CHAIT_REALTIME_ENABLED`, desabilitado por padrão. Nenhuma publicação em produção, migração da base da aplicação ou mensagem real foi executada nesta implementação.

## Escopo entregue

- Lista persistida, projeção compacta, importação compartilhada de 200 chats e paginação local por cursor. Busca automática limitada a três páginas remotas, com continuação manual e indicação de resultados parciais.
- Caixa de entrada criptografada, deduplicação, ordenação por conversa, aceitação durável, recuperação de despacho e descarte de conteúdo temporário.
- Laravel Reverb/Echo, canais privados por usuário, distribuição conforme permissões vigentes, atualização por eventos e operações com resposta `202`. Histórico consultado na abertura; reconexão não busca mensagens automaticamente.
- Filas separadas, limites compartilhados por workspace/conexão, prioridade de atendimento, `Retry-After` e proteção contra repetição de envio incerto. Mídia preparada sob demanda e por referência.
- Configuração de logs, comandos de recuperação/descarte/métricas, migrations aditivas e guia de deploy com Reverb, proxy, cron e reinício de processos.

## Revisão final

Corrigida a deduplicação de snapshots de conexão/chats sem identidade do evento: um status conectado repetido depois de uma desconexão precisa ser aceito. Recibos normalizam o conjunto de IDs, independentemente da ordem. `Retry-After` aceita segundos e data HTTP. Estruturas inválidas de webhook são rejeitadas antes da aceitação durável. Um teste de rollback confirma que falha no trabalho de domínio não publica a mensagem nem mantém a projeção parcial.

Foram preservadas as alterações paralelas presentes no workspace. Execuções simultâneas no PostgreSQL de testes original causaram deadlock e recriação de tabelas durante duas verificações. A validação final utiliza uma instância temporária independente, mantendo banco `chait_test`, schema `public`, PostgreSQL e as proteções existentes dos testes. A instância temporária é encerrada após a verificação.

## Validações

- Frontend: 25 testes Node aprovados.
- Navegador: quatro cenários do novo modo e 20 cenários de regressão de atendimento/pausas aprovados. UAZAPI, autenticação do canal e WebSocket simulados nos cenários do navegador.
- TypeScript, ESLint, Pint e build de produção aprovados.
- Limitador Redis validado entre processos: espaçamento compartilhado, duas chamadas simultâneas e liberação das reservas.
- Reverb local validado com credenciais fictícias: assinatura privada autenticada e entrega real por WebSocket. Serviço de teste encerrado.
- PHP: **613 testes aprovados, 3.014 asserções**, em 26,73 segundos, com PostgreSQL isolado, HTTP simulado e bloqueio de chamadas não previstas. Comando: `DB_PORT=55439 php -d memory_limit=512M vendor/bin/pest --compact`; a porta aponta somente para a instância descartável desta revisão.

## Pendências para produção

Homologar a integração no servidor de destino, credenciais, origens, HTTPS/WebSocket, supervisores e capacidade sob carga antes de ativar. Dez workers são a configuração inicial de validação, sem garantia de capacidade de produção. O histórico completo, IndexedDB e recuperação de webhooks nunca recebidos continuam fora do escopo aprovado.

As auditorias de dependências encontraram 43 avisos em 12 pacotes Composer e seis avisos npm (um moderado, três altos e dois críticos). Os avisos atingem dependências que já existiam nos locks anteriores; os novos pacotes não acrescentaram avisos nessas auditorias. As versões anteriores foram preservadas. A atualização dessas dependências permanece uma pendência antes da liberação em produção.

Guia de ativação, operação e reversão: [chat-realtime.md](../../docs/operacao/chat-realtime.md).
