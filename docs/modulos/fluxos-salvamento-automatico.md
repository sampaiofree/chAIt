# Fluxos: salvamento automático e ativação

O fluxo possui um único conteúdo visível e os estados **ativo** e **inativo**. Um fluxo novo é inativo. Enquanto ativo, seu editor é somente para consulta; para alterá-lo ou usar o Assistente IA, é preciso inativá-lo.

No fluxo inativo, o editor salva alterações automaticamente após uma pausa de 500 ms. Uma gravação pendente precisa terminar antes da ativação. O indicador informa quando há falha, e a tela mantém as alterações locais enquanto tenta novamente. O servidor valida a estrutura durante a gravação; a ativação valida também o conteúdo e as referências necessárias à execução.

Uma aba por vez assume a edição. O bloqueio é renovado a cada 20 segundos e expira após 60 segundos; outras abas exibem quem está editando. A IA mantém o planejamento e a aprovação existentes, mas suas alterações são gravadas no fluxo. Somente o usuário ativa; a IA não ativa o fluxo.

Inativar impede novas entradas e encerra as execuções existentes, inclusive suas esperas, menus e sessões de IA. Respostas externas ou mensagens já encaminhadas podem terminar; trabalhos posteriores verificam o estado antes de continuar. A reativação não retoma execuções encerradas.

Os seletores oferecem fluxos ativos. Uma referência que ficou inativa permanece identificada. Para ativar um fluxo com “Ir para outro fluxo”, seu destino precisa estar ativo; se o destino for inativado depois, a execução é encerrada ao chegar ao salto. Gatilhos e webhooks não iniciam fluxos inativos.

Sequências preservam suas próprias programações. Ao chegar à etapa, consultam o fluxo naquele momento: se estiver inativo, pulam a etapa e iniciam a próxima espera, ou encerram a participação se não houver próxima etapa. Se a execução da etapa for interrompida pela inativação, a sequência segue para a próxima espera. Não há retomada automática das execuções interrompidas.

A importação substitui o conteúdo de um fluxo inativo após validação e confirmação; a exportação fornece o conteúdo salvo. Os registros internos de execução continuam disponíveis para histórico, sem opção de escolher uma versão para executar.

Na migração, fluxos ativos conservam o grafo em execução e suas alterações ainda não publicadas são descartadas. Fluxos inativos conservam o grafo salvo; prévias separadas são descartadas e construções de IA antigas são canceladas. A migração precisa ser aplicada ao banco antes de usar o novo editor.
