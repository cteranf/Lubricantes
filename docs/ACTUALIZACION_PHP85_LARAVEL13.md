# Actualización a PHP 8.5 y Laravel 13

## Estado y alcance

Validación local del lote sobre `feature/electronic-invoicing-phase-3`, partiendo de `96c789fb29b445de40eac80b381fc9dd501c060c`. Se conservaron los cambios locales preexistentes de facturación electrónica. No se aplicaron migraciones a la base local normal, no se contactó a SUNAT ni se hicieron pagos externos. No hubo staging, commit, push ni despliegue.

## Versiones comprobadas

- PHP CLI de validación: **8.5.13**, en `C:\laragon\bin\php\php-8.5.13-Win32-vs17-x64\php.exe`.
- Laravel: **13.43.0**.
- Composer: **2.11.1**.
- PHPUnit: **12.5.45**.
- PHP 8.3.36 permanece instalado en Laragon para rollback. El `php.exe` predeterminado de Laragon no se cambió porque puede ser compartido por otros proyectos.
- Las dependencias actuales requieren PHP `^8.5`; por ello Composer no puede instalar este `composer.lock` bajo PHP 8.3.

PHP 8.5 se validó con el INI local temporal `php85-validation.ini`; no se instaló ni habilitó globalmente como runtime web. Se comprobó que están disponibles `curl`, `fileinfo`, `mbstring`, `exif`, `gd`, `intl`, `openssl`, `pdo_mysql`, `pdo_sqlite`, `soap`, `xsl` y `zip`. Para generar certificados sintéticos OpenSSL necesitó `OPENSSL_CONF=C:\laragon\bin\php\php-8.5.13-Win32-vs17-x64\extras\ssl\openssl.cnf`. El límite temporal de memoria se fijó en 512 MB para las pruebas de procesamiento de imágenes. El runtime web, scheduler y workers no estaban ejecutándose, por lo que su binario activo queda pendiente de verificación y cambio manual en Laragon.

## Dependencias

Versiones del lock anterior y actual:

| Paquete | Anterior | Actual |
|---|---:|---:|
| `laravel/framework` | 9.52.21 | 13.43.0 |
| `laravel/sanctum` | 3.3.3 | 4.5.0 |
| `laravel/tinker` | 2.11.0 | 3.2.0 |
| `phpunit/phpunit` | 9.6.31 | 12.5.45 |
| `nunomaduro/collision` | 6.4.0 | 8.9.6 |
| `spatie/laravel-ignition` | 1.7.0 | 2.13.0 |
| `laravel/sail` | 1.52.0 | 1.71.0 |
| `barryvdh/laravel-dompdf` | 3.1.2 | 3.1.2 |
| `robrichards/xmlseclibs` | 4.0.2 | 4.0.2 |

DomPDF y XMLSecLibs ya pertenecían al trabajo local de facturación electrónica y se mantuvieron. `composer validate --no-check-publish` y `composer check-platform-reqs` pasaron. Validate conserva el warning de pin exacto `barryvdh/laravel-dompdf:3.1.2`; no se modificó porque forma parte del lote previo y evita actualizar una dependencia ajena.

`composer audit --locked` y `composer audit --locked --no-dev` finalizaron sin advisories. La captura previa al upgrade del lote local registró 42 avisos en 13 paquetes (36 de producción); no se encontraron avisos nuevos en la auditoría final. `composer.json` conserva el ignore preexistente `PKSA-8qx3-n5y5-vvnd`; el Laravel instalado está fuera del rango afectado. El detalle estructurado y las notas de Fase 3 permanecen en `FACTURACION_ELECTRONICA_FASE_3_COMPOSER_AUDIT.json` y `FACTURACION_ELECTRONICA_FASE_3.md`.

## Adaptaciones aplicadas

- Se actualizaron Laravel, Sanctum, Tinker, PHPUnit y herramientas de desarrollo a versiones compatibles con PHP 8.5/Laravel 13.
- Se migraron providers de PHPUnit 9 a atributos `#[DataProvider]` de PHPUnit 12, y los grupos MySQL a `#[Group('mysql')]`; se añadieron providers que faltaban y que el runner anterior no ejecutaba.
- Se actualizó la firma de excepciones QueryException en fixtures de Laravel 13.
- Se corrigieron compatibilidades de SQLite en una migración histórica de estados y de `unsignedDecimal()` en el esquema de tarifas.
- Se adaptaron firmas de `AppendOnlyAuditLogBuilder` a los métodos de Eloquent 13.
- Se configuró PHPUnit para forzar SQLite `:memory:`, `APP_DEBUG=false` y credenciales externas vacías de Mercado Pago. Los guards sintéticos permanecen activos.
- Se corrigió el template del sitemap: la declaración `<?xml ...?>` literal se compilaba como apertura PHP y producía ParseError en Laravel 13. El template ahora emite esa declaración desde un bloque Blade.
- Se actualizaron fixtures/assertions obsoletos para verificar el contrato de registro con verificación de correo y permitir múltiples abonos por pedido preservando unicidad por operación/idempotencia.
- Los fixtures de expiración de reservas ahora vencen antes de crear un comprobante en revisión, respetando la regla vigente de no liberar reservas con evidencia pendiente.
- Las pruebas de retry sintético usan SQLite en memoria sin transacción exterior para alcanzar el loop top-level que Laravel 13 no reintenta desde un savepoint.
- Las guardas de primitivas de inventario prueban la ausencia de transacción con una segunda conexión SQLite temporal, sin transacción externa.
- Un stub de Dispatcher elimina el único PHPUnit Notice del test suite.

No se cambiaron reglas de pagos, reservas ni autorización para hacer pasar pruebas; los ajustes de producción se limitaron a compatibilidad de framework/PHP y al error reproducible del sitemap.

## Validación de bases

### SQLite

`phpunit.xml` fuerza `DB_CONNECTION=sqlite` y `DB_DATABASE=:memory:`; además vacía las credenciales de Mercado Pago. La suite completa aprobada bajo PHP 8.5 fue **2,909 tests, 28,786 assertions, 59 omitidos** (clases realmente exclusivas de MySQL), con `--fail-on-phpunit-notice` y cero Notices. Reporte local: `storage/framework/testing/php85-suite-sqlite-final.xml`.

### MySQL/InnoDB

La conexión antigua `phpunit.mysql.xml` apunta a `lubristore_remote_payments_test`; se inspeccionó solo en lectura y **no** se migró ni modificó. Para validar se creó la base aislada y desechable `lubristore_php85_laravel13_20261120_a7f3`. Antes de migrar, `SELECT DATABASE()` confirmó el nombre exacto y MySQL **8.4.3**, con InnoDB como motor predeterminado. En ese esquema se ejecutó `migrate:fresh` (91 migraciones) y la prueba de series/documentos con dos conexiones pasó: **2 tests, 39 assertions**. PHPUnit se aisló mediante un XML temporal `phpunit.php85.mysql.xml` y `php85-validation.ini`; ambos archivos temporales se retiraron al finalizar.

El primer reintento detectó un defecto del arnés: los procesos PHP hijos no heredaban el `php.ini` usado por PHPUnit, por lo que no publicaban su señal `worker.ready`. La prueba ahora pasa explícitamente el INI activo a los dos workers, manteniendo las conexiones independientes y el mismo orden de locks. Tras el ajuste, la prueba focalizada de asignación pasó (**1 test, 17 assertions**) y la clase completa volvió a pasar (**2 tests, 39 assertions**).

La base aislada permanece para revisión/limpieza manual. No se ejecutó rollback general ni se borró la base. La validación completa de concurrencia de emisión de Fase 3 y de reintentos/caídas de workers sigue siendo independiente de este upgrade y continúa pendiente según `FACTURACION_ELECTRONICA_FASE_3.md`.

## Build

`npm run build` aprobó con Vite 4.5.14 y 783 módulos. Permanecen advertencias conocidas: base `caniuse-lite` desactualizada (11 meses) y chunk `app` mayor de 500 kB (1.52 MB). No se actualizaron dependencias JS fuera del alcance.

## PHP y Laravel: guías de migración

Se contrastó el recorrido con las guías oficiales, aplicando las etapas Laravel 10–13 y las notas de PHP 8.4/8.5:

- [Laravel 10 upgrade guide](https://laravel.com/docs/10.x/upgrade)
- [Laravel 11 upgrade guide](https://laravel.com/docs/11.x/upgrade)
- [Laravel 12 upgrade guide](https://laravel.com/docs/12.x/upgrade)
- [Laravel 13 upgrade guide](https://laravel.com/docs/13.x/upgrade)
- [PHP 8.4 migration guide](https://www.php.net/manual/en/migration84.php)
- [PHP 8.5 migration guide](https://www.php.net/manual/en/migration85.php)

## Aplicación local pendiente

El propietario debe seleccionar PHP 8.5 para este proyecto en Laragon y configurar las extensiones requeridas en el `php.ini` propio de PHP 8.5. Después, en una ventana de mantenimiento y tras respaldo:

```powershell
composer install
php artisan optimize:clear
php artisan migrate --force
npm run build
```

El comando de migración anterior es una instrucción para la instalación local, **no se ejecutó** durante esta validación. Antes de usarlo en la instalación, revisar `php artisan migrate:status` y el destino de conexión.

Actualizar también el PHP utilizado por Laragon para web y procesos queue/scheduler y reiniciarlos; validar `PHP_VERSION` desde la aplicación web, CLI y cada proceso. El rollback del runtime es volver a seleccionar PHP 8.3.36 y restaurar `composer.json`, `composer.lock`, `phpunit.xml` y `config/database.php` desde los snapshots ignorados bajo `storage/framework/testing/php85-upgrade-rollback/`, y después reinstalar dependencias con Composer/PHP 8.3. Ese rollback no debe ejecutarse automáticamente porque los cambios locales incluyen trabajo de Fase 3 que debe conservarse.

## Revisión visual y pendientes

No se identificó una instancia QA/browser activa con runtime web PHP 8.5; no se afirma que la UI haya sido comprobada visualmente en PHP 8.5. Revisar manualmente en una instancia aislada la configuración de facturación electrónica (`/admin/electronic-invoicing`) y la bandeja de Facturación (`/admin/billing`), escritorio y móvil. No usar credenciales de producción ni emitir documentos reales.

Quedan pendientes la revisión visual, el cambio/confirmación del PHP web y workers de Laragon, la validación del XSD oficial SUNAT (la configuración debe permanecer fail-closed hasta disponer del bundle oficial), y los escenarios de concurrencia/recuperación de emisión descritos en la documentación de Fase 3. No se ejecutaron migraciones sobre la base local normal, pagos externos, colas, staging, commit, push ni despliegue.
