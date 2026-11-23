# Facturación electrónica — Fase 4: boletas

## Alcance y límite operativo

Esta fase genera boletas individuales y prepara resúmenes diarios en QA local. El transporte externo para resúmenes se implementa en la Fase 5 descrita en [FACTURACION_ELECTRONICA_FASE_5_RESUMENES_BETA.md](FACTURACION_ELECTRONICA_FASE_5_RESUMENES_BETA.md). La implementación de Fase 5 no transmite por sí sola: el adaptador Beta permanece deshabilitado por defecto. Una respuesta local simulada nunca equivale a un ticket, aceptación o CDR auténticos. La aceptación de XSD tampoco demuestra cumplimiento tributario integral. No se habilita producción.

Fuentes oficiales revisadas:

- [SUNAT: boleta de venta electrónica](https://cpe.sunat.gob.pe/tipos_de_comprobantes/boleta)
- [SUNAT: SEE del contribuyente](https://cpe.sunat.gob.pe/sistema_emision/see_contribuyente)
- [SUNAT: guías y manuales](https://cpe.sunat.gob.pe/guias-y-manuales)
- [Manual del programador SUNAT](https://cpe.sunat.gob.pe/sites/default/files/inline-files/manual_programador%20%281%29.pdf)

El bundle oficial instalado para el resumen se configura con `SUNAT_UBL_SUMMARY_XSD`. La ruta del ejemplo es `resources/sunat/xsd/Archivos XSD/2.0/maindoc/UBLPE-SummaryDocuments-1.0.xsd`; `SunatSchemaBundle` resuelve y valida también sus imports/includes relativos. El XML de resumen usa UBLPE 2.0, `CustomizationID` 1.1. Este bundle no es el paquete OASIS UBL 2.1 de facturas. XSD cubre estructura, no todas las reglas comerciales, tributarias ni de comunicación.

El manual oficial documenta conceptualmente `sendSummary`, que devuelve un ticket, y `getStatus`, que consulta el procesamiento. Esta fase solo implementa sus límites funcionales mediante un contrato de transporte y un adaptador sintético: no ejecuta esas operaciones SOAP.

## Boleta individual: generación y congelamiento

La acción «Generar boleta de prueba» opera sobre el borrador preparado. Bajo una transacción y locks de pedido/documento, valida propietario lógico, versión, elegibilidad, configuración de pruebas, certificado privado, ZIP y bundle XSD antes de reservar el correlativo `B001`. La asignación de número, `confirmed_at` y congelamiento de snapshots quedan en la misma transacción que la firma, validación serializada, guardado privado de XML/ZIP y cambio a `ready_for_summary`. Si la operación falla, la transacción revierte el número/documento y se limpian los archivos que alcanzó a crear. Una repetición sobre una boleta ya generada devuelve el documento existente; no asigna otro número ni crea otro evento de emisión.

El XML firmado se conserva en el disco privado, se verifica su firma serializada y se valida con el XSD de factura UBL 2.1 configurado. El PDF disponible en pruebas deriva de los snapshots congelados; muestra identificación de simulación en todas las páginas. Las descargas requieren autorización de Administración/Facturación y usan nombres y encabezados que lo identifican como simulación. No existe CDR individual inventada.

Numerar/generar es distinto de incluir y comunicar. La inclusión ocurre después, al confirmar el resumen: bloquea pedidos y documentos, exige boletas ya firmadas y `ready_for_summary`, valida versión y snapshots, asigna correlativo `RC`, congela la selección y crea ítems, intento y outbox en la transacción. La restricción única de ítems y las verificaciones bajo locks impiden que una venta figure en dos resúmenes. El proceso local no altera pedido, pago, reserva ni inventario.

## Transporte y estados del resumen

`ElectronicReceiptSummaryTransport` define `sendSummary` y `query`. En `testing`/`local`, `LocalSimulatedElectronicReceiptSummaryTransport` entrega escenarios controlados desde `ELECTRONIC_RECEIPT_SIMULATION_SCENARIO` (predeterminado seguro: `pending`): ticket local pendiente, aceptación, aceptación con observaciones, rechazo, SOAP Fault, error HTTP, timeout antes/después del identificador, respuesta duplicada y respuesta posterior. En otros entornos se enlaza `UnavailableElectronicReceiptSummaryTransport`; nunca se selecciona accidentalmente el simulador como transporte externo.

Los identificadores `LOCAL-SIM-*` viven en `local_simulation_id`; `external_ticket` permanece separado y nulo. El Resource, el historial, los botones y las descargas indican modo simulado. Los resultados no generan archivo ni ruta de CDR. Estados como `simulated_accepted` son explícitamente sintéticos aunque las boletas relacionadas se proyecten a `reported` para representar la aceptación del escenario; la interfaz siempre acompaña ese estado con el modo de comunicación.

El flujo simulado distingue:

| Resultado simulado | Comunicación | Resumen | Boleta incluida |
| --- | --- | --- | --- |
| Ticket/pending | `ticket_pending` | `simulated_processing_pending` | `summary_pending` |
| Aceptación | `accepted` | `simulated_accepted` | `reported` |
| Aceptación observada | `accepted_observed` | `simulated_accepted_observed` | `reported` |
| Rechazo | `rejected` | `simulated_rejected` | `summary_rejected` |
| SOAP Fault / HTTP | estado de error correspondiente | estado simulado correspondiente | `summary_failed` |
| Timeout ambiguo | `uncertain` | `simulated_timeout_uncertain` | `summary_uncertain` |

La aceptación o rechazo de esta tabla son fixtures, no respuestas de SUNAT. La duplicación idéntica se deduplica por hash. Una respuesta tardía distinta no sobrescribe un estado terminal y queda registrada como evento ignorado. Los errores y resultados ambiguos conservan numeración y evidencia; no se reenvían automáticamente.

## Cola y recuperación

El resumen, intento y outbox durable se confirman juntos. La publicación exige que la cola database use la misma conexión transaccional. El job reclama el par resumen/intento una sola vez, prepara XML firmado/XSD/ZIP en almacenamiento privado y ejecuta únicamente el adaptador simulado. La recuperación de outbox publica solo entradas no publicadas. Un trabajo duplicado no reclama un resumen ya procesado.

Si un worker se interrumpe antes de persistir `LOCAL-SIM`, la recuperación terminal marca la preparación como interrumpida, mantiene documentos y números y no reintenta. Si el identificador local ya se persistió, se restaura el estado pendiente consultable y la boleta deja de estar en cola. La consulta usa únicamente ese identificador sintético. Timeout sin identificador no permite consulta ni reenvío automático.

## Permisos e interfaz

Administración y Facturación activas usan la bandeja y las acciones según autorización existente; otros roles son rechazados por backend aunque manipulen URLs. Con el módulo deshabilitado se conserva la lectura histórica permitida y se bloquean preparación, cambios y consultas que escriben estado. La bandeja diferencia solicitud del cliente, borrador individual y resumen; muestra snapshots, elegibilidad y bloqueos, versión, serie/número, modo de ejecución y comunicación por separado. Solo ofrece acciones soportadas. No permite emitir boletas en producción ni consultar tickets externos.

## Pendientes tributarios y externos

- El envío real `sendSummary`, consulta externa `getStatus`, autenticación, CDR y mapeo de respuestas reales no están habilitados.
- No se afirma conformidad completa con reglas de negocio vigentes ni autorización de producción.
- El tratamiento de anticipos/pagos parciales y los escenarios tributarios que la elegibilidad marque para revisión permanecen bloqueados.
- Boletas individuales se pueden generar y firmar en QA, pero su comunicación se realiza solo mediante el flujo local simulado; no se presentan como emitidas/aceptadas ante SUNAT.
- Client.0160 de pruebas anteriores sigue sin diagnóstico externo. Esta tarea no hizo nuevos envíos ni consultas.

## Migraciones

En instalación normal quedan pendientes, en este orden, las migraciones de la fase de resúmenes y la separación de estados simulados:

```powershell
php artisan migrate --path=database/migrations/2026_11_22_071400_create_electronic_receipt_summaries.php
php artisan migrate --path=database/migrations/2026_11_22_071500_add_electronic_receipt_simulation_state.php
```

No se ejecutaron sobre la base normal. La migración `071500` conserva el valor previo de ticket al separar `LOCAL-SIM` del ticket externo; el rollback solo elimina las columnas nuevas después de devolver el dato local/external al campo legado. La reversión de `071400` está protegida cuando existen resúmenes confirmados.

## Validación ejecutada en esta continuación

El destino MySQL autorizado fue `lubristore_electronic_receipts_phase4_20261122_test`, verificado con `SELECT DATABASE()` y motor InnoDB antes de migrar. MySQL fue 8.4.3. No se usaron `lubricantes` ni `lubristore_remote_payments_test`; no se alteraron pedidos ni documentos Beta QA 2/3.

- `ElectronicReceiptSummaryTest` SQLite: 10 pruebas / 209 assertions; incluye generación idempotente de boleta, escenarios simulados, deduplicación/late response, recuperación con LOCAL-SIM ya persistido, interrupción previa al identificador y ausencia de CDR.
- `ElectronicReceiptSummaryTest` + `MySqlElectronicReceiptSummaryConcurrencyTest`: MySQL 8.4.3/InnoDB aislado, 11 / 282. La prueba con dos conexiones independientes confirmó que selecciones parcialmente coincidentes no duplican inclusión ni dejan escrituras parciales. PHPUnit informó una deprecación.
- `ElectronicInvoicingMigrationTest`: SQLite, 3 / 35; la migración `071500` separa identificador LOCAL-SIM y ticket legado y preserva ambos al revertir/reaplicar.
- `ElectronicInvoicingFoundationTest` + `ReceiptBillingTest`: SQLite, 30 / 372. Se actualizó la expectativa antigua para que un borrador inelegible reciba sus bloqueos reales antes de generar.
- `ElectronicInvoicingFrontendTest`: 2 / 68; contrato de modo simulado, detalle inmediato de boleta y actualización del listado.
- Pint: aplicado a los PHP modificados/nuevos; 7 archivos requirieron formato. `pint --test` pasó después.
- `php -l`: 40 PHP modificados/nuevos sin errores.
- `npm run build`: pasó, 783 módulos. Quedaron los avisos existentes de Browserslist desactualizado y chunk mayor a 500 kB; la hora y la inclusión de las dos vistas finales se detallan debajo.
- `git diff --check`: limpio; Git solo informa avisos LF/CRLF.
- Build: el último `npm run build` aprobó con 783 módulos a las 20:59:11 del 22/11/2026. Los archivos finales `BillingInbox.vue` (20:58:25) y `ElectronicInvoicingSettings.vue` (20:52:56) son anteriores al manifiesto generado, por lo que ambos están incluidos; no fue necesario repetirlo.
- Revisión visual de escritorio y móvil completada en la instancia identificada por URL exacta `http://127.0.0.1:8017/admin/billing` (escritorio y viewport móvil 390 × 844). El endpoint de verificación servido por el mismo proceso confirmó la base `lubristore_electronic_receipts_phase4_20261122_test`, MySQL 8.4.3/InnoDB, `LocalSimulatedElectronicReceiptSummaryTransport`, entorno local y transporte externo deshabilitado (PID 34892). No se interactuó con las ventanas Chrome ambiguas.
- Se usó el pedido sintético existente #000027; no se creó otro durante la revisión. El detalle mostró la solicitud separada del documento, los snapshots, los bloqueos y la numeración `B001-00000003`. Tras preparar el certificado sintético en QA, la boleta fue firmada/validada localmente.
- Descargas revisadas: XML firmado con `ds:Signature`, número `B001-00000003` e importe pagable PEN 118.00; PDF de prueba de una página A4 con aviso tributario visible, emisor/receptor, detalle, base gravada PEN 100.00, impuesto PEN 18.00 y total PEN 118.00. No se observaron recortes ni desbordamientos. El PDF conserva el estado del documento firmado pendiente de resumen; el resultado del resumen se muestra separadamente.
- Los tres resúmenes sintéticos visibles en QA, uno por boleta cada uno, quedaron así:

| Resumen | Pedido / documento | Estado observado en bandeja | Resultado de comunicación |
| --- | --- | --- | --- |
| `RC-20261122-1` | Intento QA previo | Preparando resumen en QA local | Sin resultado; modo simulado local |
| `RC-20261122-2` | Pedido #000026 | Falló la preparación local; no enviado | Sin resultado; modo simulado local |
| `RC-20261122-3` | Pedido #000027 · `B001-00000003` | Aceptación simulada localmente; no es respuesta SUNAT | `LOCAL-SIM-79QXFKOCBWPSLOQL09YW`; no existe ticket externo |

- Para `RC-20261122-3`, la primera consulta devolvió procesamiento simulado pendiente y la siguiente una aceptación simulada. El detalle y el historial distinguen el identificador `LOCAL-SIM`, la comunicación/resultados de simulación y la ausencia de respuesta SUNAT. No se creó CDR. El resultado no constituye aceptación tributaria.
- Configuración segura: `ELECTRONIC_RECEIPT_SIMULATION_SCENARIO=pending` es el predeterminado; `late_response` produce la secuencia pendiente y resultado posterior usada en QA. `SUNAT_UBL_SUMMARY_XSD="resources/sunat/xsd/Archivos XSD/2.0/maindoc/UBLPE-SummaryDocuments-1.0.xsd"` apunta al XSD del resumen instalado. La simulación no habilita el transporte externo.
- Después de importar `ElectronicReceiptSummaryEvent` al job se repitió `ElectronicReceiptSummaryTest`: 10 pruebas / 209 assertions aprobadas. La suite no se repitió de nuevo en esta revisión porque no cambió su código. `ElectronicInvoicingFrontendTest` (2 / 68) y build (783 módulos) también estaban aprobados sobre las vistas finales. `php -l`, `pint --test` del job y `git diff --check` pasaron; Git informó únicamente avisos LF/CRLF.

No se llamó ni transmitió nada a SUNAT, no se ejecutaron migraciones en la base normal y no se modificaron pedidos o documentos Beta QA 2/3. La evidencia visual corresponde exclusivamente a datos sintéticos en la instancia QA indicada. `RC-20261122-1` y `RC-20261122-2` son artefactos de intentos locales previos, no respuestas externas; el primero permanecía en preparación al momento de revisar la bandeja.
