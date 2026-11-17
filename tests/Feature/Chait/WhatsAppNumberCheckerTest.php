<?php

use App\Enums\WhatsAppNumberStatus;
use App\Models\UazapiConnection;
use App\Services\Uazapi\UazapiDeferredException;
use App\Services\Uazapi\UazapiGate;
use App\Services\Uazapi\WhatsAppNumberChecker;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['services.uazapi.base_url' => 'https://uazapi.test', 'chat.enabled' => false]);
});

test('number checker sends the instance token and returns typed registration results', function (bool $registered) {
    $connection = UazapiConnection::factory()->create();
    Http::fake(['*/chat/check' => Http::response([['query' => '5511987654321', 'isInWhatsapp' => $registered, 'jid' => '551187654321@s.whatsapp.net', 'verifiedName' => 'Empresa']])]);
    $result = app(WhatsAppNumberChecker::class)->check($connection, '5511987654321');
    expect($result->status)->toBe($registered ? WhatsAppNumberStatus::Registered : WhatsAppNumberStatus::NotRegistered)
        ->and($result->jid)->toBe('551187654321@s.whatsapp.net')->and($result->verifiedName)->toBe('Empresa');
    Http::assertSent(fn ($r) => $r->url() === 'https://uazapi.test/chat/check' && $r->method() === 'POST'
        && $r['numbers'] === ['5511987654321'] && $r->hasHeader('token', $connection->instance_token) && ! $r->hasHeader('admintoken'));
    Http::assertSentCount(1);
})->with([true, false]);

test('number checker rejects uncertain responses', function ($payload) {
    $connection = UazapiConnection::factory()->create();
    Http::fake(['*' => Http::response($payload)]);
    expect(app(WhatsAppNumberChecker::class)->check($connection, '5511987654321')->status)->toBe(WhatsAppNumberStatus::Unavailable);
})->with([
    'empty' => [[]],
    'object' => [['isInWhatsapp' => true]],
    'wrong number' => [[['query' => '5511987654322', 'isInWhatsapp' => true]]],
    'non boolean' => [[['query' => '5511987654321', 'isInWhatsapp' => 'false']]],
    'missing boolean' => [[['query' => '5511987654321']]],
    'per-item error' => [[['query' => '5511987654321', 'isInWhatsapp' => false, 'error' => 'Unavailable']]],
    'ambiguous' => [[['query' => '5511987654321', 'isInWhatsapp' => true], ['query' => '5511987654321', 'isInWhatsapp' => false]]],
    'malformed json' => ['not json'],
]);

test('HTTP failures and timeouts never mean not registered', function ($status) {
    $connection = UazapiConnection::factory()->create();
    Http::fake(['*' => $status === 'timeout' ? Http::failedConnection() : Http::response([], $status)]);
    expect(app(WhatsAppNumberChecker::class)->check($connection, '5511987654321')->status)->toBe(WhatsAppNumberStatus::Unavailable);
    Http::assertSentCount(1);
})->with([401, 429, 500, 503, 'timeout']);

test('missing token and invalid input never make an HTTP request', function () {
    $connection = UazapiConnection::factory()->create(['instance_token' => null]);
    Http::fake();
    expect(app(WhatsAppNumberChecker::class)->check($connection, '5511987654321')->status)->toBe(WhatsAppNumberStatus::Unavailable);
    expect(fn () => app(WhatsAppNumberChecker::class)->check($connection, '+55 (11) 98765-4321'))->toThrow(InvalidArgumentException::class);
    Http::assertNothingSent();
});

test('capacity deferral preserves its retry delay without contacting the provider', function () {
    $connection = UazapiConnection::factory()->create();
    config(['chat.enabled' => true]);
    $this->mock(UazapiGate::class)->shouldReceive('acquire')->once()->with('connection:'.$connection->id, true)->andThrow(new UazapiDeferredException(7));
    Http::fake();
    $result = app(WhatsAppNumberChecker::class)->check($connection, '5511987654321');
    expect($result->status)->toBe(WhatsAppNumberStatus::Unavailable)->and($result->retryAfter)->toBe(7);
    Http::assertNothingSent();
});

test('provider rate limit preserves retry-after using the existing gate', function () {
    $connection = UazapiConnection::factory()->create();
    config(['chat.enabled' => true]);
    Http::fake(['*' => Http::response([], 429, ['Retry-After' => '12'])]);
    $result = app(WhatsAppNumberChecker::class)->check($connection, '5511987654321');
    expect($result->status)->toBe(WhatsAppNumberStatus::Unavailable)->and($result->retryAfter)->toBe(12);
    Http::assertSentCount(1);
});
