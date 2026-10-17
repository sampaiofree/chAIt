<?php

namespace App\Services\Flows;

class TemplateContentException extends \RuntimeException
{
    public function __construct(public readonly string $reason, public readonly string $field)
    {
        parent::__construct('Conteúdo inválido após resolução dos campos do contato.');
    }
}
