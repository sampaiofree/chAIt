<?php

namespace App\Services\Uazapi;

use RuntimeException;

class UazapiRejectedException extends RuntimeException
{
    public function __construct(public readonly int $status)
    {
        parent::__construct(match ($status) {
            413 => 'A UAZAPI rejeitou o arquivo por tamanho.',
            415 => 'A UAZAPI não aceita o formato deste arquivo.',
            429 => 'Limite de envios atingido. Aguarde antes de tentar novamente.',
            401, 403 => 'A UAZAPI não autorizou o envio. Verifique a conexão do WhatsApp.',
            default => 'A UAZAPI rejeitou o envio. Verifique o arquivo e a conexão.',
        });
    }
}
