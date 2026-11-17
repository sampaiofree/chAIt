# Otimização de imagens dos fluxos — verificação local

Data: 17/11/2026. Aplicável somente aos novos uploads de JPEG/PNG estáticos no endpoint dos fluxos.

## Verificação automatizada

- 202 testes aprovados, 1.008 asserções, em `FlowImageUploadTest`, `FlowEditorTest`, `FlowSimplifiedTest`, `FlowTransportTest`, `AiBlockTest` e `ChatRuntimeTest`.
- 24 casos novos cobrem proporções, ausência de ampliação, transparência, orientação EXIF, remoção de metadados, preservação do original menor, GIF/WebP/APNG, limites, autorização, restauração dos recursos do Imagick, limpeza após falhas e preservação dos arquivos existentes.
- Transporte de JPEG e PNG resultantes exercitado por `ProcessChatOperation`, com UAZAPI simulada e comparação exata de MIME e bytes Base64 enviados.
- Pint dos arquivos PHP alterados, `composer validate --strict` e `git diff --check` aprovados.
- Testes de banco executados apenas no PostgreSQL isolado `chait_test`.

## Comparação de amostras existentes

O serviço foi executado diretamente sobre as amostras, sem alterar os arquivos originais. Saídas temporárias ficaram fora do repositório.

| Amostra | Original (bytes) | Armazenável (bytes) | Redução | Formato final |
| --- | ---: | ---: | ---: | --- |
| `public/brand/logo.png` | 710.407 | 387.887 | 45,4% | PNG |
| `artifacts/flow-transport-import-1440.png` | 52.345 | 31.051 | 40,7% | JPEG |
| Cabeçalho `medicao-espessura-image.png` da documentação do Vistoria | 15.353 | 14.541 | 5,3% | JPEG |

Captura de tela e cabeçalho resultantes inspecionados visualmente: textos legíveis nas dimensões originais dessas amostras. Fotografias JPEG de alta qualidade foram exercitadas com imagens sintéticas nos testes; não foi feita avaliação visual de uma coleção de fotografias reais. Os percentuais acima são específicos das amostras, sem promessa de economia mínima.

## Operação e limite da validação

Não houve reprocessamento do acervo, alteração de dados da aplicação ou envio real pelo WhatsApp. A homologação de entrega e aparência no WhatsApp da instância contratada permanece pendente, com JPEG e PNG gerados e destinatário de teste expressamente autorizado.

Antes do deploy, disponibilizar Imagick com codecs JPEG/PNG no PHP CLI e PHP-FPM. Não há migration nem worker novo. Para reverter o comportamento, reverter o código; arquivos JPEG/PNG já otimizados continuam compatíveis com o caminho anterior de envio.
