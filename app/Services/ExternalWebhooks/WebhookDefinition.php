<?php

namespace App\Services\ExternalWebhooks;

use App\Models\Workspace;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class WebhookDefinition
{
    public function validate(Workspace $workspace, array $input, array $fields, bool $checkSources = true): array
    {
        $input['name'] = is_string($input['name'] ?? null) ? trim($input['name']) : ($input['name'] ?? null);
        $data = Validator::make($input, [
            'name' => ['required', 'string', 'max:120'],
            'status' => ['required', Rule::in(['test', 'active'])],
            'mappings' => ['present', 'array', 'list', 'max:1000'],
            'mappings.*' => ['required', 'array:source_path,target_kind,target'],
            'mappings.*.source_path' => ['required', 'array', 'list', 'min:1', 'max:32'],
            'mappings.*.target_kind' => ['required', Rule::in(['native', 'custom'])],
            'mappings.*.target' => ['required', function ($attribute, $value, $fail) {
                if (! is_string($value) && ! is_int($value)) {
                    $fail('Selecione um destino válido.');
                }
            }],
            'tag_rules' => ['present', 'array', 'list', 'max:100'],
            'tag_rules.*' => ['required', 'array:condition,add,remove'],
            'tag_rules.*.condition' => ['present', 'nullable', 'array:source_path,value'],
            'tag_rules.*.condition.source_path' => ['required_with:tag_rules.*.condition', 'array', 'list', 'min:1', 'max:32'],
            'tag_rules.*.add' => ['present', 'array', 'list', 'max:100'],
            'tag_rules.*.remove' => ['present', 'array', 'list', 'max:100'],
            'tag_rules.*.add.*' => ['integer', 'min:1'],
            'tag_rules.*.remove.*' => ['integer', 'min:1'],
        ])->validate();
        $errors = [];
        $active = $data['status'] === 'active';
        $fieldIds = $workspace->contactCustomFields()->where('type', 'text')->pluck('id')->all();
        $tagIds = $workspace->tags()->pluck('id')->all();
        $seen = [];
        $hasPhone = false;
        $checkPath = function (array $path, string $key) use (&$errors, $fields, $active, $checkSources): ?array {
            foreach ($path as $segment) {
                if ((! is_string($segment) && ! is_int($segment)) || (is_int($segment) && $segment < 0)) {
                    $errors[$key] = 'Selecione um campo de origem válido.';

                    return null;
                }
            }
            $field = collect($fields)->first(fn ($field) => $field['path'] === $path);
            if ($active && $checkSources && ! $field) {
                $errors[$key] = 'A origem não existe no exemplo recebido.';
            }

            return $field;
        };
        foreach ($data['mappings'] as $i => &$mapping) {
            $field = $checkPath($mapping['source_path'], "mappings.$i.source_path");
            $native = $mapping['target_kind'] === 'native';
            if ($native ? ! in_array($mapping['target'], ['phone', 'display_name', 'email', 'cpf'], true) : (! ctype_digit((string) $mapping['target']) || ! in_array((int) $mapping['target'], $fieldIds, true))) {
                $errors["mappings.$i.target"] = 'Selecione um destino válido deste workspace.';
            }
            if (! $native) {
                $mapping['target'] = (int) $mapping['target'];
            }
            $key = $mapping['target_kind'].':'.$mapping['target'];
            if (isset($seen[$key])) {
                $errors["mappings.$i.target"] = 'Este destino já está mapeado.';
            }
            $seen[$key] = true;
            $hasPhone = $hasPhone || ($native && $mapping['target'] === 'phone');
            if ($active && $checkSources && $native && $field && $field['type'] !== 'null') {
                $types = match ($mapping['target']) {
                    'phone', 'cpf' => ['string', 'number'], 'email' => ['string'], default => ['string', 'number', 'boolean'],
                };
                if (! in_array($field['type'], $types, true)) {
                    $errors["mappings.$i.source_path"] = 'O tipo da origem é incompatível com o destino.';
                }
            }
        }
        unset($mapping);
        if ($active && ! $hasPhone) {
            $errors['mappings'] = 'Mapeie o Telefone WhatsApp antes de ativar.';
        }
        foreach ($data['tag_rules'] as $i => &$rule) {
            if ($rule['condition'] !== null) {
                if (! isset($rule['condition']['source_path']) || ! array_key_exists('value', $rule['condition']) || ! is_array($rule['condition']['source_path'])) {
                    $errors["tag_rules.$i.condition"] = 'Configure o campo e o valor da condição.';

                    continue;
                }
                $field = $checkPath($rule['condition']['source_path'], "tag_rules.$i.condition.source_path");
                $value = $rule['condition']['value'];
                if (! is_string($value) && ! is_int($value) && ! is_float($value) && ! is_bool($value)) {
                    $errors["tag_rules.$i.condition.value"] = 'Informe texto, número ou booleano.';
                } elseif ($active && $checkSources && $field && $field['type'] !== 'null') {
                    $type = is_bool($value) ? 'boolean' : (is_string($value) ? 'string' : 'number');
                    if ($type !== $field['type']) {
                        $errors["tag_rules.$i.condition.value"] = 'O valor esperado deve ter o mesmo tipo da origem.';
                    }
                }
            }
            foreach (['add', 'remove'] as $action) {
                $rule[$action] = array_values(array_unique(array_map('intval', $rule[$action])));
                if (array_diff($rule[$action], $tagIds)) {
                    $errors["tag_rules.$i.$action"] = 'Selecione apenas tags deste workspace.';
                }
            }
            if (array_intersect($rule['add'], $rule['remove'])) {
                $errors["tag_rules.$i.remove"] = 'Uma regra não pode adicionar e remover a mesma tag.';
            }
            if ($rule['add'] === [] && $rule['remove'] === []) {
                $errors["tag_rules.$i.add"] = 'Selecione ao menos uma operação de tag.';
            }
        }
        unset($rule);
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return $data;
    }
}
