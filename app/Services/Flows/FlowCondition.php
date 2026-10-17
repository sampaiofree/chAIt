<?php

namespace App\Services\Flows;

use App\Models\Contact;
use App\Models\Workspace;
use Illuminate\Validation\ValidationException;
use Normalizer;

class FlowCondition
{
    public const STANDARD_FIELDS = [
        ['key' => 'display_name', 'label' => 'Nome'],
        ['key' => 'email', 'label' => 'E-mail'],
        ['key' => 'cpf', 'label' => 'CPF'],
    ];

    public function normalize(array $data): array
    {
        if (! array_key_exists('checks', $data) && array_key_exists('tag_ids', $data)) {
            $data['checks'] = is_array($data['tag_ids'])
                ? array_map(fn ($id) => ['kind' => 'tag', 'tag_id' => $id, 'operator' => 'has'], $data['tag_ids'])
                : $data['tag_ids'];
        }
        unset($data['tag_ids']);

        return $data;
    }

    /** Keys are relative to node data, shared by publication and assistant diagnostics. */
    public function issues(array $data, Workspace $workspace): array
    {
        $data = $this->normalize($data);
        $issues = [];
        if (! in_array($data['condition_operator'] ?? null, ['all', 'any'], true)) {
            $issues['condition_operator'] = 'Escolha como combinar as verificações.';
        }
        $checks = $data['checks'] ?? [];
        if (! is_array($checks) || ! array_is_list($checks) || count($checks) < 1 || count($checks) > 50) {
            $issues['checks'] = 'Adicione de 1 a 50 verificações.';

            return $issues;
        }
        $tags = $workspace->tags()->pluck('id')->all();
        $fields = $workspace->contactCustomFields()->where('type', 'text')->pluck('id')->all();
        foreach ($checks as $index => $check) {
            $path = "checks.$index";
            if (! is_array($check) || array_diff(array_keys($check), ['kind', 'tag_id', 'field_id', 'field_key', 'operator', 'value'])) {
                $issues[$path] = 'Verificação inválida.';

                continue;
            }
            if (($check['kind'] ?? null) === 'tag') {
                if (! is_int($check['tag_id'] ?? null) || ! in_array($check['tag_id'], $tags, true)) {
                    $issues["$path.tag_id"] = 'Selecione uma etiqueta existente neste workspace.';
                }
                if (! in_array($check['operator'] ?? null, ['has', 'not_has'], true)) {
                    $issues["$path.operator"] = 'Escolha Possui ou Não possui.';
                }
                if (isset($check['field_id']) || isset($check['field_key']) || isset($check['value'])) {
                    $issues[$path] = 'Uma verificação de etiqueta não aceita campo ou expressão.';
                }
            } elseif (($check['kind'] ?? null) === 'field') {
                $custom = isset($check['field_id']);
                $standard = isset($check['field_key']);
                if ($custom === $standard || isset($check['tag_id'])) {
                    $issues["$path.field_key"] = 'Selecione exatamente um campo do contato.';
                } elseif ($custom && (! is_int($check['field_id']) || ! in_array($check['field_id'], $fields, true))) {
                    $issues["$path.field_id"] = 'Selecione um campo de texto existente neste workspace.';
                } elseif ($standard && ! in_array($check['field_key'], array_column(self::STANDARD_FIELDS, 'key'), true)) {
                    $issues["$path.field_key"] = 'Selecione Nome, E-mail ou CPF.';
                }
                if (! in_array($check['operator'] ?? null, ['equals', 'contains'], true)) {
                    $issues["$path.operator"] = 'Escolha É igual a ou Contém.';
                }
                if (! is_string($check['value'] ?? null) || $this->trim($check['value']) === '' || mb_strlen($check['value']) > 4000) {
                    $issues["$path.value"] = 'Preencha a expressão com até 4.000 caracteres.';
                }
            } else {
                $issues["$path.kind"] = 'Escolha Etiqueta ou Campo do contato.';
            }
        }

        return $issues;
    }

    public function evaluate(array $data, Workspace $workspace, int $contactId): bool
    {
        $data = $this->normalize($data);
        $issues = $this->issues($data, $workspace);
        if ($issues !== []) {
            throw ValidationException::withMessages($issues);
        }
        // Reload at the step: operations earlier in this job may have changed the contact.
        $contact = Contact::where('workspace_id', $workspace->id)->findOrFail($contactId);
        $tags = $contact->tags()->where('tags.workspace_id', $workspace->id)->wherePivot('workspace_id', $workspace->id)->pluck('tags.id')->all();
        $values = $contact->customFieldValues()->where('workspace_id', $workspace->id)->pluck('value', 'field_id');
        $results = [];
        foreach ($data['checks'] as $check) {
            if ($check['kind'] === 'tag') {
                $has = in_array($check['tag_id'], $tags, true);
                $results[] = $check['operator'] === 'has' ? $has : ! $has;
            } else {
                $value = isset($check['field_id']) ? $values->get($check['field_id']) : $contact->getAttribute($check['field_key']);
                $text = $this->text($value);
                $needle = $this->text($check['value']);
                $results[] = $text !== '' && ($check['operator'] === 'equals' ? $text === $needle : str_contains($text, $needle));
            }
        }

        return $data['condition_operator'] === 'all' ? ! in_array(false, $results, true) : in_array(true, $results, true);
    }

    private function trim(string $value): string
    {
        return preg_replace('/^[\s\p{Z}]+|[\s\p{Z}]+$/u', '', $value) ?? '';
    }

    private function text(mixed $value): string
    {
        $text = Normalizer::normalize($this->trim((string) $value), Normalizer::FORM_D);

        return mb_strtolower(preg_replace('/\p{M}+/u', '', $text === false ? '' : $text));
    }
}
