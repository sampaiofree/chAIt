# chAIt

SaaS de atendimento WhatsApp por workspace, com Laravel no backend e Vue 3 + Inertia na aplicação web. Chat, editor e execução de fluxos estão implementados localmente. O bloco IA está em validação local; a operação com a UAZAPI contratada e a conta OpenAI do cliente ainda precisa de homologação.

## Configuração

Defina `UAZAPI_BASE_URL` e `UAZAPI_ADMIN_TOKEN` no ambiente do servidor. O token administrativo permanece no Laravel, e o token por instância é criptografado no banco. Cada workspace pode iniciar uma instância pelo botão **Conectar com QR Code** na tela de conversas.

O histórico das mensagens é consultado na UAZAPI. O Laravel guarda contatos, atribuição, estados das conversas e notas. Com o chat em tempo real ativo, também guarda os metadados da última mensagem e uma prévia textual limitada para a lista; não mantém o histórico completo. A lista é lida do banco, com importação inicial e reconciliação completa a cada 24 horas em segundo plano. Consulte [ativação e operação do chat](docs/operacao/chat-realtime.md).

Administradores configuram a chave própria em **Configurações → OpenAI**. Ela é criptografada por workspace e não é devolvida à interface. O bloco IA usa contexto remoto durante a sessão, limpa itens e conversa ao encerrá-la e mantém apenas metadados locais. A remoção ou troca da chave encerra sessões ativas e preserva temporariamente a credencial criptografada necessária para concluir a limpeza remota.

Para mensagens de voz, disponibilize FFmpeg com `libopus` e FFprobe ao PHP; configure `VOICE_FFMPEG_PATH` e `VOICE_FFPROBE_PATH` se precisar de caminhos absolutos. A gravação exige contexto seguro (HTTPS), permite ouvir antes de enviar e tem limite de dois minutos/10 MB. Configure o timeout do PHP/proxy para pelo menos 60 segundos. Consulte os detalhes e critérios de aceite na [integração UAZAPI](docs/integracoes/uazapi.md).

## Desenvolvimento

1. Copie `.env.example` para `.env` e configure banco e serviços.
2. Rode `composer install` e `npm install`.
3. Gere a chave com `php artisan key:generate`.
4. Rode `php artisan migrate`.
5. Inicie o ambiente com `composer dev`.

O ambiente local usa PostgreSQL em `127.0.0.1:5433`, com bancos separados `chait` e `chait_test`, e filas Redis/Horizon. Configure `.env.testing` antes de executar testes. Veja [configuração, migração e recuperação](docs/desenvolvimento/postgresql.md).

Verifique voz com `php artisan test --compact tests/Feature/Chait/VoiceMessageTest.php` e `npm run test:voice` (Node >=22.13). Os testes de backend usam os processadores de áudio e dados sintéticos; a CI instala FFmpeg e executa as duas suítes.

## Documentação

- [Índice e estado de implementação](docs/README.md).
- [Registro de decisões](docs/planejamento/decisoes.md).
- [Chat e multiatendimento](docs/modulos/chat-multiatendimento.md).
- [Interface desktop e mobile](docs/interfaces/chat.md).
- [Regras de design e layout](docs/interfaces/regras-design-layout.md).
- [Integração UAZAPI](docs/integracoes/uazapi.md).
- [Editor de fluxos — etapa posterior](docs/modulos/editor-de-fluxos.md).
- [Bloco IA](docs/modulos/bloco-ia.md).

## Verificação da interface

A navegação compartilhada e os padrões de formulários estão descritos em [Layout e design](docs/interfaces/design-interface-chait.md).

Para os testes de navegador, configure `.env.testing`, instale o Chromium com `npx playwright install chromium` e execute `npm run test:browser`. O comando compila a aplicação e usa um servidor local na porta 8895 com um schema PostgreSQL exclusivo em `chait_test`, removido ao terminar. As respostas do WhatsApp são simuladas; os testes não enviam mensagens nem alteram o banco de desenvolvimento. As capturas dos temas claro/escuro e os rastros de falhas ficam em `test-results/`.

## Sequências

Módulo de programação sequencial de fluxos com versões imutáveis, inscrição pelo contato/Operações, pausa de saldo e reserva exclusiva. [Especificação e validação](docs/modulos/sequencias.md); operação do scheduler em [Deploy](docs/operacao/deploy.md).
