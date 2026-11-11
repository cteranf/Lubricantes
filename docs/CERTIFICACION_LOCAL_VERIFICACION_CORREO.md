# Certificación local de verificación de correo

## Objetivo y alcance

Esta implementación verifica el correo de los clientes autorregistrados, de los clientes creados desde la administración y de los clientes existentes cuyo `email_verified_at` todavía es `NULL`. Administradores y tesorería están excluidos de esta verificación.

El registro público no crea una sesión ni devuelve un token. Los nuevos inicios de sesión de clientes pendientes quedan bloqueados hasta verificar el correo. Las sesiones emitidas antes de esta política permanecen activas deliberadamente.

## Confirmación y enlaces

Abrir el enlace firmado mediante `GET` no escribe datos ni completa la verificación. La persona debe pulsar **Confirmar correo**, que envía el `POST` explícito.

La firma vincula el enlace al identificador del usuario y al hash del correo vigente. La expiración configurable se normaliza dentro del intervalo de 1 a 1440 minutos, con valor predeterminado de 60 minutos. La confirmación bloquea la fila, vuelve a validar el rol y el correo actual y es idempotente: repetirla no cambia el timestamp ni duplica la auditoría. Los enlaces anteriores dejan de ser válidos cuando cambia el correo.

La URL firmada no es de un solo uso: puede confirmarse de forma idempotente hasta que expire. La URI firmada podría aparecer en logs de infraestructura; la configuración productiva del proxy y del servidor web debe evitar o redactar query strings.

## Reenvío y procesamiento

El reenvío usa una respuesta uniforme para direcciones inexistentes, pendientes o ya verificadas, con throttling por IP y por una identidad derivada mediante HMAC del correo normalizado. El correo no se usa en texto plano como clave de límite.

El procesamiento se realiza con un job cifrado en la cola `email-verification`. Registro, alta administrativa y cambio de correo despachan el job después del commit. La conexión dedicada debe ser asíncrona; `sync` se rechaza. El mailer dedicado admite `smtp` y `sendmail`; se prohíben `log`, `array`, `resend`, `sync` y failovers con transportes inseguros.

Cambiar el correo de un cliente restablece `email_verified_at` y solicita una nueva verificación. Las sesiones existentes no se revocan. Los eventos de auditoría no incluyen correo, firma, URL, hash, token ni contenido del mensaje.

## Interfaz y mensajes

La pantalla pública permite confirmar explícitamente, solicitar reenvío y consultar estados fijos en español. Después del resultado, reemplaza la URL por una ruta limpia sin identificador, hash, expiración ni firma. No persiste el correo ni muestra mensajes remotos. Incluye controles contra doble envío, respuestas obsoletas y accesibilidad mediante foco y anuncios de estado.

Los mensajes usan plantillas propias de LubriStore en HTML y texto plano. El HTML escapa el nombre y la URL; el texto plano presenta los caracteres literales y conserva exactamente la URL generada. No se incluyen scripts, imágenes externas ni recursos de seguimiento.

## Evidencia local

Los conteos corresponden a ejecuciones separadas; no deben sumarse como una cifra de cobertura independiente.

| Entorno | Suite | Resultado |
| --- | --- | ---: |
| SQLite | `CustomerEmailVerificationTest` | 18 tests / 152 assertions |
| SQLite | `EmailVerificationConfigurationTest` | 14 tests / 65 assertions |
| SQLite | `EmailVerificationFrontendTest` | 15 tests / 122 assertions |
| MySQL local aislado | `CustomerEmailVerificationTest` | 18 tests / 152 assertions |

La prueba frontend es contractual y textual; no existe un harness Vue y no demuestra interacción dinámica completa.

También se aprobaron `npm run build`, Pint focalizado, `php -l` de los PHP afectados y la compilación/limpieza de vistas Blade. La validación visual local mediante Mailpit fue aprobada. Mailpit y `no-reply@lubristore.test` pertenecen únicamente al entorno local; no representan un proveedor ni una dirección productiva.

## Límites de certificación

- La certificación es local. No hubo acceso a producción, push ni despliegue.
- No se probó un proveedor SMTP productivo.
- El enlace es idempotente hasta su expiración y no usa nonce de un solo uso.
- Las sesiones previamente emitidas continúan activas deliberadamente.
- Las pruebas frontend son textuales, sin harness Vue.
- La URI firmada puede aparecer en logs de infraestructura; producción debe redactar query strings.

## Checklist productivo pendiente

- [ ] Configurar `APP_URL` con el dominio HTTPS real.
- [ ] Configurar un remitente productivo y SMTP autenticado real.
- [ ] Publicar y verificar SPF, DKIM y DMARC.
- [ ] Configurar para la cola dedicada una conexión `database` o equivalente, nunca `sync`.
- [ ] Mantener un worker/Supervisor activo para `email-verification`.
- [ ] Ejecutar `config:cache`, reiniciar workers y comprobar que existe la tabla `jobs`.
- [ ] Verificar que ningún mailer efectivo use `log` y que los failovers sean seguros.
- [ ] Construir los assets del frontend.
- [ ] Realizar una prueba controlada de alta, entrega, confirmación y login en el entorno correspondiente.

La habilitación productiva requiere completar y verificar este checklist en el entorno objetivo.
