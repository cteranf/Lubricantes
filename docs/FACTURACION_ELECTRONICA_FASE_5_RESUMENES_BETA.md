# Facturación electrónica — Fase 5: transporte Beta de resúmenes

## Alcance y estado

Esta fase añade el adaptador SOAP de `sendSummary` y `getStatus` para resúmenes diarios de boletas, con HTTP interceptado en pruebas. No se hizo ninguna llamada a SUNAT ni se habilitó producción. La Fase 4 conserva `LOCAL-SIM`; el modo de comunicación queda congelado en el resumen (`local_simulation` o `sunat_beta`) y los identificadores locales y tickets externos permanecen en campos separados.

El endpoint Beta está fijado en el código a `https://e-beta.sunat.gob.pe/ol-ti-itcpfegem-beta/billService`. El adaptador exige entorno de aplicación `local` o `testing`, la opción Beta independiente habilitada, configuración comercial en pruebas y un resumen de pruebas. No acepta una URL desde API ni permite que el modo Beta se habilite en producción. El valor predeterminado es deshabilitado.

## Contrato contrastado

Fuentes revisadas:

- [WSDL oficial de Beta](https://e-beta.sunat.gob.pe/ol-ti-itcpfegem-beta/billService?wsdl) y sus imports `?ns1.wsdl` y `billService.xsd2.xsd`.
- [SUNAT: pautas del servicio Beta](https://orientacion.sunat.gob.pe/12-pautas-servicio-beta).
- [Manual del programador SUNAT](https://cpe.sunat.gob.pe/sites/default/files/inline-files/manual_programador%20%281%29.pdf), apartados de envío, resumen diario y consulta de ticket.
- [Anexo oficial CDR](https://www.sunat.gob.pe/legislacion/superin/2017/anexoA-117-2017.pdf).

El WSDL define SOAP 1.1 document/literal y las operaciones `sendSummary` (`SOAPAction: urn:sendSummary`) y `getStatus` (`SOAPAction: urn:getStatus`) en `http://service.sunat.gob.pe`. El transporte envía `text/xml; charset=utf-8` con un encabezado WS-Security `UsernameToken`; `sendSummary` contiene `fileName` y `contentFile` (`base64Binary`) y devuelve un ticket. `getStatus` recibe ese ticket externo y devuelve `statusCode` y, para resultados procesados, contenido binario CDR. La implementación usa Base64 inline conforme al tipo WSDL; el ejemplo MIME/`cid:` del manual antiguo no se interpreta como cambio del tipo formal del WSDL y debe volver a contrastarse en una futura prueba Beta autorizada.

El manual documenta `getStatus`: `98` significa que continúa en proceso; `0` y `99` entregan el resultado procesado y su CDR. `getStatusCdr` es una operación diferente y no se invoca: no está en el WSDL Beta utilizado. HTTP 200 o la recepción de un ticket solo prueban respuesta de transporte/recepción; no son aceptación documental.

La guía Beta vigente indica usuario `[RUC]MODDATOS` y contraseña `MODDATOS`. El manual antiguo contiene una muestra con `moddatos` en minúscula; se conserva la guía Beta específica como referencia operativa. La aplicación prioriza las credenciales ya guardadas cifradas en la configuración de facturación y, si no existen, usa el par público de prueba. No se agregaron variables de entorno para credenciales.

El paquete de resumen se construye como `<RUC>-<document_id>.zip`, con un directorio vacío `dummy/` y un XML firmado `<RUC>-<document_id>.xml`. Antes de cualquier HTTP se verifican rutas privadas esperadas, hashes persistidos, nombre, contenido, firma, XSD y límites. La inclusión de `dummy/` sigue la sección de empaquetado del manual; no se infiere de la validación XSD.

## CDR, seguridad y estados

La respuesta CDR se trata como no confiable. No se extraen archivos al sistema: se lee una única entrada XML permitida desde el ZIP con límites de tamaño, entradas y expansión. El XML se parsea sin red (`LIBXML_NONET`), se rechazan DTD/entidades, se valida con el bundle ApplicationResponse configurado, se verifica la firma y se exige una huella SHA-256 fijada explícitamente. También se comprueba RUC receptor, `ReferenceID`, `DocumentReference/ID` y el nombre de CDR contra el resumen inmutable. El ticket externo debe existir en el resumen e intento; `LOCAL-SIM-*` jamás se manda al adaptador Beta. Los topes actuales (ZIP de salida 10 MiB, respuesta/CDR ZIP 6 MiB, XML CDR 2 MiB y 20 entradas) son límites defensivos de la aplicación; no se presentan como máximos publicados por SUNAT y deben contrastarse en una futura prueba autorizada.

La confianza del certificado de firma de CDR queda cerrada por defecto: sin huella Beta obtenida y verificada fuera de banda, una CDR no puede producir un estado terminal y el resultado queda incierto. El bundle local valida estructura, pero no establece por sí solo confianza PKI de SUNAT.

Los resultados se distinguen así:

- `ticket_pending`: ticket Beta persistido; no es aceptación.
- `98`: procesamiento pendiente.
- CDR válida con código `0`: aceptación; con notas: aceptación con observaciones.
- CDR válida con código distinto de `0`: rechazo; `99` con CDR no rechazada se considera contradicción e incierto.
- SOAP Fault, error HTTP, XML inválido, tamaño excedido, timeout, ticket ausente o CDR que no corresponda: no se presenta como aceptación.
- Respuestas terminales repetidas/tardías no modifican el resultado terminal. No hay reenvío automático cuando el resultado del envío es incierto.

Las respuestas SOAP almacenadas son privadas y se sanitizan para omitir campos de autenticación; si el cuerpo no es XML interpretable, solo se conserva su huella SHA-256. El intento guarda endpoint fijo, SOAPAction, Content-Type, nombre, tamaños y SHA-256 del ZIP, sin usuario ni contraseña. La CDR solo queda disponible para descarga autorizada después de superar todas las validaciones. No se fabrica una CDR local.

## Configuración

Se añadieron **tres variables** a `.env.example` (dos controles Beta y la ruta del XSD CDR):

```dotenv
SUNAT_BETA_RECEIPT_SUMMARY_ENABLED=false
SUNAT_BETA_CDR_CERT_SHA256=
SUNAT_UBL_CDR_XSD="resources/sunat/xsd/Archivos XSD/2.0/maindoc/UBLPE-ApplicationResponse-1.0.xsd"
```

- `SUNAT_BETA_RECEIPT_SUMMARY_ENABLED`: `false` por defecto; habilitación independiente y explícita del adaptador de resúmenes Beta, además del módulo comercial. Solo QA local/pruebas.
- `SUNAT_BETA_CDR_CERT_SHA256`: vacío por defecto; huella SHA-256 del certificado CDR Beta validada por el propietario; sin ella no se confía en una CDR.
- `SUNAT_UBL_CDR_XSD`: ruta al XSD ApplicationResponse local y sus imports relativos; no se descarga durante el procesamiento.
- `SUNAT_UBL_SUMMARY_XSD`: variable ya existente; debe apuntar al bundle oficial completo de resumen.
- `ELECTRONIC_INVOICING_ENABLED`: variable ya existente para disponibilidad comercial, independiente del switch Beta.

### Configurar de forma segura la huella del certificado CDR

No se debe obtener la huella del certificado incluido dentro de una respuesta CDR y confiar en ella automáticamente: eso permitiría que una respuesta no confiable elija la clave con la que se valida a sí misma. El certificado TLS del endpoint tampoco debe asumirse como el certificado que firma las CDR.

La documentación pública SUNAT consultada describe la CDR como respuesta firmada, pero no publica en esas fuentes una huella SHA-256 autoritativa del certificado firmante específico de Beta. Antes de habilitar la validación terminal, el propietario debe obtener el certificado público o su huella por un canal oficial autenticado de SUNAT (por ejemplo, una respuesta documentada de soporte SUNAT o una descarga enlazada desde un dominio oficial), confirmar por un segundo canal oficial que corresponde al firmante CDR de Beta y comprobar vigencia/identidad. Si SUNAT no ofrece una huella publicada, solicítese expresamente la huella o el certificado público; no se sustituye con el certificado recibido en la CDR.

Si se entrega el certificado público como PEM o DER, calcúlese localmente su huella y compárese carácter por carácter con el valor confirmado fuera de banda:

```sh
openssl x509 -in sunat-beta-cdr-signer.pem -noout -fingerprint -sha256
# Para DER:
openssl x509 -inform DER -in sunat-beta-cdr-signer.cer -noout -fingerprint -sha256
```

Copiar a `SUNAT_BETA_CDR_CERT_SHA256` únicamente los 64 dígitos hexadecimales del SHA-256 (sin el prefijo `sha256 Fingerprint=` ni separadores `:`). Proteger el archivo de trabajo y eliminarlo según la política local. Este es un certificado público, no una clave privada, pero la huella debe seguir procediendo de la fuente independiente confirmada. Mantener la variable vacía hasta completar la verificación; con el valor vacío el procesador falla cerrado y no convierte una CDR en resultado terminal.

El endpoint es fijo en `config/electronic_invoicing.php`. El usuario/contraseña configurables se gestionan en la configuración de facturación cifrada existente; no se registra su contenido. El job usa el outbox/cola ya existentes y no debe iniciarse un worker ni scheduler con el switch Beta encendido sin autorización específica.

## Verificación local

Las pruebas de `ElectronicReceiptSummaryTest` interceptan todas las peticiones HTTP y además impiden solicitudes no simuladas. Se verifican SOAPAction, Content-Type, estructura/namespaces, Base64 exacto del ZIP, ticket separado de `LOCAL-SIM`, consulta `getStatus`, estados `98`, aceptación, aceptación con observaciones, rechazo, SOAP Fault, HTTP error, XML inválido/excesivo, CDR firmada/XSD/correspondencia, resultado terminal y guardas Beta deshabilitada/producción. Estas pruebas no demuestran interoperabilidad real ni aceptación SUNAT.

Pendiente para una prueba Beta futura autorizada: obtener y verificar fuera de banda la huella SHA-256 del certificado CDR Beta vigente (la documentación pública consultada no proporciona una huella autoritativa), confirmar el detalle de la autenticación y del empaquetado contra respuestas reales, y autorizar explícitamente una transmisión en la base QA. La aceptación Beta, si ocurre, no equivale a habilitación de producción ni a cumplimiento tributario completo. Las pruebas físicas/visuales y cualquier llamada externa quedan fuera de esta fase.
