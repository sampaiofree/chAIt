# Liberação dos recursos de fluxos — 17/10/2026

Por decisão do responsável, botões URL/Resposta/Copiar, menus Botões/Lista e campos dinâmicos estão disponíveis em todas as conexões atuais e futuras, inclusive produção. A validação real no WhatsApp será feita pelo responsável. As evidências anteriores permanecem em `artifacts/tunnel-homologacao/resultado.md`.

## Implantação

1. Implantar o código e os assets gerados por `npm run build`.
2. Reconstruir o cache com `php artisan config:cache`.
3. Reiniciar os workers com `php artisan horizon:terminate` (Horizon supervisionado) ou `php artisan queue:restart` (workers tradicionais).

Não há migrações de banco para esta alteração. As antigas variáveis `CHAIT_LOCAL_FLOW_MENU_CONNECTIONS`, `CHAIT_LOCAL_FLOW_CONTENT_BUTTON_CONNECTIONS` e `CHAIT_LOCAL_FLOW_CONTACT_TEMPLATE_CONNECTIONS` deixaram de ser lidas e podem ser removidas do ambiente.

## Validação operacional pelo responsável

Verificar URL, cópia exata, captura de Resposta, roteamento de Botões/Lista e expansão de campos em texto, legenda, botões e prompt IA. A UAZAPI pode substituir padrões nativos como `{{name}}` em texto e legenda; a liberação não corrige esse comportamento. Os limites da aplicação, permissões, validação de URLs e correlação de cliques continuam ativos.

## Verificação desta alteração

- Backend: 159 testes relacionados, 734 afirmações, incluindo publicação com `app.env=production`, limites, isolamento e correlação de cliques, campos dinâmicos e prompts IA.
- Frontend: 28 testes aprovados; checagem de tipos e build aprovados.
- Navegador: 14 cenários de Conteúdo/Botão, Menu e Assistente aprovados. Dois cenários de publicação foram repetidos após ajustar a espera pelo salvamento do rascunho; ambos passaram.
- Pint, ESLint nos arquivos TypeScript e Prettier nos arquivos alterados aprovados. A configuração existente do ESLint não inclui arquivos Vue.

Os testes utilizaram banco de testes e respostas simuladas. Nenhuma mensagem WhatsApp real foi enviada e nenhuma implantação em produção foi executada nesta alteração.
