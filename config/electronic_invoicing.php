<?php

return [
    // Commercial entitlement is deployment-controlled and cannot be edited in the profile API.
    'enabled' => (bool) env('ELECTRONIC_INVOICING_ENABLED', false),
    'sunat' => [
        // Fixed official beta endpoint; never accept a URL from an API request.
        'testing_endpoint' => 'https://e-beta.sunat.gob.pe/ol-ti-itcpfegem-beta/billService',
        'xsd_directory' => env('SUNAT_UBL_XSD_DIRECTORY', 'resources/sunat/xsd'),
        // Deliberately unset by default: the bundled OASIS schemas are useful
        // for local structural tests, but are not the SUNAT validation bundle.
        // Emission stays blocked until the owner installs and configures SUNAT's XSD.
        'invoice_xsd' => env('SUNAT_UBL_INVOICE_XSD'),
        'summary_xsd' => env('SUNAT_UBL_SUMMARY_XSD'),
        'cdr_xsd' => env('SUNAT_UBL_CDR_XSD', 'resources/sunat/xsd/Archivos XSD/2.0/maindoc/UBLPE-ApplicationResponse-1.0.xsd'),
    ],
    // Optional per-operation OpenSSL config path; primarily used by isolated test runtimes.
    'openssl_config' => null,
    // QA-only deterministic scenario, selected only when the independent Beta flag is disabled.
    'receipt_simulation' => [
        'scenario' => env('ELECTRONIC_RECEIPT_SIMULATION_SCENARIO', 'pending'),
    ],
    // Independent, opt-in Beta transport. The adapter hard-blocks production and fixes the endpoint in code.
    'receipt_beta' => [
        'enabled' => (bool) env('SUNAT_BETA_RECEIPT_SUMMARY_ENABLED', false),
        'endpoint' => 'https://e-beta.sunat.gob.pe/ol-ti-itcpfegem-beta/billService',
        // Empty means CDRs remain uncertain until an out-of-band trusted certificate fingerprint is installed.
        'cdr_certificate_sha256' => env('SUNAT_BETA_CDR_CERT_SHA256', ''),
    ],
];
