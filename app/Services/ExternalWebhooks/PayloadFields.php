<?php

namespace App\Services\ExternalWebhooks;

use JsonException;
use stdClass;

class PayloadFields
{
    public function parse(string $json): stdClass
    {
        if (strlen($json) > config('external-webhooks.max_body_bytes')) {
            throw new WebhookFailure('payload_too_large', 'O JSON excede o tamanho permitido.', 413);
        }
        try {
            $payload = json_decode($json, false, config('external-webhooks.max_depth') + 1, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            $depth = $error->getCode() === JSON_ERROR_DEPTH;
            throw new WebhookFailure($depth ? 'payload_too_deep' : 'invalid_json', $depth ? 'O JSON excede a profundidade permitida.' : 'JSON inválido.', $depth ? 422 : 400);
        }
        if (! $payload instanceof stdClass) {
            throw new WebhookFailure('unsupported_format', 'Envie um objeto JSON com um contato; lotes não são suportados.');
        }
        $this->extract($payload);

        return $payload;
    }

    public function extract(stdClass $payload): array
    {
        $fields = [];
        $visit = function (mixed $value, array $path) use (&$visit, &$fields): void {
            if ($value instanceof stdClass || is_array($value)) {
                foreach ($value as $segment => $child) {
                    $visit($child, [...$path, is_array($value) ? (int) $segment : (string) $segment]);
                }
            } else {
                if (count($fields) >= config('external-webhooks.max_fields')) {
                    throw new WebhookFailure('too_many_fields', 'O JSON contém campos demais.');
                }
                $fields[] = ['path' => $path, 'label' => $this->label($path), 'type' => match (true) {
                    $value === null => 'null', is_bool($value) => 'boolean', is_int($value), is_float($value) => 'number', default => 'string',
                }];
            }
        };
        $visit($payload, []);
        if ($fields === []) {
            throw new WebhookFailure('no_usable_fields', 'A estrutura não contém campos utilizáveis.');
        }

        return $fields;
    }

    public function resolve(stdClass $payload, array $path): mixed
    {
        $value = $payload;
        foreach ($path as $segment) {
            if (is_string($segment) && $value instanceof stdClass && property_exists($value, $segment)) {
                $value = $value->{$segment};
            } elseif (is_int($segment) && is_array($value) && array_key_exists($segment, $value)) {
                $value = $value[$segment];
            } else {
                return null;
            }
        }

        return $value;
    }

    public function label(array $path): string
    {
        $label = '';
        foreach ($path as $segment) {
            $label .= is_int($segment) ? '['.$segment.']' : (preg_match('/^[\pL_][\pL\pN_]*$/u', $segment) ? ($label === '' ? '' : '.').$segment : '['.json_encode($segment, JSON_UNESCAPED_UNICODE).']');
        }

        return $label;
    }
}
