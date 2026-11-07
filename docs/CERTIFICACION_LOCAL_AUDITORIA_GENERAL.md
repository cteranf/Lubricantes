# Certificación local de auditoría general

**Fecha de consolidación:** 2026-11-07
**Fases:** A y B; visor administrativo documentado como extensión separada
**Estado:** fases A/B y visor certificados localmente; staging, commit, push y despliegue pendientes.

## Alcance

Esta certificación cubre la infraestructura de `audit_logs` y la trazabilidad de autenticación, logout y revocación de tokens, administración de usuarios, roles y estados, cambio y restablecimiento de contraseña, perfil, denegaciones protegidas y contexto `request_id`/`correlation_id`.

## Exclusiones

No forman parte de las fases A/B:

- Catálogo, productos, categorías y marcas.
- Sedes, almacenes y logística.
- Exportación.
- Job de retención o purga.
- Segregación física de permisos de base de datos.
- Hash encadenado e inmutabilidad física.
- Producción y despliegue.

## No duplicación de historiales especializados

`audit_logs` no replica los datos de:

- `payment_transactions`;
- `payment_webhook_events`;
- `payment_submission_histories`;
- `payment_resolution_histories`;
- `inventory_movements`;
- `inventory_reservations`;
- `order_delivery_histories`;
- `order_fulfillment_histories`;
- `order_handling_histories`;
- `contact_inquiry_histories`.

Solo se permiten referencias incluidas en allowlists a fuentes especializadas. No se copian importes, estados, payloads, firmas ni metadata operativa.

## Arquitectura

- `AuditEventContract` define las acciones, categorías, resultados, códigos de fallo, tipos de actor y retención admitidos.
- `AuditEventData` es un DTO de construcción cerrada, validado e inmutable, que representa los campos permitidos para persistencia.
- `AuditSanitizer` aplica allowlists por acción, límites de tamaño y formato, y minimiza snapshots y metadata.
- `AuditService` valida y coordina la creación de eventos; reserva el comportamiento fail-open para fallos y denegaciones autorizados por contrato.
- `AuditLog` acepta eventos mediante `AuditEventData` y realiza una inserción canónica sin disparar eventos Eloquent reentrantes.
- `AppendOnlyAuditLogBuilder` bloquea las operaciones de escritura Eloquent cubiertas por el contrato.
- `AuditRequestContext` genera el `request_id` en el servidor, valida el `correlation_id` recibido y restaura el contexto previo de la petición.
- La migración crea `audit_logs` con identificadores de entidad como strings, índices y rollback limitado a esa tabla.
- `config/audit.php` configura la retención por categoría; no implementa una purga automática.

Las allowlists son específicas para acciones y campos. La metadata es plana y limitada. El User-Agent bruto no se persiste; la IP se anonimiza de acuerdo con el contrato implementado. El modelo es append-only para las rutas Eloquent cubiertas, no una garantía física de la base de datos.

## Políticas transaccionales

### Fail-open

- Login fallido.
- Denegaciones de acceso protegidas.

Si falla su escritura de auditoría, se conserva el resultado HTTP original. La identidad intentada en un login fallido no se registra como actor ni como dato de auditoría.

### Fail-closed

- Login exitoso.
- Creación y cambios administrativos de usuarios.
- Roles y estados.
- Cambio y restablecimiento de contraseña.
- Cambios de perfil y demás operaciones administrativas críticas incluidas.

La mutación, las revocaciones que correspondan y su auditoría participan en la misma transacción; un fallo de auditoría aborta la operación crítica.

### Orden de login exitoso

```text
transacción
→ users FOR UPDATE
→ validar identidad, estado y hash de la fila bloqueada
→ crear token
→ registrar auditoría
→ commit
→ responder con el token
```

### Orden de cambio de contraseña

```text
transacción
→ users FOR UPDATE
→ validar el hash de la fila bloqueada
→ guardar contraseña
→ revocar tokens
→ registrar auditoría
→ commit
```

## Privacidad

La auditoría excluye passwords y hashes, `password_confirmation`, tokens, cookies, `Authorization`, API keys, secretos de Webhook, sesiones, payloads de proveedores, comprobantes, archivos, rutas privadas, cuerpos completos de request/response y mensajes originales de excepciones. Tampoco conserva el email completo en login fallido ni el User-Agent bruto.

## Evidencia de pruebas

Los conteos siguientes corresponden a ejecuciones completas comunicadas para esta revisión. Las regresiones focalizadas están incluidas en sus clases completas; no se suman por separado.

| Entorno | Clase | Tests | Assertions |
|---|---|---:|---:|
| Guard MySQL | `SyntheticMercadoPagoDatabaseGuardTest` | 44 | 55 |
| SQLite | `AuditEventDataTest` | 5 | 18 |
| SQLite | `AuditSanitizerTest` | 5 | 19 |
| SQLite | `AuditSecurityTraceTest` (final) | 29 | 398 |
| SQLite | `UserAdministrationTest` | 15 | 54 |
| MySQL aislado | `AuditSecurityTraceTest` (final) | 29 | 401 |
| MySQL aislado | `UserAdministrationTest` | 15 | 54 |

Las ejecuciones MySQL se realizaron manualmente desde PHP 8.3.36 de Laragon porque el proceso ejecutor de Codex no podía abrir el puerto local. El preflight y PDO ejecutados manualmente confirmaron la conexión a la base aislada de pruebas. Esta documentación no atribuye esas ejecuciones a una conexión realizada por Codex.

## Cobertura concurrente

En MySQL se verificó el `SELECT FOR UPDATE` real y el orden SQL usuario → token → auditoría en el login exitoso. También existe una regresión determinista para una instancia de usuario autenticada obsoleta durante el cambio de contraseña.

No se ejecutó una carrera física multiproceso específica para login o cambio de contraseña. Esta es una limitación de cobertura: los resultados no certifican concurrencia física exhaustiva para esos flujos.

## Inmutabilidad y límites

Las operaciones de actualización, eliminación y otras rutas Eloquent cubiertas están bloqueadas por el modelo y su builder. `DB::table`, SQL directo y `toBase()` pueden eludir esa protección. No hay triggers ni privilegios de base de datos que proporcionen inmutabilidad física; por tanto, el término append-only solo describe la capa Eloquent cubierta.

## Entorno y límites de la certificación

- Ejecuciones locales; SQLite en memoria y MySQL 8.4.3 local con base de pruebas terminada en `_test`.
- Credenciales de Mercado Pago ausentes y sin llamadas externas.
- Sin acceso ni modificación de producción y sin despliegue.
- No se ejecutaron migraciones productivas.
- `.env.testing` y `phpunit.mysql.xml` no forman parte del lote Git.

## Revisión estática

La revisión estática final no encontró hallazgos P0–P3. Pint focalizado y `php -l` fueron reportados como aprobados. `git diff --check` fue reportado sin errores; los avisos LF/CRLF son no bloqueantes.

## Despliegue pendiente

Después de la autorización correspondiente para commit, push y despliegue, el procedimiento de producción deberá contemplar:

1. Backup.
2. Verificación de variables de configuración.
3. `php artisan migrate --force`.
4. Limpieza de caché/configuración según el procedimiento del hosting.
5. Prueba controlada de login/logout y administración.
6. Verificación de escritura en `audit_logs`.
7. Monitoreo inicial.

Estos pasos son pendientes y no se presentan como ejecutados.

## Visor administrativo de auditoría

Esta sección documenta la extensión del visor, certificada por separado de los conteos de las fases A/B.

### Alcance funcional

- Ruta frontend: `/admin/auditoria`.
- Endpoints de solo lectura:
  - `GET /api/v1/admin/audit-logs`
  - `GET /api/v1/admin/audit-logs/{auditLog}`
- El acceso exige usuario autenticado, activo y administrador.
- El visor no permite editar, eliminar, exportar ni purgar registros.
- El listado pagina 25 filas por defecto, admite hasta 100 y ordena por `occurred_at DESC, id DESC`.
- Los filtros allowlisted son `date_from`, `date_to`, `action`, `category`, `result`, `actor_type`, `actor_id`, `auditable_type`, `auditable_id`, `request_id` y `correlation_id`.

### Privacidad del visor

- El listado usa una proyección explícita y no expone valores anteriores o nuevos, metadata, IP ni User-Agent.
- El detalle vuelve a sanitizar los datos estructurados.
- La información actual del actor se limita a ID y nombre; no se muestra su email.
- No se muestran contraseñas, tokens, hashes, cookies, encabezados de autorización ni secretos.
- La interfaz no usa `v-html`.

### Auditoría de accesos al visor

Las acciones son `audit.logs.viewed` para el listado y `audit.log.viewed` para el detalle.

- Se registra exactamente un evento por acceso exitoso.
- El evento se escribe después de preparar el snapshot seguro y no aparece en la misma respuesta.
- No se copian filas consultadas ni filtros sensibles al evento.
- Si falla el registro del acceso, no se entregan los datos.
- La escritura no produce recursión.
- Rechazos y validaciones fallidas no se registran como accesos exitosos.

### Correcciones y regresiones del visor

- Las respuestas de detalle fuera de orden se protegen con secuencia e ID seleccionado; los errores obsoletos se ignoran.
- Cerrar y desmontar invalida solicitudes pendientes.
- El diálogo recibe el foco inicial, contiene `Tab` y `Shift+Tab`, cierra con Escape y restaura el foco al disparador.
- No se usan listeners globales ni `v-html`.
- Las pruebas incluyen filas objetivo y señuelo para los 11 filtros, combinaciones de filtros y rechazo de administradores inactivos.
- Los límites diarios se fijaron con cuatro filas sintéticas: día anterior `23:59:59`, día seleccionado `00:00:00` y `23:59:59`, y día siguiente `00:00:00`. Se comprobó el inicio inclusivo, el comienzo exclusivo del día siguiente, el intervalo de un día, IDs concretos, ausencia de ambos señuelos, total exacto y orden descendente.

### Evidencia de pruebas del visor

Estas ejecuciones son bloques separados y se solapan; sus conteos no se suman entre sí ni a las cifras anteriores de las fases A/B.

| Ejecución | Entorno | Tests | Assertions |
|---|---|---:|---:|
| Primera certificación del visor | SQLite | 9 | 140 |
| Primera certificación del visor | MySQL aislado | 9 | 140 |
| Después del hardening P2/P3 | SQLite | 21 | 219 |
| Después del hardening P2/P3 | MySQL aislado | 21 | 219 |
| Certificación final con límites diarios | SQLite | 22 | 236 |
| Certificación final con límites diarios | MySQL aislado | 22 | 236 |

Las ejecuciones se solapan. El conteo final representativo de la clase es SQLite: 22 tests / 236 assertions y MySQL aislado: 22 tests / 236 assertions. Estos resultados no se agregan a los conteos históricos de A/B.

### Frontend y calidad

- `npm run build` fue aprobado; quedaron avisos no bloqueantes de Browserslist desactualizado y tamaño del bundle.
- El proyecto no tiene un harness automatizado frontend configurado. Las carreras de respuesta y la gestión del foco se revisaron estáticamente.
- Pint focalizado, `php -l` para los PHP afectados y `git diff --check` fueron reportados como aprobados.

### Límites de la certificación del visor

- La certificación fue local: SQLite en memoria y MySQL local aislado con base `_test`; no hubo acceso a producción ni llamadas externas.
- No certifica producción ni incluye pruebas automatizadas frontend.
- No existe inmutabilidad física total: SQL directo, `DB::table` y `toBase()` pueden eludir la protección Eloquent.
- El visor no sustituye los historiales especializados. No ofrece exportación, purga automática ni retención ejecutada automáticamente desde la interfaz.
- El primer intento MySQL fue detenido por el guard antes de migraciones o escrituras. Solo las ejecuciones posteriores autorizadas y aprobadas constituyen evidencia.
- Esta actualización documental no despliega nada.

### Despliegue del conjunto completo pendiente

Para desplegar por primera vez el conjunto de auditoría, el procedimiento autorizado deberá incluir:

1. Respaldo de la base de datos.
2. `git pull` del cambio aprobado.
3. Instalación de dependencias conforme al procedimiento del servidor.
4. `php artisan migrate --force` para crear `audit_logs` y aplicar las migraciones pendientes.
5. Limpieza controlada de cachés Laravel.
6. `npm run build` o despliegue de los assets construidos, porque el visor modifica Vue.
7. Verificación de rutas y permisos administrativos.

Si la migración de `audit_logs` ya fue aplicada, no debe ejecutarse manualmente otra vez; Laravel determinará qué migraciones siguen pendientes. No se afirma que haya habido push, despliegue ni migración productiva.

### Revisión estática final del visor

La revisión estática final no encontró hallazgos P0–P3, confirmó el inventario y los límites diarios, y aprobó el visor para actualización documental y staging explícito. Esta aprobación no declara despliegue ni certificación productiva.

## Estado final

Las fases A/B están certificadas localmente según la evidencia indicada. Staging, commit, push y despliegue siguen pendientes de autorización. Las fases C–F quedan fuera del alcance.
