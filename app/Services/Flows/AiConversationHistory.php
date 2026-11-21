<?php

namespace App\Services\Flows;

use App\Models\AiSession;
use App\Services\Chat\ChatProjection;
use App\Services\Chat\WhatsAppJid;
use App\Services\Contacts\ContactReset;
use App\Services\Uazapi\UazapiClient;
use RuntimeException;

class AiConversationHistory
{
    private const MAX_MESSAGES = 50;

    private const MAX_CHARACTERS = 12000;

    public function __construct(private UazapiClient $uazapi, private ChatProjection $projection, private ContactReset $resets, private WhatsAppJid $jids) {}

    public function input(AiSession $session, ?string $includedMessageId, AiTurnBudget $budget): ?array
    {
        $execution = $session->execution;
        $thread = $execution->thread;
        $connection = $execution->connection;
        if (! $thread || ! $connection || $thread->id !== $session->thread_id || $thread->contact_id !== $session->contact_id
            || $thread->workspace_id !== $session->workspace_id || $connection->workspace_id !== $session->workspace_id
            || $thread->uazapi_connection_id !== $connection->id || $connection->id !== $session->uazapi_connection_id
            || ! $this->jids->isIndividual($thread->external_chat_id)) {
            return null;
        }

        $chatId = $thread->external_chat_id;
        try {
            $result = $this->uazapi->messages($connection, $chatId, budget: $budget, interactive: false, timeout: 5);
        } catch (AiTurnLimitException $exception) {
            throw $exception;
        } catch (RuntimeException) {
            return null;
        }
        $messages = $result['messages'] ?? $result['data'] ?? $result['items'] ?? $result;
        if (! is_array($messages) || ! array_is_list($messages)) {
            return null;
        }

        $before = $this->projection->timestamp($execution->context['ai_history_before'] ?? null) ?? $session->created_at;
        $oldest = $before->copy()->subDays(7);
        $reset = $this->resets->cutoff($session->workspace_id, $chatId);
        $eligible = [];
        foreach ($messages as $message) {
            if (! is_array($message) || ($message['chatid'] ?? data_get($message, 'key.remoteJid')) !== $chatId || ! empty($message['isGroup'])) {
                continue;
            }
            $time = $this->projection->timestamp($message['messageTimestamp'] ?? $message['timestamp'] ?? null);
            if (! $time || $time->gt($before) || $time->lt($oldest) || ($reset && $time->lte($reset))) {
                continue;
            }
            $ids = array_values(array_filter([$message['messageid'] ?? null, data_get($message, 'key.id'), $message['id'] ?? null],
                fn ($id) => is_string($id) && $id !== ''));
            if ($ids === [] || in_array($includedMessageId, $ids, true)) {
                continue;
            }
            $line = $this->line($message);
            if ($line !== null) {
                $eligible[] = ['time' => $time, 'ids' => $ids, 'line' => $line];
            }
        }

        return $this->transcript($eligible);
    }

    private function line(array $message): ?string
    {
        $type = $message['messageType'] ?? $message['type'] ?? null;
        $fromMe = filter_var($message['fromMe'] ?? $message['from_me'] ?? data_get($message, 'key.fromMe'), FILTER_VALIDATE_BOOL);
        $status = $message['status'] ?? null;
        if (! is_string($type) || ! in_array(strtolower($type), ['text', 'conversation', 'extendedtextmessage', 'extended_text_message'], true)
            || ($fromMe && is_string($status) && in_array(strtolower($status), ['queued', 'pending', 'failed', 'canceled', 'cancelled', 'error'], true))) {
            return null;
        }
        $content = $message['content'] ?? null;
        if (is_string($content)) {
            $content = json_decode($content, true) ?? $content;
        }
        foreach ([$message['text'] ?? null, data_get($content, 'text'), data_get($content, 'conversation'), data_get($content, 'extendedTextMessage.text'), $content] as $value) {
            if (is_string($value) && ($text = trim(preg_replace('/\R+/u', ' ', $value) ?? '')) !== '') {
                return ($fromMe ? 'Empresa: ' : 'Lead: ').$text;
            }
        }

        return null;
    }

    private function transcript(array $messages): ?array
    {
        $heading = 'Histórico recente do WhatsApp. Use estas mensagens anteriores como contexto da conversa.';
        $remaining = self::MAX_CHARACTERS - mb_strlen($heading);
        usort($messages, fn (array $first, array $second) => $second['time'] <=> $first['time']);
        $lines = [];
        $seen = [];
        foreach ($messages as $message) {
            if (array_intersect($message['ids'], $seen) !== []) {
                continue;
            }
            $seen = [...$seen, ...$message['ids']];
            $length = mb_strlen($message['line']) + 1;
            if ($length > $remaining) {
                if ($lines === []) {
                    $lines[] = mb_substr($message['line'], 0, $remaining - 2).'…';
                }
                break;
            }
            $lines[] = $message['line'];
            $remaining -= $length;
            if (count($lines) === self::MAX_MESSAGES) {
                break;
            }
        }

        return $lines === [] ? null : ['type' => 'input_text', 'text' => $heading."\n".implode("\n", array_reverse($lines))];
    }
}
