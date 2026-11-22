# Facturación electrónica — Fase 3 (SUNAT beta)

Revisión técnica: 21 de noviembre de 2026. Mantener el módulo deshabilitado hasta completar las condiciones de preparación descritas abajo.

## Alcance disponible

La integración admite únicamente facturas nacionales ordinarias del SEE del contribuyente en ambiente `testing`. Genera UBL 2.1 desde snapshots, firma el XML, valida la estructura XSD configurada, empaqueta la carpeta vacía `dummy` y el XML en ZIP, envía con SOAP y procesa CDR. La confirmación es manual para Administración/Facturación y se ejecuta en una cola database duradera; no mantiene locks durante la llamada externa.

Boletas siguen bloqueadas porque requieren resumen diario, ticket y consulta asíncrona. También bloquean la emisión los anticipos sin tratamiento, envío cobrado con afectaciones mixtas, snapshots incompletos, documentos históricos incompletos y operaciones no soportadas. Calcular impuestos no basta para habilitar la emisión.

El número queda reservado junto con el contenido confirmado, intento, evento y fila outbox en una transacción. Un publicador idempotente coloca el job en `electronic-invoices`; un comando programado vuelve a publicar outbox pendiente. Un timeout sin respuesta concluyente pasa a `uncertain`, conserva número y snapshots, y no se reenvía automáticamente. Un SOAP Fault explícito se registra como `sunat_exception`, conserva también la numeración y no activa reenvíos. Las respuestas CDR aceptadas, observadas o rechazadas quedan en el historial.

La representación PDF solo está disponible para facturas `testing` con estado final y numeración. Se genera desde snapshots, se marca visiblemente **COPIA DE PRUEBA · NO ES UN COMPROBANTE VÁLIDO PARA EFECTOS TRIBUTARIOS**, deshabilita recursos remotos/JavaScript/PHP y se descarga dentro del middleware autorizado de Facturación. No es el PDF tributario oficial.

## Esquemas XSD

La página oficial de SUNAT enlaza **Archivo XSD - 2.1 (actualizado al 28/02/2022)** desde [Guías y manuales CPE](https://cpe.sunat.gob.pe/guias-y-manuales). URL exacta del ZIP:

`https://cpe.sunat.gob.pe/sites/default/files/inline-files/Archivos%20XSD%20%281%29.zip`

El propietario descargó ese ZIP y lo dejó en `D:\Emprendimientos\FACTURAS SUNAT\Archivos XSD (1).zip`. SHA-256 comprobado: `5CAC9D9353521340FBC15D23F465A4946B004535B9FF411541C87DA01694DBD5`. La comparación exacta del ZIP con la carpeta extraída confirmó 112 entradas, 105 archivos extraídos, 0 faltantes, 0 diferencias y 0 archivos adicionales. Se instaló el árbol completo y sin editar dentro de `resources/sunat/xsd/Archivos XSD/`: 23 XSD, 3 XSL y 1 XML bajo `2.0`; 78 XSD bajo `2.1`. Se conservaron los nombres y las rutas relativas. No se copiaron el PDF, el manual ni el Excel al repositorio. La hoja de operaciones de servicios públicos permanece fuera del análisis.

Como fixture de pruebas se obtuvo el paquete oficial OASIS UBL 2.1 desde `https://docs.oasis-open.org/ubl/os-UBL-2.1/UBL-2.1.zip`. SHA-256 del ZIP: `60B80D76394A8A2ADD90723ECB8E0E2E9D826775DE9749DF37A72D60703F86ED` (57,926,016 bytes). El árbol íntegro de `xsd/` (79 XSD) vive en `tests/Fixtures/OasisUBL21/xsd/`; OASIS declara normativos `xsd/maindoc` y sus dependencias en `xsd/common`. Se usa solo para validar estructura UBL genérica. Sus encabezados y notices de reproducción se conservaron; la fuente y términos están resumidos en `tests/Fixtures/OasisUBL21/README.md`. No sustituye el bundle ni las reglas SUNAT. [Especificación UBL 2.1 de OASIS](https://docs.oasis-open.org/ubl/os-UBL-2.1/UBL-2.1.html).

El punto de entrada instalado es `resources/sunat/xsd/Archivos XSD/2.1/maindoc/UBL-Invoice-2.1.xsd`, versión de esquema UBL 2.1, namespace `urn:oasis:names:specification:ubl:schema:xsd:Invoice-2`, con versión liberada 2013-11-04 y copyright OASIS 2013 en el propio archivo. La resolución recursiva del XSD de factura encontró 14 archivos y ninguna dependencia ausente o externa. El root y sus XSD comunes correspondientes son idénticos a los de OASIS UBL 2.1. Por ello, el ZIP está distribuido oficialmente por SUNAT, pero este XSD de factura valida estructura UBL genérica: no contiene por sí solo el conjunto de reglas tributarias de SUNAT ni acredita aceptación de un CPE. El paquete no trae un archivo de licencia global identificado; se mantuvieron intactos los avisos de copyright y reproducción presentes en cada archivo. El bundle completo debe distribuirse con la aplicación/deployment y no debe servirse desde `public/`. No se afirma una licencia uniforme para archivos que no la declaren.

El validador inspecciona recursivamente `include`, `import`, `redefine` y `override`; rechaza dependencias de red, traversal y rutas fuera del directorio autorizado; carga y valida con `LIBXML_NONET`. La suite comprueba una factura firmada contra OASIS y contra el árbol completo instalado, rechaza XML inválido e informa un bundle incompleto. La falta de `SUNAT_UBL_INVOICE_XSD` **mantiene bloqueada la confirmación antes de reservar numeración**; las pruebas OASIS no abren esa puerta.

La instancia del proyecto se configura a través de las variables `SUNAT_UBL_XSD_DIRECTORY` y `SUNAT_UBL_INVOICE_XSD` (el valor comercial `ELECTRONIC_INVOICING_ENABLED` continúa deshabilitado de forma predeterminada). Para una sesión local aislada de PowerShell, sin editar `.env`:

```dotenv
$env:SUNAT_UBL_XSD_DIRECTORY='C:\laragon\www\Lubricantes\resources\sunat\xsd'
$env:SUNAT_UBL_INVOICE_XSD='C:\laragon\www\Lubricantes\resources\sunat\xsd\Archivos XSD\2.1\maindoc\UBL-Invoice-2.1.xsd'
```

En otras máquinas hay que ajustar ambas rutas absolutas al release desplegado. Aplicar la misma configuración al proceso HTTP y a los workers; si se usa `config:cache`, regenerar la caché en ese entorno y reiniciar los workers después del cambio. No configurar el proceso con una ruta del directorio de descargas del propietario. No apuntar `SUNAT_UBL_INVOICE_XSD` a `tests/Fixtures/OasisUBL21` y llamarlo validación SUNAT. La revisión automática usa la configuración Laravel del proceso de pruebas, no modifica `.env` ni la base normal.

## Reglas revisadas contra SUNAT

Se leyó la hoja `Factura2_0` del archivo oficial `Reglas de validación - actualizado al 26.08.2026.xlsx` provisto por el propietario; las hojas de boleta, resúmenes y servicios públicos no amplían el alcance. La página oficial también enlaza la guía de factura UBL 2.1 (mayo de 2017), que SUNAT identifica como ayuda técnica y no como interpretación tributaria. Las reglas activas de la matriz del 26/08/2026 se separaron de cambios cuyo control de cambios difiere a 01/01/2027.

| Área | Evidencia y cobertura | Pendiente/limitación |
| --- | --- | --- |
| Esquema | XSD oficial del paquete para Invoice 2.1; imports locales, sin red. | El XSD no aplica reglas tributarias/XSL; no equivale a cumplimiento SUNAT. |
| Identidad y tipo | Factura `01`, emisor RUC, receptor RUC para factura, país Perú, nombre comercial de emisor y nombre de receptor; tipo de operación interna `0101`; `ProfileID` e `InvoiceTypeCode@listID` incluidos. | No se consulta el padrón para comprobar estado/condición del RUC ni la validez comercial del receptor. |
| Serie, número y fecha | XML conserva serie `F###`, correlativo de ocho dígitos, `IssueDate` y moneda del snapshot. Nombre de ZIP/XML sigue el patrón del Manual del Programador. | Reglas de emisión en producción, vencimiento, hora u otros escenarios documentales siguen sin homologación real. |
| Moneda e importes | Moneda con metadatos ISO; los totales e IGV usan los snapshots tributarios persistidos y cálculo decimal probado, sin recalcular el pedido. | No se afirman todas las reglas de redondeo, leyendas o escenarios adicionales del Excel. |
| Afectación tributaria | Se emiten únicamente las afectaciones implementadas 10 gravado, 20 exonerado y 30 inafecto, con códigos de esquema 1000/9997/9998 y agrupaciones probadas. | ISC, exportación, gratuito, retenciones, detracciones, IVAP y tipos no soportados se bloquean o siguen pendientes. |
| Operación | Solo catálogo 51 `0101` venta interna ordinaria; no se infiere una operación de los datos incompletos. | Otros códigos del catálogo, incluidos operaciones especiales, están fuera de esta fase. |
| Forma de pago | Builder genera `PaymentTerms` FormaPago/Contado solo si el snapshot indica pago completo aprobado y coincide con el total; la elegibilidad bloquea pendiente, parcial, anticipo o importes incoherentes. | Crédito, cuotas y avances no están implementados. Se conserva bloqueo aun si el cálculo tributario está completo. |
| Envío | El costo cero de recojo/envío gratuito no agrega línea. | Envío cobrado con afectaciones mixtas queda en revisión; no se distribuye arbitrariamente entre líneas. |
| Boleta | El módulo mantiene bloqueada la boleta. | Requiere resumen diario/ticket/consulta asíncrona y pruebas distintas. |

La matriz puede incluir condiciones que no se han implementado ni probado en esta fase. Ninguna validación aislada de XSD, prueba local o CDR beta se presenta como certificación fiscal.

## Runtime PHP

El CLI efectivo de esta revisión es PHP 8.5.13 en `C:\laragon\bin\php\php-8.5.13-Win32-vs17-x64\php.exe`, con el `php.ini` de ese mismo directorio. DOM, OpenSSL y ZIP están disponibles. Para la firma sintética automatizada se estableció solo en el proceso de PHPUnit `OPENSSL_CONF=C:\laragon\bin\php\php-8.5.13-Win32-vs17-x64\extras\ssl\openssl.cnf`; no se cambió una variable global. Cada runtime HTTP/worker debe revisar sus propios binarios y habilitar `zip`, `openssl` y `dom`.

## Recuperación, concurrencia y límites de evidencia

- MySQL 8.4.3/InnoDB aislado: dos conexiones independientes confirmaron el mismo draft; una sola numeración, intento, evento `confirmed`, fila outbox publicada y job quedó en base. Se comprobó que el segundo proceso esperó el lock de la cabecera.
- MySQL aislado: dos conexiones asignaron correlativos consecutivos por el lock de serie.
- SQLite/transport mock: publicación del outbox idempotente, job duplicado envía una sola vez, worker stale pasa a `uncertain` sin reenvío automático, CDR `accepted`, `accepted_observed`, `rejected` y no concluyente.
- Se simuló el fallo después del commit y antes de publicar: la confirmación, número, intento, evento y fila outbox quedan durables; el publicador de recuperación crea un solo job al reintentar. Se simuló timeout, consulta posterior con CDR tardía `accepted_observed` y repetición del job antiguo sin duplicar el intento ni cambiar la numeración.
- La caída física de worker antes/después de una llamada SOAP no se inyectó en un proceso real; el comportamiento se cubre con transporte mock y reconciliación de `processing` vencido. Una prueba Beta controlada anterior está documentada más abajo; no se realizaron nuevos envíos para este diagnóstico. Consulta oficial de CDR para una factura síncrona en Beta no está disponible en el WSDL revisado.
- Una respuesta tardía de worker no sobrescribe estados finales; el resultado incierto conserva el mismo número para consulta manual. No se afirma haber simulado una caída física del proceso dentro de una llamada SOAP real.

### Revalidación MySQL QA del 21/11/2026

Se creó `lubristore_php85_laravel13_qa_20261121_a91c` solo después de conectar a `information_schema`, comprobar que el esquema no existía y crear un destino nuevo. Antes de migrar, Laravel confirmó `SELECT DATABASE()` con ese nombre, MySQL 8.4.3 e InnoDB. Se aplicaron las migraciones completas únicamente en ese esquema aislado. `MySqlElectronicDocumentSeriesConcurrencyTest` aprobó **2 tests / 39 assertions**: las dos conexiones independientes asignaron números distintos y la confirmación concurrente del mismo borrador produjo una sola emisión/outbox/job. La primera ejecución, antes de preparar el esquema, encontró la tabla de series ausente; se clasificó como precondición de base vacía y no como fallo funcional. Luego de migrar el esquema QA, la clase completa pasó. No se usó `lubricantes` ni `lubristore_remote_payments_test`.

La migración adicional `2026_11_19_071300_create_electronic_document_outbox_table` se aplicó solo en la base aislada `lubristore_einvoice_phase3_20261119_a7f3` después de verificar desde Laravel `SELECT DATABASE()`, motor InnoDB y versión MySQL 8.4.3. En un primer intento falló el nombre autogenerado de FK por superar el límite de MySQL; se asignó `electronic_doc_outbox_attempt_fk`, se retiró únicamente la tabla parcial vacía de esa base y la migración quedó aplicada. La migración 071200 del lote ya estaba aplicada allí. No se migró la base local normal.

## PDF y acceso

`GET /api/v1/billing/electronic-documents/{id}/files/pdf` usa las mismas rutas protegidas de Facturación, requiere factura de ambiente `testing`, estado final y serie/número. Se verificó en HTTP con usuario Facturación y rechazo a customer; el cuerpo empieza por `%PDF-`, el nombre del archivo contiene `-PRUEBA.pdf` y la vista usa emisor, receptor, líneas, importes y estado del snapshot. El **21/11/2026** se renderizó el PDF sintético con Dompdf 3.1.6 y se inspeccionaron visualmente sus dos páginas a partir de imágenes PNG: acentos y caracteres españoles legibles; serie/número, emisor/receptor, precios y totales visibles; cabecera de columnas repetida al saltar de página; impuesto 576.00, base 3200.00 y total 3776.00 coherentes con las 32 líneas sintéticas de 118.00. La revisión detectó inicialmente que la segunda página no llevaba la identificación de prueba; la plantilla ahora fija el aviso `COPIA DE PRUEBA · NO ES UN COMPROBANTE VÁLIDO PARA EFECTOS TRIBUTARIOS` en cada página y reserva espacio superior para que no se solape con la tabla. La segunda renderización mostró el aviso en ambas páginas, sin texto cortado ni superposición. El archivo y sus renders fueron temporales, fuera del repositorio. El test HTTP de autorización y generación permanece en `ElectronicInvoicingFoundationTest`.

## Credenciales beta y certificados

La pauta oficial vigente de Beta indica que el usuario es el **RUC del emisor** concatenado con `MODDATOS` y que la contraseña publicada para Beta es `MODDATOS` en mayúsculas. El manual general incluye un ejemplo de UsernameToken en otro contexto con `moddatos` en minúsculas; para este endpoint prevalece la pauta específica Beta. El gateway usa credenciales explícitas configuradas si existen; el fallback de Beta ahora coincide con la pauta específica en mayúsculas. No se publica un RUC comodín ni se presume que uno sintético sea aceptado. [Pautas del servicio Beta](https://orientacion.sunat.gob.pe/12-pautas-servicio-beta), [Manual del programador SEE](https://cpe.sunat.gob.pe/sites/default/files/inline-files/manual_programador%20%281%29.pdf).

Separación de certificados:

- **Pruebas locales:** se generó en memoria un PFX autofirmado sintético con el RUC de fixture en el atributo `OU`; se comprobó su vigencia, lectura PKCS#12 y correspondencia entre clave privada y certificado, sin guardar la clave/PFX ni imprimir secretos. La suite `ElectronicInvoiceCredentialServiceTest` aprobó tras cambiar el fixture para usar `OU`. Esto demuestra que podemos hacer QA sin comprar un certificado; no demuestra admisión del RUC ficticio ni aceptación por SUNAT.
- **SUNAT beta:** SUNAT dice que no se necesita un certificado digital registrado para el servicio Beta. Nuestra aplicación impone un requisito propio: un PFX vigente, contraseña, clave privada coincidente y el RUC configurado presente en el Subject. La regla productiva de certificado del Manual del Programador describe el RUC en `OU`; el PFX sintético de QA usa ese atributo. La app no comprueba cadena de confianza ni revocación. Beta no equivale a producción ni a homologación.
- **Producción:** certificado digital válido del emisor, emitido por proveedor admitido por el marco peruano y registrado/administrado para la emisión productiva; esta fase fuerza `testing` y no habilita producción.

Para preparar el siguiente envío controlado ya no falta el RUC autorizado. El PFX/contraseña de QA debe permanecer configurado únicamente en el almacén privado local; no se copiará a Git, tickets ni logs. No se necesita clave SOL privada para la autenticación Beta publicada.

## Diagnóstico de transporte Beta (21/11/2026)

Se revisó en modo lectura el índice y las respuestas privadas de la prueba ya existente (sin mostrar ni registrar credenciales ni el cuerpo SOAP completo). El documento QA ID 2, serie/número `F001-00000001`, sigue intacto, sin CDR y con su numeración retenida. El SOAP Fault conservado fue una respuesta SOAP HTTP exitosa con `faultcode` `Client.0160`; su texto señala XML vacío/error de tamaño. No era timeout ni una CDR de rechazo. El Manual del Programador explica que `Client` indica como probable un problema del cliente, y clasifica los códigos 0100–0999 como excepciones del servicio; no encontramos en ese manual una entrada que defina el significado exacto de `0160`. La aplicación conserva el código y el mensaje seguro observado, y clasifica faults `Client` como `sunat_exception`; los faults `Server` sin CDR siguen `uncertain`. Ninguno habilita reenvío automático ni libera número. El estado incierto del documento histórico no se modificó. [Manual del programador SEE, manejo de errores y Fault](https://cpe.sunat.gob.pe/sites/default/files/inline-files/manual_programador%20%281%29.pdf).

El [WSDL Beta vigente](https://e-beta.sunat.gob.pe/ol-ti-itcpfegem-beta/billService?wsdl) publica SOAP 1.1 document/literal, operaciones `sendBill`, `sendPack`, `sendSummary`, `getStatus` y `getStatusAR`, con `sendBill`/`urn:sendBill`; no publica `getStatusCdr`. El manual describe `getStatus` para tickets de operaciones asíncronas, no para recuperar el resultado síncrono de `sendBill`. El request usa `Content-Type: text/xml; charset=utf-8` y la SOAPAction del WSDL. `fileName` es texto y `contentFile` es `base64Binary`; el binding WSDL no declara multipart MIME. El manual requiere para la factura individual un ZIP con carpeta vacía `dummy` y un XML; ambos elementos se conservan. La construcción interceptada prueba que `contentFile` decodificado sea byte por byte el ZIP privado, que el ZIP contenga `dummy/` y el XML cuyo SHA-256 coincide con el XML firmado almacenado, que el nombre coincida con el documento y que SOAPAction sea del WSDL.

El mensaje del Fault conservado informa XML vacío/error de tamaño, pero el manual del programador no define el código `Client.0160`; por ello no se afirma una causa interna exacta ni que la carpeta `dummy` provoque el error. El manual indica `contentFile` como bytes y el WSDL como `base64Binary`; el ejemplo SOAP del manual representa el binario como `cid:`. La construcción actual inline se valida localmente como una sola codificación base64 que, al decodificarse, reproduce exactamente el ZIP guardado. No se puede concluir a partir de estos documentos que `Client.0160` se deba al transporte inline; la siguiente prueba autorizada deberá resolver esa diferencia empíricamente. [Manual del programador SEE, envío y ejemplo SOAP](https://cpe.sunat.gob.pe/sites/default/files/inline-files/manual_programador%20%281%29.pdf).

La consulta anterior recibió HTTP 401 HTML, no SOAP Fault. Se estaba invocando `getStatusCdr` en el endpoint Beta de emisión. Como la operación no existe en el WSDL Beta, el gateway deja de realizar esa petición y devuelve un resultado controlado sin red; no se redirige al endpoint productivo. El 401 no demuestra que la contraseña fuese incorrecta. La solicitud saliente original no se conservó. La lectura de solo lectura se verificó contra la base QA autorizada (MySQL 8.4.3/InnoDB); allí no había credenciales explícitas en el singleton, por lo que la versión anterior habría usado el fallback `moddatos` minúscula. La pauta Beta específica exige `MODDATOS` mayúscula, que ahora es el fallback. No se imprimió ni se alteró ningún secreto.

El SOAP Fault no se transmitió de nuevo y tampoco se alteró el documento existente, sus snapshots, XML, ZIP ni número. La próxima prueba controlada debe crear un documento QA sintético nuevo solo después de autorización operativa, enviar una sola vez el ZIP de una entrada con SOAP 1.1/`urn:sendBill` y fallback de credenciales Beta actualizado, conservar la respuesta sanitizada/CDR en el almacenamiento privado, y detenerse para revisión si vuelve una excepción. La consulta de CDR queda pendiente de un canal oficialmente disponible; no se reintentará `getStatusCdr` en Beta.

## Dependencias

Se añadió `barryvdh/laravel-dompdf` 3.1.2 y Dompdf 3.1.6 para PDF; se añadió/actualizó la firma con `robrichards/xmlseclibs` 4.0.2. El audit estructurado completo, con paquete, versión actual/base, advisory, severidad, rango afectado y enlace, está en [`FACTURACION_ELECTRONICA_FASE_3_COMPOSER_AUDIT.json`](FACTURACION_ELECTRONICA_FASE_3_COMPOSER_AUDIT.json).

Comparación del lock base con el actual: 55 avisos en 15 paquetes antes; 42 en 13 paquetes ahora; cero IDs nuevos. Guzzle estaba en 7.10.0 y afectaba el transporte SOAP; se actualizó con dependencias compatibles a 7.16.3 dentro de `^7.2`, lo que eliminó sus nueve avisos del audit vigente. Dompdf 3.1.6 y xmlseclibs 4.0.2 no aparecen afectados por los avisos actuales. Siguen 42 avisos heredados, incluidos avisos en Laravel Framework 9.52.21 (uno crítico y otros de severidad alta/media/baja). Sus versiones corregidas requieren una actualización mayor de Laravel; se recomienda un lote separado de compatibilidad y seguridad. Composer reporta además un advisory medio ignorado previamente en Laravel, registrado en el JSON.

Los advisories de Symfony/Guzzle pueden afectar request parsing, SOAP y operaciones de archivos; el audit no detectó un aviso nuevo originado en Dompdf/xmlseclibs. Revalidar audit en CI y planificar Laravel 10+ aparte. No se ejecutó `composer audit fix --force`.

## Pruebas de esta revisión

- `SunatUblInvoiceBuilderTest`: **4 tests / 55 assertions**, aprobado. Comprueba firma tras serialización, XSD OASIS y bundle SUNAT instalado, XML inválido, bundle incompleto, traversal y dependencia remota bloqueados.
- `ElectronicInvoicingFoundationTest`: **16 / 213**, aprobado en SQLite en memoria. Incluye bloqueo por XSD ausente antes de numerar, elegibilidad, PDF autorizado, outbox durable/idempotente, Fault SOAP clasificado y consulta Beta bloqueada sin red.
- `ElectronicInvoicingFoundationTest::test_signed_invoice_and_zip_pass_through_real_gateway_with_mocked_sunat_transport`: aprobado dentro de la clase completa; construye por servicios reales e inspecciona el ZIP de `dummy/` + XML, `fileName`, `contentFile` decodificado, SHA-256, identidad, total, esquema y firma. Transporte y CDR son simulados; no se contactó SUNAT.
- `DirectSunatGatewayTransportTest`: **3 / 41**, aprobado; WS-Security, SOAPAction, Fault `Client.0160` sanitizado y respuestas CDR aceptada, observada, rechazada y no concluyente.
- Regresiones complementarias `ElectronicInvoicingFrontendTest`, `ElectronicInvoiceCredentialServiceTest`, `SunatUblInvoiceBuilderTest` y `SunatXmlSignatureServiceTest`: **8 / 118**, aprobadas.
- `ElectronicInvoiceCredentialServiceTest`: **2 / 21**; `ElectronicInvoicingMigrationTest`: **2 / 25**; `OrderTaxCheckoutTest`: **5 / 61**; `OrderTaxSnapshotsMigrationTest`: **2 / 29**; `ElectronicInvoicingFrontendTest`: **1 / 34**; `SunatXmlSignatureServiceTest`: **1 / 8**. Todas aprobadas.
- Las suites SQLite se ejecutaron con `phpunit.xml`, que fuerza `APP_ENV=testing`, `DB_CONNECTION=sqlite` y `DB_DATABASE=:memory:`. Se usó temporalmente `OPENSSL_CONF` solo en los procesos de prueba. No se abrió ni modificó la base normal.
- `MySqlElectronicDocumentSeriesConcurrencyTest` sí se ejecutó con configuración PHPUnit temporal dirigida únicamente a `lubristore_php85_laravel13_qa_20261121_a91c`, después de verificar destino, MySQL 8.4.3 e InnoDB y aplicar el esquema aislado. Pasó **2 / 39**. No se ejecutó `phpunit.mysql.xml` directamente porque todavía apunta a `lubristore_remote_payments_test`, que está excluida; tampoco se usó la base normal. El archivo temporal PHPUnit se retiró al finalizar.

La validación local del XML, firma, XSD, ZIP, PDF y concurrencia MySQL se ejecutó con material sintético; el transporte fue simulado y no se contactó SUNAT. La prueba focalizada más reciente de `SunatUblInvoiceBuilderTest` fue **4 / 55**, aprobada. Pendiente: envío Beta controlado autorizado, respuesta/consulta SOAP real y fallos físicos de proceso/worker alrededor de llamadas remotas. La descarga del ZIP oficial, su árbol completo y la inspección visual del PDF de prueba están resueltos.

## Migraciones para la instalación local (propietario)

Revisar backup y destino antes de aplicar. Estas son únicamente las migraciones de soporte directo y outbox; no se ejecutaron en la base normal:

```bash
php artisan migrate --path=database/migrations/2026_11_19_071200_add_direct_sunat_emission_support.php
php artisan migrate --path=database/migrations/2026_11_19_071300_create_electronic_document_outbox_table.php
```

No habilitar `ELECTRONIC_INVOICING_ENABLED` para uso operativo hasta configurar XSD SUNAT, probar el transporte beta con datos sintéticos y completar la validación de recuperación/consulta.

## Recorrido de Administración y Facturación

El acceso a **Facturación** está limitado por `can_access_billing` a administradores activos y usuarios activos con rol Facturación. La configuración técnica y comercial del módulo continúa reservada a Administración. Otros roles y cuentas inactivas no obtienen acceso por URL ni por API. Con `ELECTRONIC_INVOICING_ENABLED=false`, el listado, los pedidos, los snapshots y los documentos históricos siguen siendo consultables; preparar, corregir, confirmar o consultar mediante acciones de escritura queda bloqueado por el backend.

La bandeja conserva la paginación y los filtros de búsqueda, tipo solicitado, fechas y datos faltantes. Distingue **Solicitud del cliente** (solo expresa qué comprobante pidió el cliente) de **Documento electrónico** (borrador o documento con estado de procesamiento). Una solicitud no se etiqueta como emisión. El detalle muestra el pedido, pago, receptor, líneas históricas, snapshots de emisor/receptor, afectaciones e importes tributarios, datos faltantes y procedencia. Las correcciones usan el número de versión; ante conflicto, la persona puede cargar la versión actual y sus cambios locales permanecen visibles para revisión antes de guardar.

Estados documentales presentados desde la fuente común `App\Support\ElectronicDocumentStatus`: `draft` = borrador no emitido; `queued` = pendiente de procesamiento; `processing` = procesando; `submitted` = enviado con resultado pendiente; `uncertain` = resultado incierto que requiere revisión manual; `accepted` y `accepted_observed` = aceptado según CDR en ambiente de pruebas; `rejected` = rechazado por SUNAT pruebas; `sunat_exception` = excepción SOAP con numeración conservada. Un HTTP 200 no equivale a aceptación: la interfaz usa el estado del documento persistido a partir de la respuesta SOAP/CDR y muestra código y mensaje sanitizado cuando existen. La serie-correlativo permanece visible después de confirmar.

El PDF solo está disponible para facturas de prueba con resultado final; es una representación de prueba, no un comprobante tributario. XML, ZIP y CDR aparecen únicamente si sus archivos existen y la descarga pasa por el middleware de Facturación y el almacenamiento privado. No se inventa una CDR. Las boletas pueden consultarse y preparar su borrador, pero la interfaz informa que emisión, resumen diario y ticket aún no están implementados, y no presenta una acción para emitir boletas. SUNAT Beta no ofrece consulta de estado para este `sendBill` síncrono: no se ofrece esa acción en la pantalla y el endpoint no registra una falsa consulta. No se ofrecen reenvíos ni ediciones para documentos que ya tienen intento.

**Limitación operativa vigente:** las pruebas locales del transporte/XML/ZIP no resolvieron el `Client.0160 — Validation File size error` recibido en Beta para el documento QA 3. No iniciar nuevos envíos Beta hasta obtener diagnóstico externo. Los documentos QA 2 y 3 y sus numeraciones permanecen históricos e intactos. La UI y el gateway no deben interpretarse como autorización para producción.

Validación automatizada del cierre de interfaz: pruebas de permisos y flujo `ElectronicInvoicingFoundationTest`, `ReceiptBillingTest` y `ElectronicInvoicingFrontendTest`; build Vue requerido tras cambios de `BillingInbox.vue`. La revisión visual de escritorio/móvil queda pendiente si no se dispone de una sesión QA identificable y autorizada; no se ejecutan operaciones de emisión durante esa revisión.
