# Documentação do chAIt

> **Atualizado em:** 2026-10-17
> **Base desta revisão:** comparação estática dos documentos com rotas, páginas Vue, controllers, serviços, models, migrations e testes presentes no repositório. Nenhum teste ou homologação externa foi executado nesta revisão.
> **Fase atual:** fundação do workspace, autenticação, chat WhatsApp, editor/runtime de fluxos, blocos, Assistente IA de Fluxos, Sequências, Webhooks Externos e configurações estão implementados localmente. Leads ainda não tem módulo próprio. O chat em tempo real depende de ativação e infraestrutura; integrações e operação real mantêm homologações parciais registradas nos documentos específicos.

## Decisões aprovadas

- PostgreSQL no desenvolvimento e testes isolados; Redis/Horizon nas filas. [Configuração e migração](desenvolvimento/postgresql.md).

- UAZAPI como provedor inicial do WhatsApp.
- Um número de WhatsApp por workspace, com isolamento dos dados.
- Laravel no backend e Vue 3 + Inertia.js no frontend web.
- Vue Flow para o editor visual de fluxos; versões publicadas executam no backend.
- Chat e multiatendimento como primeira entrega.
- Ao finalizar, liberar o responsável ativo e preservar o último atendente e histórico operacional.
- Manter o histórico completo de mensagens na UAZAPI, aceitando sua retenção limitada. A revisão de 17/10 mantém prévia local de até 200 caracteres e conteúdo temporário criptografado por até 24 horas nos dois modos, com lista local alimentada apenas por novas mensagens recebidas; ver [política e operação](operacao/chat-realtime.md).
- Atendimento humano somente por gatilho definido; sem ele, processamento normal da conversa.
- Responsável ou administrador pode responder, finalizar e criar notas.
- Dois seletores na lista; contato com nome, email, CPF e notas; anexos documento/imagem/vídeo e busca na coluna de conversas.
- Mensagem de voz gravada pelo microfone, com prévia antes do envio e limite de dois minutos/10 MB.
- Bolinha verde para mensagens não lidas, leitura compartilhada no workspace e painel de conversa fixo com rolagem no histórico.

Desktop e celular usarão a mesma aplicação web, com layout/navegação adaptados. Instalação como PWA permanece proposta opcional no [registro de decisões](planejamento/decisoes.md).

**Implementado localmente:** chat com voz e leitura compartilhada; configuração UAZAPI; editor com oito tipos de nó, pastas, publicação, gatilhos, importação/exportação e prévia compartilhada com Assistente IA; Sequências; Webhooks Externos e campos do contato nos fluxos. O bloco IA de atendimento inclui mídias autorizadas, tags, campos, modalidades recebidas e limpeza remota. O histórico completo não é copiado para o SaaS; capturas em campos do CRM e a retenção temporária do tempo real têm contratos próprios.

**Outras áreas confirmadas no código:** autenticação e verificação de e-mail, perfil, segurança/2FA, aparência, equipe e permissões existentes, workspace e ícone, configuração WhatsApp/OpenAI, etiquetas e campos personalizados. A navegação atual expõe Conversas, Fluxos, Automação e Configurações. Não há rotas nem páginas próprias para Leads/Contatos; o cadastro de contatos é alimentado pelas integrações e pelas ações atuais do chat/webhooks. O briefing de Leads permanece plano não implementado.

**Homologações registradas:** IA/OpenAI e quatro tipos de mídia com entradas sintéticas e CRM isolado; duas etapas reais de Sequências; apresentação e envio de Botões/Lista e Elemento Botão na conexão local 1; apresentação de Menu no iPhone. Isso não comprova todos os cenários em produção. Seleções interativas pelo webhook público e validação em outras conexões permanecem pendentes conforme os módulos. O código mantém `CHAIT_REALTIME_ENABLED=false` por padrão; implantação, capacidade e tráfego real do novo caminho exigem validação própria.

## Índice

| Documento                                                               | Responsabilidade                                                          | Situação                                                                                                       |
| ----------------------------------------------------------------------- | ------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------- |
| [Registro de decisões](planejamento/decisoes.md)                        | Decisões confirmadas, sequência de discussão e dependências.              | Atualizado após cada resposta do responsável pelo produto.                                                     |
| [Chat em tempo real](operacao/chat-realtime.md)                         | Lista local, Reverb, processamento temporário, ativação e recuperação.    | Implementado sob configuração; infraestrutura e capacidade exigem homologação.                                 |
| [Deploy](operacao/deploy.md)                                            | Comandos via SSH para primeira instalação, atualizações e verificação.    | Guia manual para servidor configurado.                                                                         |
| [Diagnóstico UAZAPI no primeiro deploy](operacao/diagnostico-uazapi-primeiro-deploy.md) | Diagnóstico de ativação e conexão na primeira instalação. | Procedimento operacional; não substitui validação da instância. |
| [Homologação local com túnel](operacao/homologacao-local-tunel.md)       | Acesso de webhook ao ambiente local para homologação.                     | Guia operacional, requer endpoint público temporário.                                                          |
| [Liberação dos recursos de fluxos](operacao/liberacao-recursos-fluxos.md) | Implantação e validação de botões, menus e campos dinâmicos. | Liberados por decisão do responsável em 17/10/2026; validação real a cargo dele. |
| [Ajuste da integração UAZAPI sem sincronização](ajuste-integracao-uazapi-sem-sincronizacao.md) | Decisão e contrato da lista local sem importação geral de chats. | Implementado; operação vigente detalhada no guia de tempo real. |
| [Chat e multiatendimento](modulos/chat-multiatendimento.md)             | Isolamento, permissões, atribuição, finalização e notas.                  | Fundação implementada; validação operacional da instância continua pendente.                                   |
| [Interface do chat](interfaces/chat.md)                                 | Desktop em quatro áreas e navegação mobile.                               | Primeira interface responsiva implementada; refinamento e teste em dispositivos continuam.                     |
| [Regras de design e layout](interfaces/regras-design-layout.md)         | Guia reutilizável para novas telas e revisão de interface.                | Consolida os padrões aprovados; recomendações adicionais identificadas como propostas.                         |
| [Briefing de layout e design](interfaces/design-interface-chait.md)     | Decisões da revisão visual, implementação e evidências de validação.      | Revisão implementada e validada localmente.                                                                    |
| [Integração UAZAPI](integracoes/uazapi.md)                              | Contratos e integração no backend.                                        | Integração implementada; testes reais parciais e limites registrados por recurso.                              |
| [Editor de fluxos](modulos/editor-de-fluxos.md)                         | Blocos, publicação, executor e gatilhos.                                  | Implementado localmente; aceite manual pendente.                                                               |
| [Importação e exportação JSON](modulos/importacao-exportacao-fluxos.md) | Transporte portátil de fluxos, recursos pendentes e guia para IA externa. | Implementado localmente; testes isolados de backend e navegador.                                               |
| [Assistente IA de Fluxos](modulos/assistente-ia-fluxos.md)              | Briefing, aprovação, prévia compartilhada, salvar/descartar e retomada.   | MVP implementado e testado; integração real requer chave OpenAI e worker ativo.                                |
| [Conteúdo — Elemento Botão](modulos/bloco-conteudo-elemento-botao.md)   | URL, Resposta e Copiar, envio sequencial e acesso pelo construtor IA.     | Liberado em todas as conexões; responsável validará em produção.                                          |
| [Bloco Menu](modulos/bloco-menu.md)                                     | Escolhas, saídas dinâmicas, espera, erros e Assistente IA do editor.      | Botões/Lista liberados em todas as conexões; responsável validará em produção.                   |
| [Bloco Condição](modulos/bloco-condicao.md)                             | Etiquetas, campos, comparação de texto, suporte IA e exclusão protegida.  | Implementado localmente; validação automatizada com dados sintéticos.                                          |
| [Bloco IA](modulos/bloco-ia.md)                                         | Contrato, chave por workspace, sessão e integração OpenAI.                | Implementado; homologação real parcial registrada na evolução Assistente IA, sem certificar todos os cenários. |
| [Assistente IA](modulos/bloco-assistente-ia.md)                         | Mídias, tags, campos e permissões do bloco IA existente.                  | Implementado; OpenAI e UAZAPI homologadas com dados sintéticos, CRM isolado e número de teste autorizado.      |
| [Sequências](modulos/sequencias.md)                                     | Programação, publicação, inscrição e executor compartilhado.              | Implementado, testes locais e duas etapas homologadas na UAZAPI.                                               |
| [Webhooks Externos](modulos/webhooks-externos.md)                       | Recepção JSON, mapeamentos e sincronização de contatos/tags.              | Implementado e testado localmente; sem gatilhos externos de automação.                                         |
| [Meta WhatsApp Tech Provider](integracoes/meta-tech-provider.md)        | Roteiro de credenciamento e integração oficial.                           | Plano de execução v1.1; não comprova credenciamento ou implementação.                                          |
| [Campos do contato nos fluxos](modulos/campos-do-contato-fluxos.md)     | Chips, resolução, proteção de referências e continuidade operacional.     | Liberado em todas as conexões; substituições nativas UAZAPI seguem como comportamento conhecido.                   |
| [Leads](modulos/leads.md)                                               | Listagem, filtros e ficha do contato.                                     | Não implementado; briefing funcional aprovado.                                                                 |
| [Interface mobile](interfaces/mobile.md)                                | Experiência e navegação no celular.                                       | Comportamento observado e distinções em relação ao recorte inicial.                                            |
| [Ordem de implementação](planejamento/ordem-de-implementacao.md)        | Sequência recomendada e dependências.                                     | Planejamento original; não comprova o estado atual de entrega.                                                 |
| [Checklist de implementação e homologação](planejamento/implementar/checklist-pendencias.md) | Itens ainda não implementados e recursos prontos que aguardam liberação/homologação. | Revisado contra o repositório em 17/10/2026; ver limites de cada item. |
| [Notificações operacionais de fluxos](planejamento/notificacoes-operacionais-fluxos.md) | Proposta de notificações sobre falhas e resultados desconhecidos. | Não implementado; consta como pendência no checklist. |
| [PostgreSQL no desenvolvimento](desenvolvimento/postgresql.md)          | Ambiente local, migração e testes isolados.                               | Guia de desenvolvimento.                                                                                       |
| [Histórico e versões alternativas](historico/README.md)                 | Briefings anteriores, variantes e referências já consolidadas.            | Conteúdo preservado para consulta.                                                                             |

Testes de voz: `php artisan test --compact tests/Feature/Chait/VoiceMessageTest.php` e `npm run test:voice`. Dependências e operação em [Integração UAZAPI](integracoes/uazapi.md#mensagens-de-voz--implementação-de-12102026).

## Organização

Os documentos são agrupados por assunto. O estado de implementação fica registrado no próprio documento, independentemente da pasta.

- `desenvolvimento/`: configuração local, banco e testes.
- `integracoes/`: provedores externos e roteiros de credenciamento.
- `interfaces/`: comportamento visual, design e navegação.
- `modulos/`: especificações funcionais e técnicas dos recursos.
- `operacao/`: implantação e manutenção do servidor.
- `planejamento/`: decisões e ordem de implementação.
- `historico/`: versões anteriores e alternativas preservadas, incluindo referências de briefings já consolidados.

```text
docs/
├── desenvolvimento/
│   └── postgresql.md
├── ajuste-integracao-uazapi-sem-sincronizacao.md
├── historico/
│   ├── referencias/
│   │   ├── bloco-condicao.md
│   │   ├── sequencias.md
│   │   └── webhooks-externos.md
│   ├── README.md
│   ├── bloco-assistente-ia-v0.1.md
│   ├── decisoes-menu.md
│   ├── editor-de-fluxos-menu-v0.4.md
│   ├── meta-tech-provider-v1.0.md
│   └── uazapi-menu-v0.3.md
├── integracoes/
│   ├── meta-tech-provider.md
│   └── uazapi.md
├── interfaces/
│   ├── chat.md
│   ├── design-interface-chait.md
│   ├── mobile.md
│   └── regras-design-layout.md
├── modulos/
│   ├── assistente-ia-fluxos.md
│   ├── bloco-assistente-ia.md
│   ├── bloco-condicao.md
│   ├── bloco-conteudo-elemento-botao.md
│   ├── bloco-ia.md
│   ├── bloco-menu.md
│   ├── campos-do-contato-fluxos.md
│   ├── chait-flow.schema.json
│   ├── chat-multiatendimento.md
│   ├── editor-de-fluxos.md
│   ├── importacao-exportacao-fluxos.md
│   ├── leads.md
│   ├── sequencias.md
│   └── webhooks-externos.md
├── operacao/
│   ├── chat-realtime.md
│   ├── diagnostico-uazapi-primeiro-deploy.md
│   ├── deploy.md
│   └── homologacao-local-tunel.md
├── planejamento/
│   ├── implementar/
│   │   ├── bloco-conteudo-elemento-botao.md
│   │   ├── checklist-pendencias.md
│   │   └── especificacao-importacao-exportacao-fluxos-chait.md
│   ├── decisoes.md
│   ├── notificacoes-operacionais-fluxos.md
│   └── ordem-de-implementacao.md
└── README.md
```

As cópias com nomes repetidos foram preservadas em `historico/` com nomes descritivos. As variantes do Menu contêm diferenças relevantes e permanecem acessíveis pelos documentos principais; esta reorganização não altera decisões de produto. O roteiro Meta v1.1 é a versão principal, e a v1.0 permanece no histórico.

## Ordem de entrega original

Esta sequência registra a prioridade original; não descreve uma lista atual de funcionalidades por implementar. O estado vigente está no índice e nos módulos.

1. **Chat e multiatendimento:** base de atendimento e interface Vue responsiva.
2. **Editor de fluxos:** canvas, configuração dos blocos, publicação e integração com o chat.
3. **Aceite operacional:** executar cenários manuais com a conexão WhatsApp contratada e registrar ajustes encontrados.

Campos personalizados e etiquetas do contato agora atendem os blocos. Simulador, canais adicionais e publicação fora do workspace permanecem para etapas futuras.

## Como interpretar e atualizar

- **Aprovado:** confirmado explicitamente pelo responsável pelo produto.
- **Proposta:** recomendação para discussão; não constitui decisão aprovada.
- **Pendente:** comportamento ainda aberto.
- **Documentado pelo provedor:** descrito em fonte oficial; requer compatibilidade e teste no servidor contratado.
- **Implementado:** somente quando houver código e verificação correspondentes.

Ao decidir um ponto, atualizar o registro, o documento responsável, os critérios de aceite afetados e o histórico de versões. Discutir as demais decisões **uma por vez**, com recomendação e efeitos claros.

**Referências de verdade:** decisões aprovadas registram a intenção de produto; o código registra o comportamento implementado, descrito nos documentos vigentes. Diferenças observadas não são aprovações retroativas. Registros históricos e contagens de testes conservam suas datas; esta revisão não reexecuta testes ou homologações. Para o retrato de implementação e pendências, comece pelo [checklist](planejamento/implementar/checklist-pendencias.md); para especificações, use os módulos e integrações listados acima.

**Fonte de verdade do briefing:** estes arquivos do projeto, versionados junto ao código no repositório Git. Cópias em outros ambientes não se sincronizam automaticamente.
