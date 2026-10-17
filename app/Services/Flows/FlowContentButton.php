<?php

namespace App\Services\Flows;

use App\Models\FlowExecution;
use App\Models\UazapiConnection;

class FlowContentButton
{
    public const HELP = 'O clique envia uma resposta do contato. Para aguardar essa resposta, utilize o bloco Aguardar Resposta. Para criar caminhos diferentes, utilize o bloco Menu.';

    public function catalog(): array
    {
        return ['max_per_content' => 1, 'kinds' => ['url', 'reply', 'copy'], 'reply_help' => self::HELP,
            'matching' => 'IDs de resposta são derivados pelo servidor; não os configure. O rótulo da versão enviada é o texto recebido.'];
    }

    public function issues(array $items): array
    {
        $issues = [];
        foreach ($items as $index => $item) {
            if (($item['kind'] ?? '') !== 'button') {
                continue;
            }
            $prefix = "items.{$index}.";
            $button = $item['button'] ?? [];
            $kind = $button['kind'] ?? '';
            foreach (['text' => $item['text'] ?? '', 'button.label' => $button['label'] ?? ''] as $field => $value) {
                if (trim(ContactTemplate::display($value)) === '') {
                    $issues[$prefix.$field] = $field === 'text' ? 'Preencha o texto da mensagem.' : 'Preencha o texto do botão.';
                }
            }
            if (! in_array($kind, ['url', 'reply', 'copy'], true)) {
                $issues[$prefix.'button.kind'] = 'Escolha URL, Resposta ou Copiar.';

                continue;
            }
            $field = $kind === 'url' ? 'url' : 'copy_text';
            if ($kind !== 'reply' && trim(ContactTemplate::display($button[$field] ?? '')) === '') {
                $issues[$prefix.'button.'.$field] = $kind === 'url' ? 'Preencha o endereço URL.' : 'Preencha o texto para copiar.';
            }

        }

        return $issues;
    }

    public function replyId(FlowExecution $execution, string $nodeId, string $itemId): string
    {
        // Stable for retries; unique to the execution and immutable version/item.
        $identity = $execution->flow_version_id.'|'.$nodeId.'|'.$itemId;

        return 'content:'.$execution->id.':'.substr(hash_hmac('sha256', $identity, (string) config('app.key')), 0, 32);
    }

    public function choice(FlowExecution $execution, string $nodeId, array $item): string
    {
        $button = $item['button'];
        $value = match ($button['kind']) {
            'url' => 'url:'.$button['url'],
            'copy' => 'copy:'.$button['copy_text'],
            'reply' => $this->replyId($execution, $nodeId, $item['id']),
        };

        return $button['label'].'|'.$value;
    }

    /** Resolve only an explicitly interactive reply to a transport reserved for this contact. */
    public function resolve(UazapiConnection $connection, int $contactId, array $message): ?array
    {
        $type = strtolower((string) ($message['messageType'] ?? $message['type'] ?? ''));
        $selection = $message['buttonOrListid'] ?? null;
        if ($type !== 'buttonsresponsemessage' || ! is_string($selection)
            || ! preg_match('/^content:([0-9]+):[a-f0-9]{32}$/D', $selection)
            || ($message['fromMe'] ?? $message['from_me'] ?? data_get($message, 'key.fromMe', false))
            || ($message['wasSentByApi'] ?? false) || ($message['isGroup'] ?? false)) {
            return null;
        }
        $id = explode(':', $selection)[1];
        $execution = FlowExecution::with('version')->where('workspace_id', $connection->workspace_id)
            ->where('uazapi_connection_id', $connection->id)->where('contact_id', $contactId)->find($id);
        $reference = $execution?->context['content_buttons'][$selection] ?? null;
        if (! $reference) {
            return null;
        }
        $node = collect($execution->version->graph['nodes'])->firstWhere('id', $reference['node_id']);
        $item = collect($node['data']['items'] ?? [])->firstWhere('id', $reference['item_id']);
        if (($item['kind'] ?? '') !== 'button' || ($item['button']['kind'] ?? '') !== 'reply'
            || ! hash_equals($this->replyId($execution, $reference['node_id'], $reference['item_id']), $selection)) {
            return null;
        }

        return ['text' => $item['button']['label'], 'execution_id' => $execution->id, 'selection' => $selection, ...$reference];
    }
}
