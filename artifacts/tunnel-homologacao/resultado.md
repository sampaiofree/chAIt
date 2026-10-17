# Homologação local, 16/10/2026

Ambiente: Mac local, workspace 1, conexão UAZAPI 1, contato de teste autorizado. Os dois Quick Tunnels expuseram somente o receptor de webhook e o Reverb. Nenhuma liberação foi aplicada à produção. O log privado `storage/app/private/homologation-webhooks.jsonl` contém apenas tipo, horário, hashes e status HTTP, sem corpos ou segredos.

| Recurso | Envio e resultado no iPhone | Webhook / app | Resultado |
| --- | --- | --- | --- |
| Chat em tempo real | Mensagem comum enviada pelo iPhone durante a conversa aberta | `Conversation` recebido com HTTP 200; mensagem apareceu sem recarregar; Horizon/Reverb ativos | Validado neste Mac |
| Menu Botões | Três opções distintas tocadas no iPhone | Três `ButtonsResponseMessage` com IDs distintos e esperados; fluxo novo escolheu `b` e enviou a confirmação | Perfil local: até 3 opções, rótulos até 10 caracteres |
| Menu Lista | Décima opção tocada; novo fluxo escolheu `b` e enviou a confirmação | `ListResponseMessage` com ID esperado; texto comum “Escolha 10” chegou como `Conversation` sem ID de seleção | Perfil local: até 10 opções, rótulos até 10 caracteres |
| Conteúdo Botão URL | Abriu o endereço esperado no iPhone | Envio aceito pelo provedor | Perfil local restrito ao tamanho testado |
| Conteúdo Botão Copiar | Copiou exatamente o código esperado no iPhone | Envio aceito pelo provedor | Perfil local restrito ao tamanho testado |
| Conteúdo Botão Resposta | Clique recebido no iPhone; fluxo capturou o rótulo no campo sintético | ID de 60 caracteres preservado em ensaio direto; execução real recebeu `ButtonsResponseMessage` com ID exato e um recibo, e terminou com `wait_reply` respondido | Perfil local restrito ao tamanho testado |
| Chips em texto | O iPhone exibiu substituições nativas em padrões literais e dentro do valor do chip | Texto preparado pelo Laravel tinha SHA-256 `f57686ee2e1464648e61e8130b85a2595cbb14c5907707eee321f8369d9c546b`; recebido difere | **Bloqueado** |
| Chips em legenda | O iPhone exibiu substituições nativas nas duas posições | Legenda preparada tinha SHA-256 `e48f60884a1efcd0b68daec159dda5a6862600314adeb2d59ac512f6999396be`; recebido difere | **Bloqueado** |
| Chips em rótulo, cópia e URL | Rótulo, cópia com padrão literal e URL simples ficaram exatos no iPhone | Valores foram renderizados por `ContactTemplate` e enviados como botões; esse resultado não elimina a falha em texto/legenda | Parcial; perfil global de chips bloqueado |

O primeiro teste de roteamento falhou porque o fluxo foi iniciado em um cadastro de contato com dígito extra, enquanto a UAZAPI normalizou o chat para outro cadastro. A execução foi interrompida. Os dois ensaios seguintes usaram o contato correspondente ao JID recebido e passaram. Esse cuidado precisa constar da futura homologação de produção.

Os testes automatizados de Menu, Botão de Conteúdo e chat passaram: 114 testes, 364 afirmações. Eles incluem duplicatas, expiração, respostas antigas e autenticação do canal privado. Os cliques e a entrega reais acima complementam esses testes; duplicata e expiração não foram provocadas no iPhone nesta sessão.

Os limites dos perfis locais durante o ensaio foram deliberadamente iguais ou menores que as amostras exercitadas. O perfil de chips permaneceu vazio. A [documentação da UAZAPI para envio de texto](https://docs.uazapi.com/endpoint/post/send~text) descreve a substituição automática de placeholders. A capacidade e a disponibilidade do servidor definitivo continuam pendentes.

## Encerramento em 17/10/2026

Os processos dos Quick Tunnels, do proxy, do Reverb, do Horizon e do scheduler desta aplicação já estavam encerrados na revisão. A UAZAPI foi reconfigurada para o webhook anterior em `chait.test` e `GET /webhook` confirmou HTTP 200, `enabled: true` e esse host, sem imprimir o caminho secreto. O `.env` foi restaurado exatamente do backup anterior à sessão: tempo real desativado, broadcaster `log` e nenhum perfil local de Menu, Botão ou Chips. Os cinco fluxos de ensaio foram desativados; dois campos sintéticos usados só para chips foram removidos. O campo sintético da execução de Resposta foi preservado com o registro dessa execução.

Após a limpeza, `chat:metrics` mostrou zero entradas pendentes/falhadas e zero operações pendentes. Os 114 testes relacionados foram repetidos com 364 afirmações aprovadas. A simulação de `APP_ENV=production` ignorou as três variáveis de perfil local.
