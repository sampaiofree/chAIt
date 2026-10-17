# Homologação real do Assistente IA

Estes scripts são manuais e não entram na suíte automatizada. Usam a chave OpenAI do único workspace cadastrado e fazem chamadas reais que geram consumo nessa conta.

```bash
php tests/Manual/assistant-openai-smoke.php
php tests/Manual/assistant-openai-homologation.php
php tests/Manual/assistant-openai-inputs.php
php tests/Manual/flow-builder-guide.php 1
```

- **Smoke:** respostas dos três modelos configurados, descarte do contexto e relatório sem conteúdo da resposta.
- **Runtime:** executor e ferramentas reais, com banco SQLite em memória, filas/eventos de contexto isolados e dados fictícios. Usa a UAZAPI da conexão atual e envia quatro arquivos e mensagens de teste ao destinatário autorizado `5562995772922`. Recebimento de mensagens é substituído por um adaptador sintético; não testa webhooks reais de entrada. A saída humana abre a fila somente na base temporária.
- **Entradas:** solicitações OpenAI reais de imagem, PDF, DOCX e TXT já normalizados, além de transcrição de fala sintética. A geração de fala usa `say` com a voz Luciana no macOS e FFmpeg.
- **Guia do construtor:** usa a chave do workspace indicado (padrão `1`) somente para chamadas OpenAI com dados fictícios. Cria um schema exclusivo em `chait_test` usando `.env.testing`, simula briefing/aprovação/construção e verifica os caminhos suporte, compra, opção não reconhecida e prazo vencido. Não executa o fluxo, não publica e bloqueia HTTP fora da OpenAI; filas são simuladas. Remove o schema ao terminar e confere que os fluxos e prévias da base de origem permaneceram iguais. Relatório sem diálogos ou credenciais em `flow-builder-guide.json`.

Os arquivos de mídia de teste ficam em `artifacts/assistente-ia-homologacao/`. Relatórios nessa pasta contêm apenas resultados, identificadores operacionais e consumo; não contêm a chave nem os diálogos. Contextos remotos são limpos depois dos testes. O runtime não altera contatos, tags, campos, fluxos nem filas do workspace principal.

Reexecutar o runtime envia novas mensagens e arquivos na sessão nova. Relatórios usam nomes estáveis e são substituídos pela execução mais recente.
