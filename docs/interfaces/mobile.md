# Interface mobile — comportamento atual e recorte original

> **Projeto:** chAIt — atendimento e automações via WhatsApp  
> **Versão:** 0.2  
> **Data:** 2026-10-16  
> **Status:** revisão documental do código atual, sem nova homologação de dispositivos. Decisões originais e diferenças observadas são identificadas abaixo.

## 1. Navegação implementada

Desktop e celular usam a mesma aplicação Vue 3 + Inertia e o mesmo backend Laravel. Abaixo de 1024 px, lista, conversa e perfil ocupam visualizações separadas. A lista é a entrada do atendimento; escolher uma conversa abre o chat. O ícone à direita do cabeçalho abre o perfil, cujo botão Voltar devolve à conversa. Voltar do chat mostra a lista.

O menu compartilhado abre em drawer de 280 px, limitado a 88% da janela. A troca de workspace fica no rodapé desse menu, junto ao usuário; não há seletor de workspace no cabeçalho atual da lista. O estado do drawer é independente da preferência desktop.

## 2. Ações e dados observados

| Local                 | Comportamento atual                                                                                                                                               |
| --------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Lista                 | Busca e botão de filtros na mesma linha; painel com atribuição, atendimentos abertos e etiquetas do contato. O aviso de conexão aparece quando há falha.            |
| Cabeçalho da conversa | Voltar, foto de identificação, estado e responsável, além do ícone que abre o perfil. |
| Compositor            | Sempre visível; texto, anexos e voz ficam disponíveis para o responsável ou em conversa sem atribuição. O primeiro envio abre e assume. Em conversa ocupada, os controles ficam bloqueados com indicação do responsável. |
| Perfil                | Ações de atendimento no topo: Abrir, Assumir para mim, Finalizar e, para administrador, atribuir a outro membro. Abaixo ficam dados do contato, automação, Sequência e notas internas. |

Abertos inclui atendimentos aguardando responsável (`open`) e em atendimento (`in_progress`). Concluídos não aparece no painel atual, embora o backend ainda aceite esse filtro. Essas diferenças em relação à decisão inicial também estão registradas na [Interface do Chat](chat.md).

Finalizar exige confirmação. Autorização de conversa, responsável e workspace é aplicada no backend; a presença do formulário de notas não concede permissão de gravação. Quem pode ver uma conversa ocupada pode escolher **Assumir para mim**; somente o administrador pode escolher outro destinatário.

## 3. Notas: decisão inicial e implementação atual

O recorte aprovado em 12/10/2026 excluía a criação de notas internas no celular. O código atual compartilha o perfil entre desktop e celular e apresenta histórico, campo e botão Adicionar nota também no mobile.

Esta revisão registra o comportamento existente e preserva a decisão inicial como histórico. Não declara que a ampliação tenha recebido aprovação de produto separada. Notas continuam vinculadas à conversa e nunca são enviadas ao WhatsApp.

## 4. Layout, mensagens e permissões

Cabeçalho e compositor permanecem visíveis; o histórico tem rolagem própria. Alvos de toque usam pelo menos 44 px. Os estados de gravação, envio, confirmação pendente, conexão e acesso são compartilhados com o desktop.

Com `CHAIT_REALTIME_ENABLED=false`, o chat usa o caminho de consultas periódicas. Com a opção habilitada, a lista é local e Reverb entrega eventos autorizados; o histórico fica na memória da tela após a consulta inicial. Prévia local e conteúdo temporário criptografado seguem o [contrato de tempo real](../operacao/chat-realtime.md). Não há IndexedDB nem promessa de funcionamento offline.

As regras de abertura humana, atribuição, pausa, finalização e notas permanecem em [Chat e multiatendimento](../modulos/chat-multiatendimento.md). Voz, anexos e limites estão na [Integração UAZAPI](../integracoes/uazapi.md). Outros módulos usam a mesma aplicação responsiva, com seus contratos próprios; o editor abre propriedades sobre o canvas no celular.

## 5. Referências de verificação

- Conferir lista → conversa → perfil → conversa → lista, mantendo a seleção.
- Conferir menu, troca de workspace e ações permitidas, incluindo bloqueios do backend.
- Conferir filtros conforme o comportamento atual, compositor, voz, anexos e notas no perfil.
- Conferir teclado virtual, áreas seguras, temas e ausência de transbordamento nos dispositivos relevantes.

Esses cenários são referências para verificação, não evidência de testes executados nesta revisão. Instalação PWA e notificações continuam propostas, sem aprovação implícita pela adaptação mobile.

## 6. Histórico

- **v0.2 — 2026-10-16:** descrito o código atual; atualizados breakpoint, ações, filtros e localização do workspace. Registrada explicitamente a presença de notas no perfil mobile, distinta do recorte inicial.
- **v0.1 — 2026-10-12:** definição inicial com foco em conversar com o lead e exclusão da criação de notas internas no celular; previa menu ⋮ para Transferir/Finalizar. Esse registro não descreve a interface atual.
