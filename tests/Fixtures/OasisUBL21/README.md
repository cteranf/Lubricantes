# OASIS UBL 2.1 schemas — test fixture

This directory contains the complete `xsd/` subtree from the OASIS UBL 2.1
OASIS Standard release, published 4 November 2013. The source archive is
<https://docs.oasis-open.org/ubl/os-UBL-2.1/UBL-2.1.zip>; its SHA-256 recorded
when obtained was
`60B80D76394A8A2ADD90723ECB8E0E2E9D826775DE9749DF37A72D60703F86ED`.
The subtree has 79 XSD files under `xsd/common/` and `xsd/maindoc/`. Keep the
directory structure unchanged because schema imports are relative.

The schemas are used by automated tests for generic UBL 2.1 XML structure.
They are **not** the SUNAT XSD bundle and do not establish SUNAT compliance.
The official SUNAT XSD archive is published separately from
<https://cpe.sunat.gob.pe/guias-y-manuales> and must be configured separately
before emission can pass its SUNAT-specific schema gate.

Each schema retains its OASIS copyright and reproduction notice. The notice
permits copying and distribution provided the copyright notice and notice
paragraph stay with copies. The UBL Technical Committee's IPR mode is RF on
Limited Terms; this describes the committee's essential-claims patent
licensing mode and is not a replacement for the copyright notice in each
schema. No SPDX license identifier is asserted for this fixture.

References:

- <https://docs.oasis-open.org/ubl/os-UBL-2.1/UBL-2.1.html>
- <https://www.oasis-open.org/tc-ubl/>
- <https://www.oasis-open.org/who/ipr/ipr_faq.php>
