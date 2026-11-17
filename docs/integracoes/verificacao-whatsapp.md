# Verificação de números no WhatsApp

Implementado em 17/11/2026. Testes usam respostas simuladas; o contrato precisa de homologação na instância contratada.

## Serviço reutilizável

`App\Services\Uazapi\WhatsAppNumberChecker::check(UazapiConnection $connection, string $phone)` consulta `POST /chat/check` pelo `UazapiClient`. O cliente também oferece `checkNumbers($connection, $numbers)` para transporte em lote; a interpretação tipada do serviço é individual.

O chamador deve autorizar o acesso ao workspace, selecionar sua conexão no backend e normalizar o telefone com `App\Services\Contacts\InternationalPhone`. O serviço exige a forma canônica internacional, somente dígitos, e lança `InvalidArgumentException` para entradas fora desse contrato, sem chamada HTTP.

```php
use App\Enums\WhatsAppNumberStatus;
use App\Services\Contacts\InternationalPhone;
use App\Services\Uazapi\WhatsAppNumberChecker;

// $connection deve pertencer ao workspace autorizado pelo chamador.
$phone = app(InternationalPhone::class)->normalize($input);
if ($phone === null) {
    throw new InvalidArgumentException('Telefone inválido.');
}
$result = app(WhatsAppNumberChecker::class)->check($connection, $phone);
if ($result->status === WhatsAppNumberStatus::Registered) {
    // Aplicar a regra de negócio do chamador.
}
```

`WhatsAppNumberCheckResult` é imutável e contém `status` (`WhatsAppNumberStatus`), `jid`, `verifiedName` e `retryAfter` (segundos, opcional).

| Estado | Significado |
| --- | --- |
| `registered` | Uma entrada corresponde exatamente ao `query` solicitado, com `isInWhatsapp: true` e sem erro. |
| `not_registered` | Entrada correspondente, com `isInWhatsapp: false` explícito e sem erro. |
| `unavailable` | Sem token, falha de transporte/configuração/HTTP, limite, resposta inválida, ambígua ou erro por item. Nunca significa número inexistente. |

A resposta deve ser uma lista e conter exatamente uma entrada correspondente. Strings como `"true"`/`"false"` não são convertidas para booleanos. `jid` e nome são opcionais e só retornados se forem strings. Não substituir o telefone armazenado pelo JID: normalização brasileira e reconciliação de identidade são questões separadas.

## Transporte e autenticação

Usa o header `token` da instância; nunca o token administrativo. Corpo: `{"numbers":["5511987654321"]}`. Não expor tokens ao frontend nem registrar telefone, token ou payload completo em logs.

Reutiliza o timeout atual de 25 segundos e, quando habilitado, o controle existente de limites como leitura interativa. `retryAfter` preserva o adiamento informado pelo controle, inclusive `Retry-After` em HTTP 429. Não há cache, repetição automática, consulta prévia de `/instance/status`, envio de mensagem ou gravação de contatos. O resultado representa a consulta naquele momento, não uma garantia permanente.

[Contrato oficial da UAZAPI](https://docs.uazapi.com/openapi-bundled.json), operação `checkChat`, `POST /chat/check`.

## Cadastro manual de leads

Leads autoriza, valida e normaliza os dados e verifica duplicidade antes da consulta. Seleciona a conexão do workspace no servidor. Somente `registered` permite iniciar a transação de cadastro; a unicidade transacional continua protegendo contra um contato recebido por webhook durante a consulta.

`not_registered` apresenta “Este número não está cadastrado no WhatsApp.”. `unavailable` mantém o modal e os campos preenchidos, orientando conferir a conexão e tentar novamente; quando disponível, informa o tempo de espera. Sem conexão cadastrada, orienta conectar o WhatsApp do workspace. O formulário bloqueia envio duplicado e fechamento durante processamento.

Listagem, ficha e edição continuam disponíveis sem WhatsApp. Webhooks e outros cadastros mantêm suas regras atuais. O serviço não verifica permissão por conta própria: essa responsabilidade pertence a cada chamador.

## Validação automatizada

- Backend: `php artisan test tests/Feature/Chait/WhatsAppNumberCheckerTest.php tests/Feature/Chait/LeadsTest.php` (28 cenários).
- Navegador: após `npm run build`, executar `CHAIT_BROWSER_WHATSAPP_CHECK=true npx playwright test tests/Browser/lead-verification.spec.ts`. A opção habilita respostas HTTP simuladas somente no servidor isolado de testes, sem consultar números reais.
- A cobertura inclui respostas negativas/inconclusivas, falhas HTTP e transporte, token, limites e espera, permissões, duplicidade concorrente, preservação dos campos e bloqueio durante envio.
