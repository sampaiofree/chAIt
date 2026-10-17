# Verificação local — Campos do contato

Data: 15/10/2026. Dados sintéticos, PostgreSQL `chait_test` e schemas isolados para concorrência/navegador. Nenhum disparo WhatsApp, chamada OpenAI paga ou homologação real executados.

| Verificação | Resultado |
| --- | --- |
| `composer ci:check` | Passou: lint PHP, lint JS/TS, formatação, tipos, 20 testes frontend e 567 testes PHP (2.819 assertions). |
| `npm run build` | Passou. Sem nova dependência de editor rico. |
| Chromium: chips, Botão, assistente, transporte e Menu | 25 cenários de regressão passaram. |
| Chromium final: chips e Botão | 8 cenários passaram após os últimos ajustes. |
| WebKit final: chips | 4 cenários passaram. |
| Compatibilidade do controle de formatação existente | Cenário `shared editor controls preserve text formatting and numeric values when saving` passou. |
| PostgreSQL: gravação/exclusão de campos | 4 disputas reais passaram: salvar/excluir e prévia/excluir nas duas ordens. |
| PostgreSQL: confirmação/cancelamento de envios | 3 disputas reais passaram: duas confirmações e cancelamento antes/depois da confirmação. Sem avanço duplicado/reabertura. |
| PostgreSQL: captura antecipada e timeout de Resposta | 4 disputas existentes passaram, mantendo captura/avanço únicos. |
| Temas/responsividade | 390, 768, 1024, 1280 e 1440 px; claro/escuro em Chromium e WebKit. 20 screenshots locais nesta pasta; inspeção visual das dez combinações em Chromium e amostra WebKit. |

## Comandos de reprodução

```sh
composer ci:check
npm run build
CHAIT_BROWSER_PORT=8899 npx playwright test tests/Browser/contact-template.spec.ts tests/Browser/content-button.spec.ts tests/Browser/flow-assistant.spec.ts tests/Browser/flow-transport.spec.ts tests/Browser/menu.spec.ts
CHAIT_BROWSER_ENGINE=webkit CHAIT_BROWSER_PORT=8899 npx playwright test tests/Browser/contact-template.spec.ts
CHAIT_BROWSER_PORT=8899 npx playwright test tests/Browser/layout.spec.ts --grep 'shared editor controls'
APP_ENV=testing DB_CONNECTION=pgsql DB_DATABASE=chait_test DB_URL= php tests/Concurrency/contact-templates.php
APP_ENV=testing DB_CONNECTION=pgsql DB_DATABASE=chait_test DB_URL= php tests/Concurrency/contact-template-sends.php
APP_ENV=testing DB_CONNECTION=pgsql DB_DATABASE=chait_test DB_URL= php tests/Concurrency/content-button-transitions.php
```

Os scripts de concorrência exigem ambiente testing/PostgreSQL/chait_test/DB_URL vazio, criam schemas exclusivos e os removem no fim. O navegador também cria seu schema próprio e deixa o provedor sem configuração real.

## Cobertura principal

`tests/Feature/Chait/ContactTemplateTest.php` cobre dados padrão, espaços Unicode no primeiro nome, telefone original, vazio/zero, normalização sem trim, estruturas malformadas, campos de outro workspace/inventados/ausentes, referências antigas, renomeação, campos excluídos protegidos, campos proibidos, URL completa/composta, escapes e injeção de componentes, limites após expansão, continuidade estática/dinâmica, orçamento incerto, recuperação de jobs, metadados seguros, paginação/isolamento de eventos, snapshots atualizados do prompt e privacidade, recusa confirmada e conciliação de respostas IA sem guardar corpo, lote atômico do construtor e remapeamento portátil de referências.

`tests/Browser/contact-template.spec.ts` cobre cursor, chips indivisíveis, seleção parcial/Delete, Backspace, composição sintética/acentos, undo/redo, formatação, cópia/colagem estruturada, texto externo literal/HTML ignorado, rejeição de outro workspace, troca bloqueada para Resposta, persistência/recuperação e dimensões dos seletores. A composição foi exercitada por eventos de navegador, sem afirmar teste de todos os IMEs físicos.

As suítes existentes continuam verificando pausas, atendimento humano, palavras-chave, versões imutáveis, espera antecipada, duplicação de webhooks e limites/permissões do bloco IA. A suíte frontend também verifica reaplicação de conflito preservando o documento completo.

## Pendência de produção

O contrato público UAZAPI documenta substituições próprias, sem escape/desativação comprovados. Os testes locais não demonstram igualdade entre conteúdo preparado e recebido. `config/flow-contact-template.php` permanece com conexões vazias: permite rascunhos, mas mantém publicação e novos envios/prompts com chips pendentes por conexão. Perfis sintéticos usados em testes não habilitam produção.

A homologação deverá testar padrões nativos literais, valores contendo esses padrões, URLs, rótulos/cópia, legendas e respostas IA. Não houve bloqueio automático de padrões, inserção de caracteres invisíveis ou alteração silenciosa de conteúdo. Notificações internas continuam fora da entrega.
