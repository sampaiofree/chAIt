<?php

namespace App\Services\Flows;

class FlowMenu
{
    public static function defaults(): array
    {
        return ['menu_format' => 'numeric', 'question' => '', 'options' => [], 'invalid_reply_text' => 'Opção inválida. Escolha uma das opções abaixo.', 'max_invalid_replies' => 3, 'timeout_seconds' => 300, 'list_button_text' => 'Ver opções'];
    }

    public function numericText(array $data): string
    {
        $lines = [(string) ($data['question'] ?? ''), ''];
        foreach ($data['options'] ?? [] as $index => $option) {
            $lines[] = ($index + 1).'. '.($option['label'] ?? '');
        }
        $lines[] = '';
        $lines[] = 'Responda somente com o número da opção.';

        return implode("\n", $lines);
    }

    public function issues(array $data): array
    {
        $issues = [];
        $format = $data['menu_format'] ?? null;
        if (! in_array($format, ['numeric', 'button', 'list'], true)) {
            $issues['menu_format'] = 'Escolha o formato do menu.';
        }
        foreach (['question' => 'Preencha a pergunta para o contato.', 'invalid_reply_text' => 'Preencha a mensagem para resposta inválida.'] as $field => $message) {
            if (trim((string) ($data[$field] ?? '')) === '') {
                $issues[$field] = $message;
            }
        }
        if (empty($data['options'])) {
            $issues['options'] = 'Adicione ao menos uma opção ao Menu.';
        }
        foreach ($data['options'] ?? [] as $index => $option) {
            if (trim((string) ($option['label'] ?? '')) === '') {
                $issues["options.{$index}.label"] = 'Preencha o rótulo da opção.';
            }
        }
        if (! is_int($data['timeout_seconds'] ?? null) || $data['timeout_seconds'] < 60 || $data['timeout_seconds'] > 604800) {
            $issues['timeout_seconds'] = 'Defina uma espera de 1 minuto a 7 dias.';
        }
        if (! is_int($data['max_invalid_replies'] ?? null) || $data['max_invalid_replies'] < 1 || $data['max_invalid_replies'] > 10) {
            $issues['max_invalid_replies'] = 'Defina um limite de 1 a 10 respostas inválidas.';
        }
        // The fallback must fit the existing application text-message limit too.
        if (mb_strlen($this->numericText($data)) > 4000) {
            $issues['options'] = 'A pergunta e as opções devem caber em uma mensagem de até 4.000 caracteres.';
        }
        if ($format === 'list' && trim((string) ($data['list_button_text'] ?? '')) === '') {
            $issues['list_button_text'] = 'Preencha o texto do botão de abertura.';
        }

        return $issues;
    }
}
