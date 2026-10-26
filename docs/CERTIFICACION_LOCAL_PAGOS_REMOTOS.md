# Certificación local de pagos remotos

Fecha de consolidación: 2026-10-26. Esta evidencia describe ejecuciones locales; no certifica producción.

## Alcance y aislamiento

Las pruebas utilizaron fixtures e identidades sintéticas, credenciales sintéticas y transporte Mercado Pago completamente simulado. No hubo llamadas externas. No se accedió ni modificó producción, no se desplegó ningún cambio y no se realizó una prueba E2E real en navegador. Tampoco se recuperó el pago Sandbox histórico.

## Comportamiento verificado

- **Vinculación remota:** la evidencia sigue el recorrido pago → orden comercial → preferencia. La orden comercial recuperada debe contener exactamente el pago consultado. Su `preference_id`, `external_reference`, importe y moneda deben concordar con el pago y con la preferencia local vigente del pedido. La respuesta incompleta o contradictoria se rechaza; no se inventa una identidad remota.
- **Separación del gateway:** el gateway recupera y normaliza evidencia remota; la validación de pedido y preferencia local sucede en el servicio financiero bajo los locks correspondientes.
- **Webhook:** JSON y query string oficial `data.id` se normalizan de forma segura. Se exige tópico `payment`; se rechazan duplicados, IDs contradictorios y formatos ambiguos. La firma se verifica antes de consultar el gateway o escribir el evento. IPN queda fuera del contrato.
- **Procesamiento financiero:** una transición `pending` → `approved` conserva una sola identidad financiera y actualiza el pedido de forma coherente. Un pedido aprobado no se degrada por notificaciones posteriores.
- **Segundo pago remoto:** un pago aprobado diferente para un pedido ya pagado no crea otra transacción ni altera inventario. El evento se conserva para conciliación como `review_required`, con HTTP 202; nunca queda `processed` ni se comunica como éxito financiero.
- **Atomicidad e inventario:** pedido, `paid_at`, transacción, consumo de reserva y movimientos de inventario permanecen coherentes dentro de la transacción financiera. La reserva se consume una sola vez y no se duplica el movimiento.
- **Locks:** se conserva el orden pedido → preferencia → transacción → inventario. La preferencia y el pedido se revalidan bajo lock. Se mantienen tres intentos transaccionales y los errores SQL no se convierten en éxito.
- **Idempotencia:** repetir el mismo pago o evento no duplica transacciones, consumos, movimientos ni historial. Notificaciones distintas del mismo pago permanecen idempotentes.
- **Limpieza:** las carreras físicas usaron fixtures y marcadores propios. Al terminar no quedaron triggers, filas de fixture ni directorios temporales del harness.

## Evidencia de pruebas

Los bloques de regresión posteriores al P1 se solapan con las matrices ordinarias. Sus conteos se presentan aparte y **no se suman** a los conteos de certificación inicial o matriz ordinaria.

| Motor y ejecución | Tests | Assertions | Skips |
| --- | ---: | ---: | ---: |
| SQLite, certificación inicial completa | 269 | 1582 | — |
| SQLite, regresiones posteriores al P1 | 145 | 1293 | — |
| MySQL aislado, matriz ordinaria completa anterior | 305 | 2240 | 0 |
| MySQL aislado, regresiones posteriores al P1 | 145 | 1295 | 0 |

### Evidencia posterior: conciliación manual y estados remotos

Las regresiones focalizadas verificaron que `refunded`, `charged_back` y `cancelled` terminan en `review_required`, HTTP 202 y categoría `manual_reconciliation_required`. Los estados remotos no soportados terminan en `review_required`, HTTP 202 y categoría `unsupported_remote_status`. Ninguno queda `processed`. Estas rutas no modifican pedido, transacciones, reservas, inventario ni movimientos; las repeticiones permanecen terminales e idempotentes. Los errores SQL y técnicos siguen propagándose.

Para pedidos aprobados, vínculos locales obsoletos o incoherentes terminan en revisión. Los mismos vínculos en pedidos pendientes conservan el rechazo cerrado HTTP 422.

| Ejecución posterior | Motor | Tests | Assertions | Skips |
| --- | --- | ---: | ---: | ---: |
| Regresiones nuevas de conciliación manual | SQLite | 14 | 231 | — |
| Clases afectadas de conciliación manual | SQLite | 106 | 939 | — |
| Regresiones nuevas de conciliación manual | MySQL aislado | 14 | 231 | 0 |
| Clases afectadas de conciliación manual | MySQL aislado | 106 | 941 | 0 |

Estos bloques comparten clases y escenarios; son ejecuciones solapadas y no se suman entre sí ni a los conteos iniciales o a las matrices ordinarias.

### Allowlist de evidencia

`PaymentLinkEvidenceException` restringe sus categorías mediante una allowlist. Todo valor desconocido se normaliza a `payment_link_evidence_error`; categoría, mensaje, serialización y persistencia usan únicamente el valor normalizado. La causa `$previous` se conserva. La revisión estática no encontró un camino productivo que entregue una categoría arbitraria al constructor. La cobertura unitaria final fue de 13 tests y 64 assertions.

### Concurrencia física MySQL

La clase física completa pasó con 2 tests y 4308 assertions. Se ejecutaron 50 carreras: 10 Webhooks idénticos aprobados, 10 notificaciones distintas del mismo pago, 10 carreras `pending` → `approved`, 10 carreras `approved` → `pending` y 10 carreras entre dos pagos aprobados distintos para un mismo pedido.

El observador MySQL independiente confirmó el par exacto de locks en las 50 carreras. Se registraron 100 entradas al punto de coordinación transaccional. No hubo errores 1205 ni deadlocks. En las 10 carreras del segundo pago, un evento quedó procesado y el pago diferente quedó `review_required` con HTTP 202; este último nunca quedó `processed`. Se mantuvieron una sola transacción aprobada y una sola consumición de reserva y movimiento de inventario por pedido.

El worker publica sus marcadores escribiendo primero un archivo temporal y publicándolo mediante renombrado atómico. El marcador `release` continúa siendo creado exclusivamente por el observador.

## Revisión estática final

La revisión estática final de pagos remotos no encontró hallazgos P0–P3: **REVISIÓN FINAL P1/P2/P3 PAGOS REMOTOS APROBADA**. `git diff --check` fue correcto; emitió únicamente avisos de conversión LF/CRLF no bloqueantes.

## Límites de la certificación

Todo fue local. El transporte fue simulado y las credenciales fueron sintéticas. No hubo llamadas reales a Mercado Pago, acceso ni modificación de producción, certificación productiva ni despliegue. No se ejecutó una prueba E2E real en navegador ni una prueba Sandbox posterior al despliegue. El incidente productivo histórico no se declara reconciliado ni resuelto mediante modificación manual. Esta evidencia no declara resuelto ningún incidente productivo. Los conteos históricos y nuevos que comparten clases o escenarios son solapados y no constituyen un total acumulado.
