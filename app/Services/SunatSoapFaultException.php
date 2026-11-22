<?php

namespace App\Services;

use RuntimeException;

class SunatSoapFaultException extends RuntimeException
{
    public function __construct(private readonly ?string $faultType, private readonly ?string $sunatResponseCode)
    {
        parent::__construct('SUNAT returned a SOAP fault.');
    }

    public function faultType(): ?string
    {
        return $this->faultType;
    }

    public function responseCode(): ?string
    {
        return $this->sunatResponseCode;
    }
}
