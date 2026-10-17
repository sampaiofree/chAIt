<?php

namespace App\Services\Flows;

use App\Rules\ContactTemplateRule;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Public transport shape; block constraints come from the editor's catalog. */
class FlowTransportContract
{
    public const MAX_BYTES = 5 * 1024 * 1024;

    public const RESOURCE_KEYS = ['tag_id' => 'tag_ref', 'field_id' => 'field_ref'];

    public const PRIVATE_KEYS = ['asset_id', 'user_id', 'sequence_id', 'target_flow_id'];

    public static function messages(): array
    {
        return [
            'required' => 'Preencha :attribute.', 'present' => 'Inclua :attribute no arquivo.',
            'in' => 'Valor, tipo ou versão não suportado em :attribute.',
            'array' => 'Estrutura inválida em :attribute. Use somente as propriedades documentadas.',
            'list' => 'Use uma lista JSON em :attribute.', 'string' => 'Use texto em :attribute.',
            'integer' => 'Use um número inteiro em :attribute.', 'numeric' => 'Use um número em :attribute.',
            'boolean' => 'Use true ou false em :attribute.', 'max' => 'Limite excedido em :attribute (máximo :max).',
            'min' => 'Valor insuficiente em :attribute (mínimo :min).', 'between' => ':attribute deve ficar entre :min e :max.',
            'distinct' => 'Identificador repetido em :attribute.', 'regex' => 'Formato inválido em :attribute.',
            'not_regex' => 'Há caracteres não permitidos em :attribute.', 'url' => 'Informe uma URL HTTP ou HTTPS válida em :attribute.',
        ];
    }

    public function dataRules(string $type): array
    {
        $rules = [];
        foreach (app(FlowCapabilities::class)->rules() as $path => $constraints) {
            $prefix = 'nodes.*.data.';
            if (! str_starts_with($path, $prefix)) {
                continue;
            }
            $path = substr($path, strlen($prefix));
            if (! in_array(explode('.', $path)[0], FlowCapabilities::FIELDS[$type], true)) {
                continue;
            }
            $leaf = last(explode('.', $path));
            if (in_array($leaf, self::PRIVATE_KEYS, true)) {
                continue;
            }
            if (isset(self::RESOURCE_KEYS[$leaf])) {
                $path = substr($path, 0, -strlen($leaf)).self::RESOURCE_KEYS[$leaf];
                $constraints = ['nullable', 'string', 'max:64'];
            }
            // Object keys are checked recursively below, after portable references are translated.
            $rules[$path] = array_map(fn ($rule) => $rule instanceof ContactTemplateRule ? new ContactTemplateRule($rule->limit, $rule->context, true) : (is_string($rule) && str_starts_with($rule, 'array:') ? 'array' : $rule), $constraints);
        }
        foreach (['ai_media', 'ai_tags', 'ai_fields'] as $list) {
            if ($type === 'ai') {
                $rules[$list.'.*.id'] = ['sometimes', 'string', 'max:64'];
            }
        }
        if (in_array($type, ['content', 'ai'], true)) {
            $prefix = $type === 'content' ? 'items.*.' : 'ai_media.*.';
            $rules[$prefix.'original_name'] = ['nullable', 'string', 'max:255'];
            if ($type === 'ai') {
                $rules[$prefix.'media_type'] = ['nullable', 'in:image,video,audio,document'];
            }
        }
        foreach (array_keys($rules) as $path) {
            if (($offset = strpos($path, '.*.')) !== false) {
                $rules[substr($path, 0, $offset).'.*'] ??= ['array'];
            }
        }

        return $rules;
    }

    public function validateData(string $type, array $data, string $path): void
    {
        $rules = $this->dataRules($type);
        $this->keys($data, $rules, '', $path);
        $validator = Validator::make($data, $rules, self::messages());
        if ($validator->fails()) {
            throw ValidationException::withMessages(collect($validator->errors()->messages())->mapWithKeys(fn ($errors, $key) => [$path.'.'.$key => $errors])->all());
        }
    }

    /** Reject unknown object properties at every level, including otherwise-valid nested data. */
    private function keys(array $value, array $rules, string $prefix, string $display): void
    {
        foreach ($rules[$prefix] ?? [] as $rule) {
            if ($rule instanceof ContactTemplateRule) {
                return;
            }
        }
        $children = [];
        foreach (array_keys($rules) as $path) {
            if ($prefix === '' || str_starts_with($path, $prefix.'.')) {
                $children[] = explode('.', $prefix === '' ? $path : substr($path, strlen($prefix) + 1))[0];
            }
        }
        $list = in_array('*', $children, true);
        if ($list && ! array_is_list($value)) {
            throw ValidationException::withMessages([$display => 'Informe uma lista JSON.']);
        }
        foreach ($value as $key => $item) {
            if (! $list && ! in_array((string) $key, $children, true)) {
                throw ValidationException::withMessages([$display.'.'.$key => 'Configuração desconhecida ou identificador interno não permitido.']);
            }
            if (is_array($item)) {
                $this->keys($item, $rules, ltrim($prefix.'.'.($list ? '*' : $key), '.'), $display.'.'.$key);
            }
        }
    }

    public function schema(bool $includeAi = true): array
    {
        $variants = [];
        foreach (array_keys(FlowCapabilities::FIELDS) as $type) {
            if (! $includeAi && $type === 'ai') {
                continue;
            }
            $variants[] = [
                'type' => 'object', 'additionalProperties' => false, 'required' => ['id', 'type', 'data'],
                'properties' => [
                    'id' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 64], 'type' => ['const' => $type],
                    'data' => $this->schemaObject($this->dataRules($type)),
                    'position' => (object) [],
                ],
            ];
        }

        return [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema', 'title' => 'CHAIT Flow 1.0',
            'type' => 'object', 'additionalProperties' => false, 'required' => ['format', 'schema_version', 'name', 'dependencies', 'nodes', 'edges'],
            'properties' => [
                'format' => ['const' => 'chait-flow'], 'schema_version' => ['const' => '1.0'],
                'name' => ['type' => 'string', 'maxLength' => 120],
                'dependencies' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['tags', 'fields'], 'properties' => [
                    'tags' => ['type' => 'array', 'maxItems' => 1000, 'items' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['id', 'name'], 'properties' => ['id' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 64], 'name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 100], 'color' => ['type' => 'string', 'pattern' => '^#[0-9a-fA-F]{6}$']]]],
                    'fields' => ['type' => 'array', 'maxItems' => 1000, 'items' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['id', 'name', 'type'], 'properties' => ['id' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 64], 'name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 100], 'type' => ['const' => 'text']]]],
                ]],
                'nodes' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 200, 'items' => ['oneOf' => $variants]],
                'edges' => ['type' => 'array', 'maxItems' => 500, 'items' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['source', 'output', 'target'], 'properties' => ['source' => ['type' => 'string', 'maxLength' => 64], 'output' => ['type' => 'string', 'maxLength' => 64], 'target' => ['type' => 'string', 'maxLength' => 64]]]],
            ],
        ];
    }

    private function schemaObject(array $rules, string $prefix = ''): array
    {
        $properties = [];
        $required = [];
        foreach ($rules as $path => $constraints) {
            if (($prefix !== '' && ! str_starts_with($path, $prefix.'.')) || str_contains($key = $prefix === '' ? $path : substr($path, strlen($prefix) + 1), '.')) {
                continue;
            }
            $type = in_array('integer', $constraints, true) ? 'integer' : (in_array('numeric', $constraints, true) ? 'number' : (in_array('boolean', $constraints, true) ? 'boolean' : 'string'));
            $schema = ['type' => $type];
            if (in_array('array', $constraints, true)) {
                $list = collect(array_keys($rules))->contains(fn ($candidate) => str_starts_with($candidate, $path.'.*.'));
                $schema = $list ? ['type' => 'array', 'items' => $this->schemaObject($rules, $path.'.*')] : $this->schemaObject($rules, $path);
                $type = $list ? 'array' : 'object';
            }
            foreach ($constraints as $constraint) {
                if ($constraint instanceof ContactTemplateRule) {
                    $schema = $constraint->schema();
                    break;
                }
                if (! is_string($constraint)) {
                    continue;
                }
                if (str_starts_with($constraint, 'in:')) {
                    $schema['enum'] = explode(',', substr($constraint, 3));
                } elseif (preg_match('~^(not_regex|regex):/(.*)/[A-Za-z]*$~s', $constraint, $match)) {
                    $pattern = str_replace('\\p{Cc}', '\\u0000-\\u001F\\u007F-\\u009F', $match[2]);
                    if ($match[1] === 'not_regex') {
                        $schema['not'] = ['pattern' => $pattern];
                    } else {
                        $schema['pattern'] = $pattern;
                    }
                } elseif ($constraint === 'url:http,https') {
                    $schema['format'] = 'uri';
                    $schema['pattern'] = '^https?://';
                } elseif (str_starts_with($constraint, 'max:')) {
                    $schema[match ($type) {
                        'array' => 'maxItems', 'string' => 'maxLength', default => 'maximum'
                    }] = (int) substr($constraint, 4);
                } elseif (str_starts_with($constraint, 'between:')) {
                    [$schema['minimum'], $schema['maximum']] = array_map('intval', explode(',', substr($constraint, 8)));
                } elseif (str_starts_with($constraint, 'min:')) {
                    $schema[match ($type) {
                        'array' => 'minItems', 'string' => 'minLength', default => 'minimum'
                    }] = (int) substr($constraint, 4);
                }
            }
            if (in_array('nullable', $constraints, true) && isset($schema['anyOf'])) {
                $schema['anyOf'][] = ['type' => 'null'];
            }
            if (in_array('nullable', $constraints, true) && ! isset($schema['anyOf'])) {
                $schema['type'] = [$type, 'null'];
                if (isset($schema['enum'])) {
                    $schema['enum'][] = null;
                }
            }
            $properties[$key] = $schema;
            if (in_array('required', $constraints, true)) {
                $required[] = $key;
                if ($type === 'string') {
                    $properties[$key]['minLength'] = 1;
                }
            }
        }

        return ['type' => 'object', 'additionalProperties' => false, 'properties' => (object) $properties, 'required' => $required];
    }
}
