# Saídas personalizadas do bloco IA

Contrato vigente para blocos com `ai_outputs`. Blocos publicados sem esse campo continuam no contrato legado, inclusive sessões em andamento.

Blocos novos começam com Atendimento finalizado e Atendimento humano. Cada saída tem um identificador estável, nome e instruções em texto simples. A lista pode ficar vazia; entradas incompletas são permitidas em rascunhos, mas impedem a ativação. Nomes preenchidos são únicos e Continuar é reservado. A ordem é visual: a IA escolhe pelo contexto e continua conversando quando faltar informação.

Cada saída personalizada tem um conector `ai_exit:{id}`. A saída fixa `continue` (Continuar) é acionada pelo sistema quando ocorre falha técnica, inatividade ou limite de mensagens, sem ser oferecida à IA. Os motivos são registrados separadamente no histórico. Sem conexão, qualquer saída encerra o fluxo. Atendimento humano somente é aberto por Operações; o nome de uma saída não executa ações.

A ferramenta concluir_bloco_ia recebe `saida` (ID permitido) e `mensagem_final` (texto opcional). Sem texto, a transição é imediata. Com texto, o sistema aguarda confirmação do envio, mantendo somente metadados da transição pendente. Uma rejeição ou resultado desconhecido após conciliação segue Continuar. A recuperação não reenvia a mensagem nem chama a IA novamente. Interrupções humanas, cancelamento e inatividade invalidam a transição pendente. O limite de mensagens prevalece sobre a decisão da IA no turno que o atingir.

A conversão ocorre ao adquirir o bloqueio de edição de um fluxo inativo, em transação, com atualização das revisões do fluxo e do rascunho compartilhado. Cria as duas saídas iniciais e Operações para abertura humana não atribuída. O destino antigo de Continuar recebe tanto Atendimento finalizado quanto a saída operacional Continuar. A conversão é idempotente e respeita os limites do grafo; falhas revertem a transação. As versões publicadas são imutáveis. A interface informa a mudança dos eventos operacionais; a nova execução exige ativação.

Importação aceita o campo adicional no formato JSON 1.0. Arquivos legados são convertidos antes da validação final. Exportação preserva IDs, nomes, instruções e conexões. O catálogo e o assistente de edição usam o mesmo contrato.
