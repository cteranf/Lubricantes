# Cierre técnico — PHP 8.5, Laravel 13 y facturación electrónica

Fecha de validación: 2026-11-22. Rama `feature/electronic-invoicing-phase-3`, HEAD de partida `96c789fb29b445de40eac80b381fc9dd501c060c`.

## Estado e inventario

Se conservaron los cambios locales existentes y se añadieron únicamente correcciones documentadas en este cierre. No se hizo staging, commit, push, merge, despliegue ni migración sobre la base normal. No se encontraron archivos `.env`, certificados, claves, logs ni artefactos privados de transmisión entre las rutas versionadas o nuevas del inventario. `phpunit.xml` se restauró byte por byte después de las corridas MySQL; no quedó configuración temporal.

El inventario previo a este documento tenía 97 rutas modificadas y 218 nuevas; con este documento hay 97 modificadas y 219 nuevas, sin rutas staged. El conjunto incluye:

- **Actualización PHP/Laravel:** `composer.json`, `composer.lock`, configuración de plataforma y cambios acumulados de compatibilidad.
- **Facturación:** controladores, Resources, modelos, servicios, job, comando de recuperación, rutas, migraciones `071200` y `071300`, interfaz y pruebas.
- **Bundle SUNAT:** payload upstream completo bajo `resources/sunat/xsd/Archivos XSD/`, incluido `2.0`, `2.1`, dependencias relativas, XSL y XML. Hay 105 archivos nuevos bajo ese directorio y un README versionado; origen, SHA-256, entrypoint y alcance se detallan en `resources/sunat/xsd/README.md`.
- **Fixtures OASIS:** bundle separado bajo `tests/Fixtures/OasisUBL21/`; solo para pruebas. No es el bundle SUNAT.
- **Documentación:** actualización PHP/Laravel, fases de facturación, auditoría estructurada de Composer y este cierre.
- **Cambios ajenos o preexistentes:** el lote acumulado incluye modificaciones anteriores de pagos, reservas, inventario, catálogo, SEO, navegación y sus pruebas. No se descartaron ni reubicaron. Revisar las 97 rutas modificadas y 218 nuevas como un lote amplio antes de agrupar commits.

La separación exacta puede consultarse con `git status --short --untracked-files=all`: 97 rutas modificadas, 219 sin seguimiento, 0 staged. Dentro de las 219 nuevas hay 105 archivos del bundle SUNAT, 80 fixtures OASIS y 4 documentos bajo `docs/`; las categorías no son aditivas porque, por ejemplo, tests y fixtures comparten `tests/`.

## Correcciones de este cierre

1. La confirmación de factura bloquea primero el pedido y luego el documento y sus documentos hermanos. Si dos borradores distintos para una misma venta compiten, solo el primero puede asignar número y crear intento; el siguiente ve el intento/documento no borrador y recibe conflicto. Número, snapshots, intento, historial y outbox se escriben juntos.
2. Los retries de reasignación de Tesorería y apertura de resolución ya no fijan una instantánea MySQL `REPEATABLE READ` antes de esperar el lock de `orders`. La lectura inicial del `order_id` ocurre fuera de la transacción; `orders` sigue siendo el primer lock y las filas financieras se revalidan bajo los locks existentes.
3. Fixtures MySQL de expiración ahora representan comprobantes rechazados cuando esperan liberar reservas. Cuando el comprobante está en revisión, el contrato correcto es diferir la expiración y conservar la reserva activa.
4. La guardia de pruebas acepta únicamente los tres valores de credenciales sintéticas allowlisted. El worker físico de pagos recibe esas credenciales de prueba antes de instalar el transporte interceptado; no se emplean credenciales externas ni se hacen cobros.
5. El test helper de certificados sintéticos usa un `openssl.cnf` temporal por operación y lo pasa explícitamente a OpenSSL. No configura `OPENSSL_CONF` globalmente ni requiere cambiar el PHP u OpenSSL de otros proyectos.
6. Las rutas relativas del bundle XSD se resuelven desde la raíz de la aplicación. Se actualizó el README para que coincida con la configuración portable.

## Seguridad y comportamiento revisados

- Rutas de lectura/preparación/corrección/confirmación siguen las autorizaciones activas de Administración y Facturación; otros roles no reciben permisos por alterar URLs. La escritura definitiva vuelve a validar en backend.
- Preparación y corrección conservan idempotencia y versión. Las respuestas incluyen readiness vigente; si falta readiness, la interfaz mantiene la confirmación deshabilitada y el backend la rechaza.
- El candado del pedido evita dos emisiones para la misma venta incluso si hay dos borradores. El documento aceptado, observado, rechazado o con excepción terminal no se reescribe por el worker.
- Numeración e intento quedan retenidos ante resultado incierto. No se reenvía automáticamente. El outbox durable recupera la ventana entre commit y publicación; el publicador exige la conexión de cola `database` en la misma base y el scheduler vuelve a publicar filas pendientes.
- El worker tiene `tries=1`; interrupciones prolongadas pasan a `uncertain`, conservan el número y exigen consulta o revisión. No se habilitó `getStatusCdr` en Beta.
- XML, ZIP, CDR, respuestas y diagnósticos usan almacenamiento privado y límites de lectura. El SOAP sanitizado omite credenciales; los logs habituales registran identificadores internos mínimos/códigos seguros, no cuerpos SOAP ni claves. Los artefactos QA existentes permanecen privados y no se incluyeron en Git.
- El módulo sigue controlado por configuración de despliegue, no por el perfil del negocio. Deshabilitado: lectura histórica autorizada continúa disponible; preparaciones, cambios y confirmaciones se bloquean.
- El bundle instalado es el ZIP SUNAT enlazado por su guía, SHA-256 `5CAC9D9353521340FBC15D23F465A4946B004535B9FF411541C87DA01694DBD5`. Su entrypoint `UBL-Invoice-2.1.xsd` importa UBL estructural; pasar XSD no prueba todas las reglas tributarias ni aceptación SUNAT.

## Configuración reproducible

Variables de entorno exactas, con valores seguros:

```dotenv
ELECTRONIC_INVOICING_ENABLED=false
SUNAT_UBL_XSD_DIRECTORY=resources/sunat/xsd
SUNAT_UBL_INVOICE_XSD="resources/sunat/xsd/Archivos XSD/2.1/maindoc/UBL-Invoice-2.1.xsd"
```

- `ELECTRONIC_INVOICING_ENABLED`: opcional; predeterminado `false`; solo habilitación operativa deliberada.
- `SUNAT_UBL_XSD_DIRECTORY`: opcional; predeterminado `resources/sunat/xsd`; se resuelve desde la raíz del proyecto.
- `SUNAT_UBL_INVOICE_XSD`: opcional y sin valor predeterminado. Si falta, el readiness bloquea confirmación; configurarlo solo después de distribuir el bundle SUNAT completo.
- No se requiere variable `OPENSSL_CONF` para la aplicación. El helper de certificados sintéticos crea su configuración temporal de OpenSSL para pruebas.
- No se añadió una variable de cola electrónica: el publisher fuerza la conexión Laravel `database` y nombre de cola `electronic-invoices`. Esa conexión usa la base configurada de la aplicación para conservar atomicidad. `QUEUE_CONNECTION=sync` puede seguir siendo el default general: no sustituye el worker de facturación.
- Sin nuevas variables de almacenamiento; los artefactos de documentos usan el disco privado configurado por Laravel.

Extensiones verificadas en PHP CLI: `curl`, `dom`, `fileinfo`, `openssl`, `pdo_mysql`, `zip` y XML/libxml; `zip` está habilitada (Zip 1.22.8, libzip 1.12.1). Composer confirma los requisitos de plataforma.

Operación requerida, no ejecutada en despliegue:

```powershell
php artisan queue:work database --queue=electronic-invoices --sleep=3 --tries=1 --timeout=120
php artisan schedule:work
```

En producción, ejecutar un scheduler Laravel cada minuto; él invoca `electronic-invoices:recover-processing`, que recupera outbox y marca workers interrumpidos como inciertos. Mantener un worker supervisado escuchando `electronic-invoices`; no usar `sync` para procesar esa cola.

### Runner reproducible de concurrencia MySQL

`RemotePaymentPhysicalConcurrencyTest` valida credenciales en el proceso PHPUnit y crea workers hijos con `proc_open`. Al correr con `phpunit.mysql.xml`, el XML debe incluir los tres valores sintéticos allowlisted; definirlos solo en la shell no es suficiente si la configuración PHPUnit los fuerza a vacío. Los workers reciben además explícitamente esos mismos valores desde `workerEnvironment()`. Ejecutar con el PHP CLI y `php.ini` del runtime que se valida, apuntando exclusivamente a un esquema local `_test`; preservar y restaurar el `phpunit.xml` raíz byte por byte:

```powershell
$db = 'NOMBRE_LOCAL_DEDICADO_test'
$php = 'C:\laragon\bin\php\php-8.5.13-Win32-vs17-x64\php.exe'
$ini = 'C:\laragon\bin\php\php-8.5.13-Win32-vs17-x64\php.ini'
$target = Join-Path $PWD 'phpunit.xml'
$backup = [IO.File]::ReadAllBytes($target)
$xml = [IO.File]::ReadAllText((Join-Path $PWD 'phpunit.mysql.xml')) `
  .Replace('lubristore_remote_payments_test', $db) `
  .Replace('name="MERCADOPAGO_PUBLIC_KEY" value=""', 'name="MERCADOPAGO_PUBLIC_KEY" value="TEST-SYNTHETIC-PUBLIC-KEY"') `
  .Replace('name="MERCADOPAGO_ACCESS_TOKEN" value=""', 'name="MERCADOPAGO_ACCESS_TOKEN" value="TEST-SYNTHETIC-ACCESS-TOKEN"') `
  .Replace('name="MERCADOPAGO_WEBHOOK_SECRET" value=""', 'name="MERCADOPAGO_WEBHOOK_SECRET" value="synthetic-local-webhook-secret"')
try {
  [IO.File]::WriteAllText($target, $xml, [Text.UTF8Encoding]::new($false))
  $env:LARAVEL13_MYSQL_TEST_DATABASE = $db
  $env:MOCK_PAYMENT_PHYSICAL_CERT = '1'
  $env:MERCADOPAGO_ACCESS_TOKEN = 'TEST-SYNTHETIC-ACCESS-TOKEN'
  $env:MERCADOPAGO_PUBLIC_KEY = 'TEST-SYNTHETIC-PUBLIC-KEY'
  $env:MERCADOPAGO_WEBHOOK_SECRET = 'synthetic-local-webhook-secret'
  & $php -c $ini vendor/bin/phpunit --group=mysql tests/Feature --stop-on-failure --stop-on-error --do-not-cache-result
  if ($LASTEXITCODE -ne 0) { throw "PHPUnit exit $LASTEXITCODE" }
} finally {
  [IO.File]::WriteAllBytes($target, $backup)
}
```

En este equipo, el fallo de bootstrap solo se reprodujo al ejecutar PHPUnit dentro del sandbox: `is_readable()` devolvía falso para `vendor/autoload.php`, aunque el archivo existía, Windows ACL permitía lectura/modificación y `require` directo funcionaba. El PHP 8.5.13 efectivo ejecutado fuera del sandbox cargó el bootstrap estándar de `phpunit.xml` correctamente. No se cambió el bootstrap ni se relajó la guardia; el runner final usó el XML habitual restaurado al salir.

## Migraciones y propietario

No se ejecutaron migraciones en la base local normal. En la base QA aislada `lubristore_php85_laravel13_closure_20261122_a91c_test` se verificaron MySQL 8.4.3, InnoDB y destino efectivo Laravel antes de migrar; se aplicó allí el conjunto completo.

Orden de las migraciones electrónicas (después de las migraciones previas de la aplicación):

1. `2026_11_19_070000_add_receipt_settings_and_order_request_snapshots.php`
2. `2026_11_19_071000_create_electronic_invoice_foundation_tables.php`
3. `2026_11_19_071100_create_business_tax_configurations_table.php`
4. `2026_11_19_071110_add_tax_snapshots_to_orders_and_order_items.php`
5. `2026_11_19_071200_add_direct_sunat_emission_support.php`
6. `2026_11_19_071300_create_electronic_document_outbox_table.php`

Para una instalación local, primero respaldar base y archivos, revisar `php artisan migrate:status`, configurar XSD privado y luego ejecutar `php artisan migrate --force`. No se recomienda migrar solo la última etapa si existen fases anteriores pendientes.

Reversión controlada: deshabilitar el módulo y detener worker/scheduler; respaldar la base y exportar documentos/artefactos; ejecutar `migrate:rollback --path=database/migrations/<archivo>` en orden inverso, solo tras comprobar dependencias y retención legal. `071300` revierte outbox; `071200` elimina soporte de emisión; `071110` elimina snapshots tributarios y `071100` su configuración; `071000` elimina tablas fundacionales. Estas operaciones pueden retirar datos; no ejecutar a ciegas ni en producción. No se probó rollback de todos los datos locales en este cierre.

## Validación ejecutada

- PHP CLI 8.5.13 (`C:\laragon\bin\php\php-8.5.13-Win32-vs17-x64\php.exe`); Laravel Framework 13.43.0; Composer 2.11.1; MySQL QA 8.4.3, InnoDB.
- PHPUnit general después de las correcciones: 2,920 tests, 28,951 assertions, 60 skipped, exit 0.
- Grupo MySQL (`--group=mysql tests/Feature`) en el esquema desechable: 59 tests, 17,766 assertions, exit 0, incluida la repetición final con el runner corregido. Incluye suites MySQL de facturación, checkout/pagos, reservas, inventario y tesorería. La concurrencia física de pagos observó 40 pares de locks webhook y 10 pares de segundo pago, sin deadlocks SQL; reintentos e idempotencia verificaron el estado persistido.
- `composer validate --no-check-publish`: válido con advertencia por versión exacta `barryvdh/laravel-dompdf` 3.1.2.
- `composer check-platform-reqs`: aprobado.
- `composer audit --locked` y `composer audit --locked --no-dev`: cero advisories reportados.
- Pint aplicado al inventario PHP del cierre; después `php -l` en 119 PHP y `pint --test` en esos 119: aprobados. Pint necesitó autorización de escritura fuera del sandbox porque la protección del workspace bloqueó archivos con ACL modificable; los archivos no tenían atributo de solo lectura. Una segunda pasada corrigió formato en `TreasuryPaymentResolutionService.php` y `tests/Feature/Concerns/SyntheticMercadoPagoLink.php`.
- Después de esa pasada de formato, `SyntheticMercadoPagoDatabaseGuardTest` aprobó (44/54). La primera repetición incompleta del grupo tuvo 58 pruebas aprobadas y se detuvo porque el runner no había propagado las credenciales sintéticas al proceso principal; el preflight rechazó el token vacío antes de la carrera. Se corrigió el runner, sin debilitar las guardas. `RemotePaymentPhysicalConcurrencyTest` pasó finalmente (2/4,308): 40 carreras de webhook y 10 de segundo pago, 50 pares de locks observados, sin deadlocks. Luego el grupo MySQL completo pasó (59/17,766). El fallo de bootstrap fue específico de la lectura sandbox de `vendor/autoload.php`; se confirmó con el PHP efectivo y se resolvió ejecutando el mismo PHPUnit fuera del sandbox, sin cambiar el XML de bootstrap.
- `npm run build`: aprobado, 783 módulos. Advertencias no bloqueantes: datos Browserslist desactualizados y un chunk principal >500 kB.
- `git diff --check`: aprobado (exit 0). Git emite avisos LF/CRLF al inspeccionar archivos, no errores de whitespace.

## Pendientes y límites

- SUNAT Beta **no aceptó** los documentos Beta QA IDs 2 y 3. `Client.0160 — Validation File size error` permanece pendiente de diagnóstico externo; no se hicieron nuevas llamadas durante este cierre. Se preservaron documentos, números y evidencia.
- No hay aceptación Beta que permita hablar de autorización de producción. Producción permanece bloqueada.
- Emisión de boletas no implementada. Consultar sus solicitudes sigue permitido según rol; no se ofrece una emisión inexistente.
- XSD no cubre íntegramente reglas del Excel SUNAT, catálogos, cálculos, certificados/identidad, reglas por tipo de operación ni aceptación. La revisión tributaria completa sigue pendiente.
- El PDF de pruebas y el recorrido visual comprobados manualmente en fases anteriores no se repitieron en este cierre. Esta validación se enfocó en suites técnicas/build; revisar visualmente en QA antes de habilitar a operadores.
- La prueba concurrente MySQL real valida carreras de checkout/pagos/reservas y no sustituye una aceptación SUNAT Beta.

## Agrupación sugerida de commits (no ejecutada)

1. Actualización PHP 8.5/Laravel 13 y compatibilidad de plataforma.
2. Esquema/modelo y snapshots tributarios.
3. Núcleo de facturación, XML, firma y validación XSD.
4. Transporte Beta, protección/recuperación durable y PDF.
5. Interfaz, documentación y pruebas de integración.
6. Correcciones/regresiones previas de pagos, reservas e inventario en commits separados por componente.

No separar los XSD de SUNAT de la distribución que habilite la barrera de esquema; los fixtures OASIS se pueden mantener únicamente en el paquete de pruebas.
