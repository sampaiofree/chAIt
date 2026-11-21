# Permissões de conversas — verificação local

Data: 21/11/2026.

- Backend: 288 testes, 1754 asserções aprovadas. Conjuntos: ChatVisibility, ChatRuntime, UazapiChat, Sequence, Leads, Team, VoiceMessage, AutomationPause e WorkspaceIsolation.
- Frontend: `npm run types:check`, `npm run build` e `git diff --check` aprovados.
- Chromium: `CHAIT_BROWSER_WHATSAPP_CHECK=true npx playwright test tests/Browser/chat-visibility.spec.ts tests/Browser/lead-chat-navigation.spec.ts --reporter=line`: 10 cenários aprovados.
- Cenário de permissões: padrão Próprias no cadastro, gravação/recarga dos controles, consulta exclusiva à fila, ausência de ações de atendimento/automação, revogação detectada pelo intervalo existente e redirecionamento a Perfil sem o link de Conversas.
- Capturas de Equipe em 390 e 1440 px nos temas claro e escuro: `permissions-*.png` nesta pasta. Inspeção visual sem transbordamento.
- Migração `2026_11_21_000001_add_chat_visibility_permissions` aplicada no banco local, lote 10. Conversão e reversão exercitadas em teste isolado.
- App local: tela Equipe e formulário Adicionar usuário conferidos no Firefox, sem criar conta ou modificar permissões reais.

As respostas do WhatsApp nos testes são simuladas. Esta evidência não certifica integração real nem implantação em produção.
