<?php

namespace App\Services\ExternalWebhooks;

use App\Models\Contact;
use App\Models\ContactCustomFieldValue;
use App\Models\ExternalWebhook;
use App\Models\Workspace;
use App\Services\Contacts\ContactIdentity;
use App\Services\Contacts\InternationalPhone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use stdClass;

class WebhookProcessor
{
    public function __construct(private PayloadFields $fields, private InternationalPhone $phones, private WebhookDefinition $definition) {}

    public function prepare(ExternalWebhook $webhook, stdClass $payload, ?array $configuration = null, bool $checkSources = false): array
    {
        $configuration ??= $webhook->only(['name', 'status', 'mappings', 'tag_rules']);
        $configuration['status'] = 'active';
        try {
            $config = $this->definition->validate(Workspace::findOrFail($webhook->workspace_id), $configuration, $checkSources ? $this->fields->extract($payload) : $webhook->payload_fields, $checkSources);
        } catch (ValidationException) {
            throw new WebhookFailure('invalid_configuration', 'A configuração do Webhook está incompleta ou inválida.');
        }
        $native = [];
        $custom = [];
        $warnings = [];
        $phone = null;
        foreach ($config['mappings'] as $i => $mapping) {
            $value = $this->fields->resolve($payload, $mapping['source_path']);
            if ($mapping['target_kind'] === 'native' && $mapping['target'] === 'phone') {
                $phone = $this->phones->normalize($value);

                continue;
            }
            if ($value === null || (is_string($value) && trim($value) === '')) {
                continue;
            }
            if (! is_scalar($value) || (is_float($value) && ! is_finite($value))) {
                $warnings[] = ['mapping' => $i, 'code' => 'incompatible_value'];

                continue;
            }
            $text = is_bool($value) ? ($value ? 'true' : 'false') : trim((string) $value);
            $rules = $mapping['target_kind'] === 'custom' ? ['string', 'max:65535'] : match ($mapping['target']) {
                'email' => ['string', 'email', 'max:255'], 'cpf' => ['string', 'max:14'], default => ['string', 'max:255'],
            };
            if (Validator::make(['value' => $text], ['value' => $rules])->fails()
                || ($mapping['target_kind'] === 'native' && in_array($mapping['target'], ['email', 'cpf'], true) && is_bool($value))) {
                $warnings[] = ['mapping' => $i, 'code' => 'invalid_value'];

                continue;
            }
            if ($mapping['target_kind'] === 'custom') {
                $custom[$mapping['target']] = $text;
            } else {
                $native[$mapping['target']] = $text;
            }
        }
        if ($phone === null) {
            throw new WebhookFailure('invalid_phone', 'Telefone WhatsApp ausente ou inválido. Envie o número completo com DDI.');
        }
        $tags = [];
        $matched = [];
        foreach ($config['tag_rules'] as $i => $rule) {
            $condition = $rule['condition'];
            if ($condition !== null) {
                $actual = $this->fields->resolve($payload, $condition['source_path']);
                $expected = $condition['value'];
                // JSON has one number type; integers and floats compare numerically.
                $equal = (is_int($actual) || is_float($actual)) && (is_int($expected) || is_float($expected)) ? $actual == $expected : $actual === $expected;
                if (! $equal) {
                    continue;
                }
            }
            $matched[] = $i;
            foreach (['add', 'remove'] as $action) {
                foreach ($rule[$action] as $tagId) {
                    $tags[$tagId] = $action;
                }
            }
        }

        return compact('phone', 'native', 'custom', 'warnings', 'tags', 'matched');
    }

    /** Call inside a transaction; all CRM changes form one unit. */
    public function apply(ExternalWebhook $webhook, array $prepared): array
    {
        $existed = Contact::where('workspace_id', $webhook->workspace_id)->where('phone', $prepared['phone'])->exists();
        $contact = app(ContactIdentity::class)->locked($webhook->workspace_id, $prepared['phone']);
        $contact->fill($prepared['native']);
        $changed = ! $existed || $contact->isDirty();
        if ($contact->isDirty()) {
            $contact->save();
        }
        foreach ($prepared['custom'] as $fieldId => $value) {
            $fieldValue = ContactCustomFieldValue::firstOrNew(['workspace_id' => $webhook->workspace_id, 'contact_id' => $contact->id, 'field_id' => $fieldId]);
            $fieldValue->value = $value;
            if (! $fieldValue->exists || $fieldValue->isDirty()) {
                $fieldValue->save();
                $changed = true;
            }
        }
        foreach ($prepared['tags'] as $tagId => $action) {
            if ($action === 'add') {
                $affected = DB::table('contact_tag')->insertOrIgnore(['workspace_id' => $webhook->workspace_id, 'contact_id' => $contact->id, 'tag_id' => $tagId, 'created_at' => now(), 'updated_at' => now()]);
            } else {
                $affected = DB::table('contact_tag')->where('workspace_id', $webhook->workspace_id)->where('contact_id', $contact->id)->where('tag_id', $tagId)->delete();
            }
            $changed = $changed || $affected > 0;
        }

        return ['status' => $changed ? 'processed' : 'ignored', 'contact_id' => $contact->id, 'warnings' => $prepared['warnings']];
    }
}
