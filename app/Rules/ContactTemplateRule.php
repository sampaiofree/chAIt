<?php

namespace App\Rules;

use App\Services\Flows\ContactTemplate;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Validator;

class ContactTemplateRule implements ValidationRule
{
    public function __construct(public int $limit, public string $context, public bool $portable = false) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_array($value)) {
            if (Validator::make(['value' => $value], ['value' => ContactTemplate::stringRules($this->limit, $this->context)])->fails()) {
                $fail('Conteúdo inválido ou acima do limite permitido.');
            }

            return;
        }
        $idKey = $this->portable ? 'field_ref' : 'field_id';
        $rules = ['value' => ['array:template_version,parts'], 'value.template_version' => ['required', 'integer', 'in:1'], 'value.parts' => ['required', 'array', 'list', 'min:1', 'max:1000'], 'value.parts.*' => ['required', 'array:type,text,key,'.$idKey], 'value.parts.*.type' => ['required', 'in:text,standard,custom']];
        if (($value['template_version'] ?? null) !== 1 || Validator::make(['value' => $value], $rules)->fails()) {
            $fail('Documento de campos do contato inválido.');

            return;
        }
        $length = 0;
        foreach ($value['parts'] as $p) {
            $valid = match ($p['type']) {
                'text' => array_keys($p) === ['type', 'text'] || (count($p) === 2 && isset($p['text'])),
                'standard' => count($p) === 2 && is_string($p['key'] ?? null) && isset(ContactTemplate::STANDARD[$p['key']]),
                'custom' => count($p) === 2 && ($this->portable ? is_string($p['field_ref'] ?? null) && strlen($p['field_ref']) > 0 && strlen($p['field_ref']) <= 64 : is_int($p['field_id'] ?? null) && $p['field_id'] > 0),
            };
            if (! $valid || ($p['type'] === 'text' && ! is_string($p['text']))) {
                $fail('Referência ou texto do chip inválido.');

                return;
            }
            if ($p['type'] === 'text') {
                $length += mb_strlen($p['text']);
                if (in_array($this->context, ['url', 'copy', 'label'], true) && preg_match($this->context === 'url' ? '/[|\p{Cc}\s]/u' : '/[|\p{Cc}]/u', $p['text'])) {
                    $fail('Texto literal contém caracteres reservados para este campo.');

                    return;
                }
            }
        }
        if ($length > $this->limit) {
            $fail('Texto literal acima do limite permitido.');
        }
    }

    public function schema(): array
    {
        $object = fn ($props) => ['type' => 'object', 'properties' => $props, 'required' => array_keys($props), 'additionalProperties' => false];

        return ['anyOf' => [['type' => 'string', 'maxLength' => $this->limit], $object(['template_version' => ['type' => 'integer', 'enum' => [1]], 'parts' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 1000, 'items' => ['anyOf' => [
            $object(['type' => ['type' => 'string', 'enum' => ['text']], 'text' => ['type' => 'string', 'maxLength' => $this->limit]]),
            $object(['type' => ['type' => 'string', 'enum' => ['standard']], 'key' => ['type' => 'string', 'enum' => array_keys(ContactTemplate::STANDARD)]]),
            $object(['type' => ['type' => 'string', 'enum' => ['custom']], ($this->portable ? 'field_ref' : 'field_id') => ($this->portable ? ['type' => 'string', 'minLength' => 1, 'maxLength' => 64] : ['type' => 'integer', 'minimum' => 1])]),
        ]]]])]];
    }
}
