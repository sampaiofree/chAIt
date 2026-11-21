<?php

namespace App\Services\Flows;

use App\Models\AiSession;
use App\Models\UazapiInbox;
use Illuminate\Support\Facades\DB;

/** Builds the opening input without placing lead content in administrator instructions. */
class AiInitialContext
{
    public function release(AiSession $session): void
    {
        DB::transaction(function () use ($session): void {
            $execution = $session->execution()->lockForUpdate()->first();
            $context = $execution?->context ?? [];
            if (($context['ai_initial_session_id'] ?? null) !== $session->id || ! ($context['ai_initial_reserved'] ?? false)) {
                return;
            }
            $context['ai_initial_reserved'] = false;
            $execution->update(['context' => $context]);
            UazapiInbox::find($context['ai_initial_message']['inbox_id'])?->consumed();
        });
    }

    public function input(AiSession $session, mixed $template, string $key, AiTurnBudget $budget, ?string &$includedMessageId = null): ?array
    {
        if (trim(ContactTemplate::display($template)) === '') {
            return null;
        }
        $templates = app(ContactTemplate::class);
        $snapshot = $templates->snapshot($session->workspace_id, $session->contact_id);
        $parts = [];
        $message = null;
        foreach (ContactTemplate::parts($template) as $part) {
            if ($part['type'] === 'runtime') {
                $ref = $session->execution->context['ai_initial_message'] ?? null;
                if (! $ref || empty($ref['message_id'])) {
                    return null;
                }
                if ($message === null) {
                    $inbox = isset($ref['inbox_id']) ? UazapiInbox::find($ref['inbox_id']) : null;
                    try {
                        $message = app(AiInboundMessage::class)->input($session, $ref['message_id'], $key, $budget,
                            $inbox && $inbox->expires_at->isFuture() ? $inbox->payload : null);
                    } catch (UnsupportedAiMediaException $error) {
                        if (str_contains($error->getMessage(), 'Mensagem indisponível') || str_contains($error->getMessage(), 'Mensagem não encontrada')) {
                            return null;
                        }

                        return [['type' => 'input_text', 'text' => 'A última mensagem está indisponível ou sua mídia não pôde ser analisada. Peça ao lead que envie sua dúvida em texto, imagem, áudio ou PDF, DOCX e TXT de até 10 MB.']];
                    }
                    if ($message['video']) {
                        return [['type' => 'input_text', 'text' => 'O lead enviou um vídeo, formato não suportado. Peça que envie texto, áudio, imagem ou documento.']];
                    }
                }
                if ($message['content'] !== []) {
                    $includedMessageId = $ref['message_id'];
                    $parts = [...$parts, ...$message['content']];
                }
            } else {
                $text = $templates->render(['parts' => [$part]], $snapshot);
                if ($text !== '') {
                    $parts[] = ['type' => 'input_text', 'text' => $text];
                }
            }
        }

        return $parts ?: null;
    }
}
