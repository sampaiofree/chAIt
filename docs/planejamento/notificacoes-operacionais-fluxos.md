# Futuro: notificações operacionais dos fluxos

Fora desta entrega. O editor já consulta ocorrências seguras de `flow_events`; não envia alertas ao lead nem notificações internas.

Uma implementação futura deverá definir destinatários e permissões do workspace, canais, agrupamento e deduplicação de ocorrências, frequência, tratamento de resultado desconhecido e links para execução/bloco/item/campo. O conteúdo da notificação não deverá incluir mensagens renderizadas, dados pessoais dos contatos, credenciais ou payloads de provedores. Não interpretar resultado desconhecido como confirmação de falha ou autorizar reenvio automático.
