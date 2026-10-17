<?php

namespace App\Services\Flows;

use App\Models\FlowExecution;
use App\Models\UazapiConnection;
use App\Models\Workspace;

class FlowContentButton
{
    public const HELP = 'O clique envia uma resposta do contato. Para aguardar essa resposta, utilize o bloco Aguardar Resposta. Para criar caminhos diferentes, utilize o bloco Menu.';

    public function profile(?UazapiConnection $connection, string $kind): ?array
    {
        $profile = config('flow-content-button.connections.'.($connection?->id ?? 0).'.'.$kind);
        if (! is_array($profile) || ($profile['verified'] ?? false) !== true) {
            return null;
        }
        foreach (['max_text_length', 'max_label_length', $kind === 'reply' ? 'max_reply_id_length' : 'max_value_length'] as $key) {
            if (! is_int($profile[$key] ?? null) || $profile[$key] < 1) {
                return null;
            }
        }

        return $profile;
    }

    public function catalog(?Workspace $workspace): array
    {
        $connection = $workspace ? UazapiConnection::where('workspace_id', $workspace->id)->first() : null;

        return ['max_per_content' => 1, 'kinds' => ['url', 'reply', 'copy'], 'reply_help' => self::HELP,
            'profiles' => collect(['url', 'reply', 'copy'])->mapWithKeys(fn ($kind) => [$kind => $this->profile($connection, $kind)])->all(),
            'matching' => 'IDs de resposta são derivados pelo servidor; não os configure. O rótulo da versão enviada é o texto recebido. Publicação e envio exigem homologação do tipo na conexão.'];
    }

    public function issues(array $items, Workspace $workspace, ?UazapiConnection $connection = null): array
    {
        $issues = [];
        $connection ??= UazapiConnection::where('workspace_id', $workspace->id)->first();
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
            $profile = $this->profile($connection, $kind);
            if (! $profile) {
                $issues[$prefix.'button.kind'] = 'Este tipo de botão aguarda homologação da conexão WhatsApp. Você pode salvar o rascunho.';

                continue;
            }
            if ($kind === 'reply' && $profile['max_reply_id_length'] < strlen('content:'.PHP_INT_MAX.':'.str_repeat('0', 32))) {
                $issues[$prefix.'button.kind'] = 'O limite homologado não comporta o identificador de resposta do sistema.';
            }
            foreach (['text' => [$item['text'] ?? '', 'max_text_length'], 'button.label' => [$button['label'] ?? '', 'max_label_length']] as $key => [$value, $limit]) {
                if (mb_strlen(is_array($value) ? '' : $value) > $profile[$limit]) {
                    $issues[$prefix.$key] = 'Texto acima do limite homologado para este tipo de botão.';
                }
            }
            if ($kind !== 'reply' && mb_strlen(is_array($button[$field] ?? null) ? '' : ($button[$field] ?? '')) > $profile['max_value_length']) {
                $issues[$prefix.'button.'.$field] = 'Destino acima do limite homologado para este tipo de botão.';
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
