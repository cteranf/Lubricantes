<?php

namespace App\Contracts;

use App\Models\ElectronicDocument;
use App\Models\ElectronicDocumentAttempt;

interface ElectronicInvoiceGateway
{
    public function send(ElectronicDocument $document, ElectronicDocumentAttempt $attempt): array;

    public function query(ElectronicDocumentAttempt $attempt): array;
}
