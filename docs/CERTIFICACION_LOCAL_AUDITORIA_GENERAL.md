# Certificación local de auditoría general

**Fecha de consolidación:** 2026-11-07
**Fases:** A y B
**Estado:** certificadas localmente; staging, commit, push y despliegue pendientes.

## Alcance

Esta certificación cubre la infraestructura de `audit_logs` y la trazabilidad de autenticación, logout y revocación de tokens, administración de usuarios, roles y estados, cambio y restablecimiento de contraseña, perfil, denegaciones protegidas y contexto `request_id`/`correlation_id`.

## Exclusiones

No forman parte de las fases A/B:

- Catálogo, productos, categorías y marcas.
- Sedes, almacenes y logística.
- Visor administrativo y exportación.
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

## Estado final

Las fases A/B están certificadas localmente según la evidencia indicada. Staging, commit, push y despliegue siguen pendientes de autorización. Las fases C–F quedan fuera del alcance.
