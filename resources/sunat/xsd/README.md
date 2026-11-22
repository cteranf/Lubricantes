# SUNAT CPE XSD bundle installed for QA

This is the unmodified extracted payload of SUNAT's **Archivo XSD - 2.1**
linked from <https://cpe.sunat.gob.pe/guias-y-manuales> (page entry updated
2022-02-28). The source archive URL is:

<https://cpe.sunat.gob.pe/sites/default/files/inline-files/Archivos%20XSD%20%281%29.zip>

The locally supplied archive SHA-256 was
`5CAC9D9353521340FBC15D23F465A4946B004535B9FF411541C87DA01694DBD5`. Its
extracted `Archivos XSD/` tree is preserved under this directory, including
both `2.0/` and `2.1/`, relative imports/includes, filenames, XSL and XML files.
The invoice schema entrypoint used for local validation is:

`Archivos XSD/2.1/maindoc/UBL-Invoice-2.1.xsd`

Keep this complete bundle with application releases that enable the local
schema gate. Do not place it under `public/`. Do not edit upstream schemas to
make an invoice pass. The package has no separate top-level license file in
the supplied archive; preserve each schema's embedded notices. The UBL 2.1
invoice/common schemas identify OASIS copyright. This note does not assert a
single package-wide license beyond notices in the source files.

This file tree is the package distributed by SUNAT, but the invoice schema
resolves to generic OASIS UBL 2.1 content and does not encode the complete
SUNAT tax/business validation rules. A successful `schemaValidate()` is only
a structural check, not SUNAT compliance or acceptance. The independent OASIS
fixtures remain at `tests/Fixtures/OasisUBL21/` and must not be used as the
configured SUNAT entrypoint.

The application resolves relative values from its installation root. The
portable configuration is:

```dotenv
SUNAT_UBL_XSD_DIRECTORY=resources/sunat/xsd
SUNAT_UBL_INVOICE_XSD="resources/sunat/xsd/Archivos XSD/2.1/maindoc/UBL-Invoice-2.1.xsd"
```

Use the same configuration in HTTP and worker runtimes. Keep the emission
feature disabled unless its separate operational prerequisites are approved.
