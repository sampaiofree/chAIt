# Integração WhatsApp — UAZAPI

> Revisão documental em 16/10/2026, confrontada com o código; não representa nova homologação operacional.

> **Versão:** 0.6  
> **Consulta documental:** 2026-10-12  
> **Estado:** integração implementada, com caminho de tempo real sob configuração e homologações reais parciais registradas por recurso. Não equivale à certificação de todos os cenários no servidor contratado.

> **Revisão de 16/10/2026:** o novo modo de chat, protegido por configuração, usa lista local, prévia de 200 caracteres, caixa de entrada temporária criptografada e Laravel Reverb. As exceções de persistência aprovadas e a ativação estão no [contrato operacional de tempo real](../operacao/chat-realtime.md); essa revisão substitui as proibições anteriores de prévia e conteúdo temporário de processamento, mantendo o histórico completo no provedor.

## Direção aprovada

- UAZAPI é o provedor inicial do WhatsApp.
- Cada workspace terá um número de WhatsApp na primeira versão.
- Laravel intermedeia chamadas e protege as credenciais; o frontend nunca recebe os tokens.
- Chat e multiatendimento são integrados ao editor e ao executor de fluxos.
- O administrador inicia uma instância por workspace com QR Code. A credencial administrativa é configurada no ambiente; o token de instância é criptografado no banco.

## Fluxo implementado e modos de operação

1. Administradores iniciam/gerenciam a instância por workspace e o QR Code; credencial administrativa fica no ambiente e token da instância é criptografado no banco, nunca entregue ao navegador.
2. Laravel autentica webhooks por conexão/segredo e recebe eventos de conexão, chats e mensagens.
3. Com `CHAIT_REALTIME_ENABLED=false` (padrão do código), o chat mantém o caminho anterior de consultas/sincronização com a UAZAPI e atualizações periódicas.
4. Com `CHAIT_REALTIME_ENABLED=true`, a lista usa projeção local, importação progressiva e Reverb. Webhooks são aceitos em caixa de entrada criptografada; operações assíncronas retornam `202` com `data.operation_id`, e seus resultados chegam por eventos autorizados. Histórico é consultado ao abrir a conversa.
5. O novo caminho permite prévia local de até 200 caracteres e conteúdo temporário necessário ao processamento. Payloads são apagados após os consumidores concluírem e expiram em até 24 horas em falha/pendência; mídias temporárias ficam no disco privado, sem base64 persistido. Jobs usam IDs, e não corpos; isso não cria histórico completo local.

Configure `UAZAPI_BASE_URL` e `UAZAPI_ADMIN_TOKEN`. Recebimento real de webhooks exige URL pública HTTPS. Infraestrutura, limites, limpeza e reversão do novo caminho estão em [Chat em tempo real](../operacao/chat-realtime.md). Os contratos síncronos de voz/leitura abaixo descrevem o caminho anterior; no novo caminho são executados por operações assíncronas.

## Fontes oficiais

- [Documentação principal](https://docs.uazapi.com/).
- [Índice para agentes](https://docs.uazapi.com/llms.txt), identificado como API 2.6.4 na consulta; isso não determina a versão contratada.
- [Contrato OpenAPI](https://docs.uazapi.com/openapi-bundled.json).
- [Instâncias e sessões](https://docs.uazapi.com/docs/instances).
- [Webhooks](https://docs.uazapi.com/docs/integrations-webhooks).
- [Erros e confiabilidade](https://docs.uazapi.com/docs/errors-and-retries).
- [Histórico e retenção](https://docs.uazapi.com/docs/collections/message-history-retention.md).
- [Consulta de mensagens](https://docs.uazapi.com/reference/findMessages.md).
- [Consulta de chats](https://docs.uazapi.com/reference/findChats.md).

## Capacidades documentadas, sem teste no servidor contratado

| Recurso                                                              | Referência                                                 | Validação necessária                                                                                                                           |
| -------------------------------------------------------------------- | ---------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------- |
| Sessão WhatsApp por instância                                        | Guia de instâncias                                         | Criação, pareamento, status e reconexão implementados; registros posteriores usam conexão real, sem certificar reconexão em todos os cenários. |
| Listagem de chats com filtros e paginação                            | Consulta de chats                                          | Dados disponíveis e composição com permissões e atribuições locais.                                                                            |
| Consulta de mensagens por chat, com paginação                        | Consulta de mensagens                                      | Identificadores, ordenação, atualização e mídias.                                                                                              |
| Recuperação de histórico anterior                                    | Guia de histórico                                          | Dependência do celular, lotes, timeout e indisponibilidade.                                                                                    |
| Eventos de conexão, mensagens, entrega/leitura, histórico e presença | Guia de webhooks                                           | Payloads efetivos, correlação, duplicidade e ordem.                                                                                            |
| Avatar do chat                                                       | `/chat/avatar` retorna URL de prévia ou URL vazia          | Validar retorno e validade das URLs no servidor contratado; cache da aplicação de 15 minutos e iniciais como fallback.                         |
| Renovação de mídia                                                   | `/message/download` gera URL pública para o ID da mensagem | Validar IDs e expiração das imagens enviadas e recebidas na versão contratada.                                                                 |
| Conciliação do envio                                                 | `track_id` em `/send/media` e filtro em `/message/find`    | Uso de rastreamento confirmado nas homologações parciais abaixo; timeout mantém confirmação pendente, sem reenvio automático.                  |

## Execução dos blocos Conteúdo

O runtime usa `/send/text` para textos e `/send/media` para imagens, vídeos MP4, áudio e documentos. Os uploads configurados no editor ficam no disco privado local e o grafo publicado referencia os IDs dos ativos; o runtime converte o arquivo para Data URL apenas durante a chamada ao provedor. Imagens aceitas: JPEG, PNG, WebP e GIF; áudio: MP3 e OGG; documentos: PDF, DOC/DOCX, XLS/XLSX e texto. A UAZAPI documenta URLs ou base64 em `file` e os tipos `image`, `video`, `audio` e `document`. [Contrato oficial](https://docs.uazapi.com/reference/sendMedia.md).

Cada envio do fluxo recebe um `track_id`. Quando a resposta indicar fila/pendência ou a conexão ficar incerta, o runtime consulta `/message/find` antes de avançar; não repete automaticamente a chamada de envio. Os corpos recebidos são usados para selecionar gatilhos e capturar texto em `Aguardar Resposta`. No modo de tempo real, a caixa de entrada temporária é criptografada e limitada conforme o contrato acima. Não vão como corpos de jobs nem para logs, e não formam histórico completo no SaaS. Texto capturado em `Aguardar Resposta` fica no campo personalizado escolhido do contato.

O processamento usa Redis + Horizon. Configure `QUEUE_CONNECTION=redis`, Cache Redis compatível com locks e execute `php artisan horizon` nos ambientes que processam fluxos. O Horizon exige Redis. [Documentação Laravel](https://laravel.com/framework/docs/horizon).

Recursos do provedor não aprovam automaticamente sua inclusão na interface. Status, responsáveis e notas internas do SaaS seguem os contratos do [multiatendimento](../modulos/chat-multiatendimento.md).

## Histórico: limitação que afeta o produto

A documentação informa retenção local de **até sete dias**, possivelmente menor com alto volume. A recuperação de mensagens anteriores depende do aparelho principal e não tem retorno garantido. Solicitar histórico não aumenta permanentemente a janela de retenção. [Fonte oficial](https://docs.uazapi.com/docs/collections/message-history-retention.md).

**Implicação para o briefing:** manter o histórico exclusivamente no provedor limita a consulta de conversas antigas. Essa é uma conclusão para o produto a partir da regra documentada, não um teste de disponibilidade do servidor contratado.

**Regra de 12/10/2026, revisada em 16/10/2026:** manter o histórico completo no provedor e aceitar sua retenção limitada; permitir prévia local e conteúdo temporário criptografado no modo de tempo real conforme a decisão B-06 no [registro de decisões](../planejamento/decisoes.md). Histórico operacional de atendimento e notas internas são dados distintos e permanecem no SaaS conforme suas regras. A interface não deve prometer acesso permanente ou completo às mensagens antigas.

## Pontos de integração a validar

- Confirmar versão, Server URL, instância, autenticação e contrato aplicáveis ao servidor contratado.
- Validar vínculo entre instância e workspace no backend; proteger tokens e evitar logs do payload completo. [Webhooks](https://docs.uazapi.com/docs/integrations-webhooks).
- Confirmar deduplicação e reconciliação por evento; a documentação não define um identificador universal para todos os webhooks. [Webhooks](https://docs.uazapi.com/docs/integrations-webhooks).
- Evitar repetir envios após timeout sem consultar o estado, pois o primeiro envio pode ter sido processado. [Confiabilidade](https://docs.uazapi.com/docs/errors-and-retries).
- Definir processamento assíncrono compatível com a política de persistência; não colocar corpos de mensagens em filas duráveis ou logs por suposição.
- Validar histórico, reconexão, expiração de mídia, limites e estados de indisponibilidade antes do aceite.
- Confirmar os campos efetivos do perfil (`profileName`, `profilePicUrl`, última desconexão e QR), além dos estados durante pareamento e falha de rede.

A primeira implementação registrou build/migrations sem conexão real. Posteriormente houve homologações parciais de mídias, IA, Menu e Sequências descritas abaixo. Esses registros datados não comprovam o estado atual de uma instância nem a homologação completa do novo caminho de tempo real.

## Mensagens de voz — implementação de 12/10/2026

**Estado:** implementado com testes locais de conversão, envio simulado, isolamento e falhas. O teste de envio real de áudio no servidor contratado e a matriz de dispositivos continuam pendentes. Referência pública consultada: OpenAPI **2.6.4**, sem presumir que essa seja a versão contratada.

### Contrato da aplicação

A rota existente `POST /workspaces/{workspace}/conversations/{thread}/send` recebe multipart:

| Campo             | Regra para voz                                                                                                               |
| ----------------- | ---------------------------------------------------------------------------------------------------------------------------- |
| `media_type`      | `voice`; outros valores são rejeitados. Ausente mantém anexos/texto existentes.                                              |
| `file`            | Obrigatório; WebM, OGG ou MP4/AAC; até 10 MiB (10.240 KiB). MIME é verificado pelo backend e contêiner/trilhas pelo FFprobe. |
| `track_id`        | UUID obrigatório gerado pelo navegador por tentativa; identificador de correlação.                                           |
| `text`, `caption` | Proibidos para voz; rascunho permanece no navegador.                                                                         |

Autorização de workspace, atribuição e conexão precede a conversão e é revalidada antes do envio após o processamento. Administrador mantém a regra atual de abertura/atribuição ao responder; gravações inválidas não abrem atendimento.

`VoiceAudioConverter` inspeciona o upload com FFprobe (5 s), rejeita vídeo e converte com FFmpeg (15 s) para OGG/Opus mono, 48 kHz, 32 kbps. FFprobe inspeciona também a saída (5 s). WebM de navegador pode omitir duração: nesse caso a conversão decodifica no máximo 121 segundos e rejeita a saída acima de 120, sem truncar silenciosamente uma mensagem longa. As durações inspecionadas são arredondadas a décimos de segundo para compensar padding do codec; mínimo de um segundo.

Arquivos ficam em diretório aleatório privado com permissão 0700; upload e saída são apagados em `finally`, inclusive em falhas. No caminho anterior, os arquivos de conversão são apagados ao final da requisição. No modo de tempo real, o upload necessário à operação pode permanecer no disco privado até conclusão ou expiração, no máximo 24 horas. Áudio/base64 não vai para banco, corpos de jobs, logs ou disco público. Exceções de processo e detalhes do provedor são convertidos em mensagens seguras, sem expor conteúdo ou credenciais.

### Envio e conciliação

Laravel chama `POST /send/media` com header `token`, `number` da conversa, `type=ptt`, `file` como Data URL/base64 OGG, `mimetype=audio/ogg` e `track_id`. Não envia legenda, nome de documento, visualização única ou opções de transcrição. [Contrato oficial](https://docs.uazapi.com/openapi-bundled.json).

A resposta da aplicação mantém `data.sent`, `data.status`, `data.message` e `data.track_id`. Rejeições HTTP explícitas e status Failed/Canceled retornam `failed`; timeout, transporte, HTTP 409/5xx, resposta sem identificador ou status Queued/Pending retornam `202` com `confirmation_pending`. No caminho anterior, o frontend concilia por ID/`track_id` nas consultas periódicas e não repete o envio. No modo de tempo real, o resultado da operação é recebido por evento e a confirmação pendente não autoriza reenvio automático. `track_id` não é chave de idempotência. [Erros e retries](https://docs.uazapi.com/docs/errors-and-retries).

### Histórico e reprodução

Mensagens transitórias incluem `is_audio`, `is_voice`, `mime_type` e `duration_seconds` (os últimos opcionais), normalizando `audio`, `audioMessage`, `ptt`, `myaudio` e conteúdo `audioMessage`/`audio` ou campos diretos. A aplicação não transmite o conteúdo bruto do provedor ao frontend.

O endpoint existente de mídia verifica ID e vínculo com a conversa antes de chamar `/message/download`, com `generate_mp3=true`, `return_base64=false`, `transcribe=false`. O player usa a URL pública `fileURL`, obtida sob demanda para áudio. Falha de reprodução renova uma vez; ausência da mídia não altera o estado de envio. URLs de CDN são temporárias e nova recuperação depende da disponibilidade no provedor. [Contrato de download](https://docs.uazapi.com/openapi-bundled.json).

### Leitura compartilhada

O backend chama `POST /chat/read` com header `token`, `number` igual ao identificador da conversa autorizada e `read=true`. A documentação informa que isso zera as não lidas e remove o indicador do chat; a confirmação de leitura ao remetente depende das configurações do WhatsApp. No caminho anterior, a lista é sincronizada pelo contador retornado em `/chat/find`; no modo de tempo real, a projeção local e os eventos atualizam os contadores. [Contrato oficial](https://docs.uazapi.com/openapi-bundled.json).

A leitura compartilhada persiste `unread_count` e `unread_version`. Isso não exclui a prévia e o conteúdo temporário permitidos no modo de tempo real; ver o contrato inicial deste documento. O histórico retorna a versão observada antes da consulta. A rota de leitura exige essa versão e só zera o contador se ela continuar atual após a confirmação UAZAPI. Se uma mensagem entrar durante a operação, o contador local permanece, o backend tenta restaurar o estado não lido no provedor com `read=false`, e a interface recebe conflito para atualizar o histórico.

### Operação e verificação

- Instalar FFmpeg com encoder `libopus` e FFprobe no mesmo ambiente/PATH do PHP. Configurar `VOICE_FFMPEG_PATH` e `VOICE_FFPROBE_PATH` quando forem necessários caminhos absolutos (por exemplo, PHP executado pelo Herd).
- Caminhos absolutos dos binários foram configurados no `.env` local para que o PHP do Herd não dependa do PATH do terminal; `.env.example` mantém defaults portáveis.
- Disponibilizar contexto seguro HTTPS ao navegador. Ajustar proxy/PHP para requisições de pelo menos 60 segundos: processamento pode usar 25 s e chamada UAZAPI mais 25 s. Não usar retries automáticos de POST.
- Upload/post no PHP e proxy devem acomodar 10 MiB multipart; preservar também o limite existente de anexos de 20 MiB. No CLI local, os limites encontrados são 25M/30M; isso não comprova a configuração do PHP web/servidor de produção.
- Verificar binários com `ffmpeg -encoders` (libopus) e `ffprobe -version`. Testes de backend exigem ambos e AAC para o caso de MP4; `npm run test:voice` exige Node >=22.13 com suporte a strip-types.
- Executar testes de chat/voz, testes de frontend, TypeScript, lint e build. Testes reais geram tons sintéticos temporários, sem mensagens do usuário.
- Aceite operacional: gravar no navegador, ouvir a prévia, enviar para destinatário de teste autorizado, verificar formato de voz no WhatsApp e reproduzir no chat. Nenhum envio real de áudio foi realizado nesta implementação.

**Verificação local:** 101 testes PHP (38 de voz) e 10 testes de frontend aprovados. Conversão real de WebM/OGG/MP4, limites de um segundo/dois minutos, ausência de vídeo, exclusão de temporários em falha/timeout, revalidação de atribuição/permissão/conexão e download autorizado cobertos por testes de backend. Frontend cobre ciclo do microfone e formatos simulados; botão/layout inspecionados no Chrome desktop e viewport de 390 × 844. TypeScript, ESLint, build e formatação dos arquivos alterados verificados. A checagem global Prettier aponta pendências em 13 arquivos fora desta alteração; isso não foi corrigido como parte do áudio. A CI instala FFmpeg e executa os testes PHP e de gravação. Safari/Firefox/Edge e celulares reais continuam no checklist de aceite.

## Histórico de versões

- **v0.6 — 2026-10-12:** leitura compartilhada via `/chat/read`, versão concorrente e sincronização do contador autoritativo documentadas e implementadas; servidor contratado pendente de validação.

- **v0.5 — 2026-10-12:** gravação e conversão temporária, envio `ptt`, player MP3, classificação de rejeições e testes locais; aceite operacional de áudio pendente.

- **v0.4 — 2026-10-12:** documentados página administrativa, falha do provedor, avatares, renovação de mídia e conciliação por `track_id`; teste operacional pendente.

- **v0.3 — 2026-10-12:** implementados cliente backend, conexão QR, webhook sem payload persistido, sincronização de metadados e proxy do chat; teste operacional do servidor continua pendente.
- **v0.2 — 2026-10-12:** confirmada manutenção do histórico exclusivamente na UAZAPI, com aceitação explícita da retenção limitada.
- **v0.1 — 2026-10-12:** provedor confirmado, fontes oficiais registradas e retenção limitada identificada como dependência de decisão do produto.

## Homologação de mídias do Assistente IA — 14/10/2026

Imagem PNG, vídeo MP4, áudio MP3 e documento TXT foram enviados sem legenda ao destinatário de teste indicado pelo usuário, usando a conexão existente. Os quatro foram confirmados como `Delivered` por rastreamento, sem reenvio. Evidência: `artifacts/assistente-ia-homologacao/resultado.json`.

A busca por `track_id` deve dispensar filtro por número na conciliação de mídia: o WhatsApp pode normalizar o número brasileiro e causar ausência de resultado com o número original. A [documentação oficial de busca](https://docs.uazapi.com/endpoint/post/message~find) permite consulta por rastreamento. O backend usa um identificador próprio por sessão/arquivo e mantém resultado incerto caso a consulta falhe; não repete o envio.

O teste inicial cobre transporte dos quatro tipos na instância contratada. Após cadastro da chave OpenAI, a homologação integrada do Assistente IA também enviou quatro mídias, todas confirmadas como Delivered, com alterações de CRM isoladas em memória. Entradas do diálogo foram sintéticas; limites máximos e todos os formatos não foram exercitados. Ver `openai-runtime.json` na mesma pasta de evidências.

## Confirmação de envios de Sequências — 14/10/2026

Homologação real confirmou que a UAZAPI pode normalizar o número brasileiro e retornar outro `chatid`. Ao consultar por `track_id` único, a conexão é suficiente: não combinar o filtro com o chat original. O executor persiste a intenção antes do transporte, consulta envios pendentes sem reenvio cego e registra somente metadados de confirmação. Duas etapas reais de Sequências foram concluídas, com intervalo após o primeiro término.

## Documentos relacionados preservados

Consulte também a [consulta documental sobre menus interativos](../historico/uazapi-menu-v0.3.md). A organização dos arquivos não consolida automaticamente diferenças de conteúdo entre as versões.

## Menu — implementação local de 14/10/2026

Numérico usa `/send/text`; Botões/Lista usam `/send/menu` com IDs por espera em `choices` e seleção explícita em `buttonOrListid`. Intenção precede transporte; conciliação a cada 5 segundos, até 24 consultas, sem reenvio em resultado incerto. Rejeição comprovada pode mudar para Numérico após confirmar seu envio. Erros de autorização/rate limit não acionam fallback.

O contrato público não comprova cliques ou limites na instância real. Formatos interativos permanecem bloqueados para publicação; habilitação e limites são por conexão em `config/flow-menu.php`. Homologação e estados em [Bloco Menu](../modulos/bloco-menu.md). Dois menus reais foram confirmados como Delivered e funcionaram no iPhone; IDs de seleção foram encontrados em /message/find. O webhook aponta para chait.test e não há URL pública, portanto a validação de eventos reais e a liberação interativa continuam pendentes. Evidência em artifacts/menu-homologacao/resultado.json.

## Conteúdo com Botão — implementação local de 15/10/2026

O elemento envia mensagem e uma escolha por `/send/menu`, `type: button`: `rótulo|url:destino`, `rótulo|copy:valor` ou resposta com identificador operacional gerado pelo servidor. Usa intenção persistida, `track_id` e conciliação sem reenvio em resultado incerto; não faz fallback para texto. Não presume eventos de clique URL/Copiar. Resposta explícita é normalizada pelo rótulo da versão enviada e segue o pipeline existente, incluindo Aguardar Resposta e IA de atendimento.

Recibo e efeito de entrada são transacionais; o buffer de clique antecipado guarda somente referências no contexto da execução. Os perfis por tipo/conexão em `config/flow-content-button.php` começam vazios, independentes dos perfis do Menu. Esta entrega teve somente testes locais, sem envios ou homologação reais. [Contrato e procedimento de liberação](../modulos/bloco-conteudo-elemento-botao.md).

## Campos do contato e substituições nativas — 15/10/2026

Chips são resolvidos internamente no Laravel, nunca convertidos deliberadamente para variáveis nativas UAZAPI. O OpenAPI documenta substituições próprias e não oferece escape/desativação comprovados. Texto literal e valores também podem conter padrões nativos. A comparação entre conteúdo preparado e recebido continua pendente; mocks não certificam esse caminho. `flow-contact-template.connections` começa vazio e impede publicação/novas preparações com chips até homologação da conexão. Nenhum padrão é bloqueado automaticamente e não são usados caracteres invisíveis. Ver [regras de liberação e evidências](../modulos/campos-do-contato-fluxos.md). Conteúdo estático ou personalizado passa a continuar após falha confirmada, registrando ocorrência; resultado incerto é conciliado e nunca reenviado.
