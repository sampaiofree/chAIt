Analisei a auditoria do CHAIT. O resultado é importante: o CHAIT possui atividades que consomem Redis continuamente, mesmo sem mensagens ou usuários ativos. Também foram identificadas situações que podem multiplicar jobs desnecessariamente.

Mas existe uma distinção: o relatório demonstra esses comportamentos no código, não comprova quanto o CHAIT está consumindo no servidor de produção.&#x20;

Texto colado.txt



## 1. Principais problemas identificados

| Problema                                | Impacto                                                                           | Prioridade             |
| --------------------------------------- | --------------------------------------------------------------------------------- | ---------------------- |
| Horizon realizando gravações constantes | Aproximadamente 18 comandos de escrita/s, mesmo ocioso, por instância configurada | Alta para investigação |
| Expiração de sessões de IA              | Uma sessão pode acumular vários jobs de expiração redundantes                     | Alta                   |
| Jobs aguardando capacidade              | Podem ser reagendados repetidamente a cada 1–3 segundos                           | Alta                   |
| Webhooks e recuperação                  | Podem gerar jobs duplicados durante atrasos                                       | Média                  |
| Workers consultando filas vazias        | Consultas Redis contínuas, mesmo sem trabalho                                     | Média                  |
| Polling das interfaces                  | Mais requisições e acessos ao Redis enquanto telas ficam abertas                  | Baixa                  |

O relatório encontrou mecanismos concretos de duplicação e reagendamento, mas não confirmou que estejam ocorrendo em grande volume na produção.&#x20;

Texto colado.txt



## 2. O que eu mudaria no CHAIT

Eu separaria as ações em duas frentes.

Correções de código: eliminar os agendamentos redundantes de expiração de IA, aplicar limites e espera progressiva aos reagendamentos e impedir despachos duplicados de jobs sem perder a capacidade de recuperação após falhas.

Otimização de infraestrutura: avaliar a quantidade real de supervisores e workers do Horizon, experimentar `block_for` para reduzir consultas ociosas e medir a atividade Redis antes de alterar os limites.

Não recomendo desligar o Horizon ou desabilitar a persistência RDB do Redis. O relatório não fornece evidências suficientes para justificar essas medidas.

## 3. Minha principal preocupação

O Horizon pode estar contribuindo para o volume de escritas no Redis, mas os picos de processamento que investigamos anteriormente podem envolver outras aplicações.

Além disso, o CHAIT compartilha a infraestrutura Redis com outros sistemas. Uma mudança no Redis global pode prejudicar filas, sessões e locks de outras aplicações.

Primeiro precisamos confirmar quantos processos do CHAIT estão realmente executando. O relatório analisou a configuração do projeto, não a quantidade efetiva de instâncias em produção.

## 4. Próxima etapa — Conferir os processos no servidor

Execute:

```
ps -eo pid,ppid,%cpu,%mem,args | grep -E '[a]rtisan (horizon|queue:work|schedule:work|reverb:start)'
```

Esse comando é somente de leitura.

Com o resultado, vamos identificar quantos processos Horizon, workers e Reverb estão ativos. Assim poderemos comparar a configuração descrita na auditoria com a execução real.

Minha avaliação: a auditoria encontrou melhorias relevantes e justificáveis no CHAIT. Eu corrigiria principalmente a multiplicação de jobs e os reagendamentos curtos, mas só redimensionaria o Horizon depois de medir sua atividade real.