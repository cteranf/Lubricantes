---
document: Resumen funcional de LubriStore
document_type: functional-overview
version: 1.0
generated_at: 2026-09-04
project: LubriStore
consolidated_at: 2026-10-18
source_commit: dd2436b
---

# LubriStore

Este resumen refleja dd2436b. Las certificaciones y smoke mencionados son evidencia existente; esta consolidación documental no ejecutó pruebas ni consultó datos. Los resultados históricos no equivalen a una certificación operativa integral de producción.

Actualización posterior del 2026-10-18: el backend interno A0/A1/A2 quedó versionado localmente en `d0a73e2`. El cierre de reembolsos incorpora además B3-B HTTP privado certificado localmente y sustituye los pendientes históricos de finalización interna y endpoints. B3-B sigue sin commit ni despliegue nuevo en Hostinger.

## Reportes gerenciales

El panel administrativo incluye una sección de Reportes gerenciales con seis pestañas: Ventas, Inventario, Movimientos, Operaciones, Tiempos de ciclo y Catálogos. Cada pestaña se carga cuando se selecciona, conserva sus filtros en la dirección del navegador y permite actualizar o exportar los mismos resultados a CSV UTF-8.

Los filtros varían según la pestaña: fechas, modalidad, categorías, marcas, almacenes, productos, tipos de movimiento, severidad, razones, etapas, vencimiento y métricas de ciclo. Catálogos permite consultar productos, categorías, marcas, almacenes, sedes, repartidores, vehículos y el catálogo territorial y de tarifas. Los resultados muestran totales, fecha de generación, límite y aviso cuando existe truncamiento.

El Dashboard y Reportes son módulos diferentes: Dashboard ofrece indicadores agregados para supervisión; Reportes permite revisar filas y exportarlas bajo demanda. La exportación protege el contenido frente a fórmulas de hojas de cálculo y no incluye datos personales. El registro de 390 pruebas exitosas corresponde al corte histórico de reportes, no a una nueva ejecución. Sigue pendiente su smoke manual en navegador para validar navegación, filtros, descargas y tamaños de pantalla.

## Propósito

LubriStore es una tienda digital especializada en lubricantes. Permite mostrar productos, recibir pedidos, reservar existencias y coordinar entrega a domicilio o recojo en una sede.

## Módulos disponibles

- Catálogo de productos, categorías, marcas e imágenes.
- Carrito y checkout.
- Direcciones de clientes.
- Inventario por sedes y almacenes.
- Reservas temporales de stock.
- Pago con tarjeta, transferencia, contraentrega y pago en sede según configuración.
- Registro seguro de transferencias mediante Yape, Plin o banco, con recuperación de la presentación del cliente.
- Bandeja de Tesorería con revisión, observación, rechazo, aprobación y resolución manual con reasignación de stock.
- Preparación de pedidos, picking y packing.
- Despacho propio o mediante courier.
- Seguimiento y confirmación de entrega o recojo.
- Administración de sedes, tarifas, territorios, repartidores y vehículos.
- Bandeja de consultas de contacto.

## Flujo comercial

El cliente selecciona productos, revisa cantidades y elige una dirección o una sede de recojo. Para delivery se calcula una tarifa según la cobertura configurada; para pickup el flete es cero. El sistema valida disponibilidad, guarda un resumen histórico del pedido y reserva el stock antes de continuar con el pago.

Una vez aprobado el pago o confirmado el método manual correspondiente, el equipo prepara el pedido. El cliente recibe el producto mediante reparto o lo recoge en la sede seleccionada.

## Administración

El panel permite gestionar el catálogo, inventario, sedes, almacenes, territorios, zonas, tarifas, pedidos, preparación, flota y consultas. Las operaciones administrativas requieren una cuenta autorizada. Los pedidos y sus datos históricos se conservan para facilitar atención y trazabilidad.

## Inventario y trazabilidad

Las existencias se controlan por almacén. Las reservas evitan vender simultáneamente las mismas unidades; pueden consumirse, liberarse o vencer. Los movimientos y el historial operativo registran quién realizó cada operación, cuándo ocurrió y qué saldo resultó.

Las reservas conservan versiones históricas. La reasignación crea una nueva reserva, sin reactivar la vencida; el cliente ve el estado financiero y la reserva actual, con mensajes de vigencia, stock confirmado, vencimiento o liberación según corresponda.

## Pagos

La plataforma puede trabajar con Mercado Pago y con métodos manuales. Las confirmaciones de tarjeta se verifican con el proveedor y los eventos se procesan de forma idempotente. Para transferencia, el cliente puede elegir Yape, Plin o banco, consultar un QR seguro cuando corresponde y registrar su operación; queda pendiente de validación por Tesorería. La operación se protege contra duplicados y no confirma el pago ni descuenta inventario por sí sola.

## Delivery y pickup

Delivery utiliza zonas y tarifas configurables. Pickup muestra únicamente sedes activas que hayan sido habilitadas para recojo y conserva la información de la sede elegida en el pedido. La preparación, el despacho, los intentos fallidos y la confirmación final quedan registrados.

## Seguridad

Las cuentas utilizan autenticación basada en tokens. Los roles Cliente, Administrador y Tesorería tienen accesos diferenciados. Treasury dispone del acceso directo “Panel de tesorería” y de su bandeja financiera. El servidor vuelve a validar precios, disponibilidad, propiedad de pedidos, cobertura y pagos; el navegador no puede confirmar por sí solo una transacción financiera. Los QR de transferencia se obtienen desde almacenamiento privado y los datos bancarios completos solo se muestran al propietario autorizado de un pedido elegible.

## Beneficios

- Venta especializada con experiencia de compra centralizada.
- Control de stock por ubicación.
- Menor riesgo de sobreventa mediante reservas.
- Opciones flexibles de entrega.
- Historial completo para atención y operación.
- Preparación y despacho organizados desde el mismo panel.
- Base preparada para incorporar nuevas sedes, tarifas y canales de pago.

## Alcance actual

El proyecto ya está desplegado y levantado en Hostinger en dd2436b. Se instalaron dependencias Composer de producción, el entorno es de producción con debug desactivado, todas las migraciones están aplicadas —incluida 070630— y se creó manualmente un administrador seguro. Estos hechos provienen del despliegue confirmado; esta consolidación no accedió al servidor. Cron/scheduler, workers/colas, correo, monitoreo y aceptación operativa integral aún no quedan certificados.

## Requisitos para implantación

Antes de atender clientes reales se deben registrar las sedes y almacenes definitivos, cargar el inventario, aprobar las zonas y tarifas comerciales, configurar los métodos de pago, habilitar correo y colas, publicar el webhook de pagos mediante HTTPS, preparar repartidores y vehículos y ejecutar pruebas de aceptación y recuperación.

## Posibles ampliaciones

La bandeja de Tesorería, la aprobación y la resolución con reasignación ya están disponibles. Los reembolsos totales cuentan con servicios internos A0/A1/A2 y HTTP privado B3-B certificados localmente; siguen pendientes su interfaz Treasury, integración de devolución con gateways y validación operativa productiva. Otras ampliaciones son nuevas pasarelas, tarifas por peso o distancia, más puntos de recojo, notificaciones, aplicaciones para repartidores y pruebas de interfaz automatizadas.

Este resumen está dirigido a clientes y responsables de negocio. Para detalles técnicos, contratos y procedimientos de mantenimiento consulte `INFORME_TECNICO_LUBRISTORE.md`.

## Dashboard gerencial

El panel administrativo ofrece una vista ejecutiva de ventas reconocidas según la fecha de pago, pedidos, ticket promedio, unidades, inventario actual, reservas y alertas operativas. Incluye filtros por periodo y modalidad, pestañas de resumen, ventas, inventario y operaciones, y enlaces para atender incidencias. Las métricas de inventario son una fotografía actual y no sustituyen reportes de costos, rentabilidad, segmentación avanzada o pronósticos, que quedan como ampliaciones futuras.

El dashboard carga una respuesta completa al abrirse. Las pestañas organizan visualmente esa respuesta y cambiar entre ellas no realiza nuevas solicitudes; aplicar filtros o actualizar vuelve a cargar el dashboard completo. La carga segmentada por sección es una optimización futura y el contrato `section` se mantiene experimental.

## Usuarios y perfil

Los administradores pueden gestionar cuentas desde el panel: buscar usuarios, crear cuentas, actualizar datos autorizados, activar o desactivar accesos y restablecer contraseñas. El sistema conserva el historial comercial y operativo: las cuentas no se eliminan desde esta administración. También protege que siempre permanezca al menos un administrador activo.

Cada persona autenticada dispone de “Mi perfil” para actualizar sus propios datos de contacto y cambiar su contraseña tras confirmar la actual. El cambio de contraseña cierra las sesiones activas como medida de seguridad y requiere iniciar sesión otra vez. Los roles, permisos administrativos y estado de la cuenta no se modifican desde el perfil personal.

La validación automática actual cubre estas reglas de acceso y privacidad; antes de una puesta en marcha debe realizarse una comprobación manual en los dispositivos y navegadores objetivo.

La pestaña Inventario distingue existencias físicas, reservas y disponibilidad actuales, además de mostrar concentración por almacén, movimientos y reservas por estado. La salida de productos se compara únicamente con el periodo elegido.
## Estado de transferencias

Los clientes pueden registrar una transferencia y Tesorería puede revisarla, observarla, rechazarla o aprobarla con trazabilidad. Si la reserva ya no permite la aprobación ordinaria, el backend determina si puede abrirse una resolución manual. La tienda no solicita un segundo pago ni reactiva automáticamente la reserva histórica.

2B4-B1/B2 ya resuelven los casos elegibles: Tesorería abre la resolución y puede “Reasignar stock y aprobar”. El sistema registra el pago recibido, intenta una nueva reserva y, si el estado y el stock son coherentes, consume esa reserva y aprueba el mismo pago una sola vez. Una cancelación manual no se interpreta como vencimiento automático.

## Aprobación de transferencias por Tesorería

La fase técnica de aprobación de transferencias está disponible para el equipo de Tesorería. Cuando una presentación válida está pendiente y la reserva sigue vigente, Tesorería puede confirmar el pago de manera controlada. El sistema registra la validación, actualiza el pago del pedido y descuenta el inventario una sola vez; una repetición coherente no duplica el cobro ni la salida de productos.

El cliente ve una confirmación clara de pago validado y una referencia de operación enmascarada. Los administradores operativos no reciben un segundo botón competidor: los pedidos con presentación de Tesorería indican su estado (“Pendiente”, “Observado”, “Aprobado”, “Rechazado”, “Vencido” o “Cancelado”) y mantienen separados los permisos de cada equipo. Los pedidos históricos sin presentación conservan su flujo operativo anterior.

Los smoke históricos documentados confirmaron aprobación ordinaria y resolución con reasignación, una transacción vinculada, consumo único de stock y continuidad hacia preparación. Se conserva la evidencia funcional sin reproducir identificadores de pedidos, operaciones o referencias financieras.

## Reembolsos: backend y HTTP privado certificados; interfaz pendiente

B3-A0 aporta la infraestructura y B3-A1 permite iniciar el reembolso mediante un servicio interno, ahora expuesto por HTTP privado B3-B. No existe un botón de inicio ni se puede completar una devolución desde la interfaz. El inicio requiere un pedido pagado y cancelado, no entregado ni recogido, con inventario ya compensado; cancelar por sí solo no crea el reembolso.

El contrato admite exclusivamente reembolsos totales, para todos los medios cobrados. Tesorería inicia y finaliza manualmente mediante servicios internos; el mismo usuario puede realizar ambas acciones. Al completar es obligatoria una referencia externa, que se guarda cifrada. No hay devolución parcial ni integración automática de devolución con Mercado Pago u otros proveedores. Los pedidos entregados o recogidos quedan fuera del flujo.

B3-A2 está implementado y certificado como finalización interna: registra un único asiento de devolución, completa el reembolso y actualiza el pedido y la presentación cuando existe, con historial coherente. Una repetición con la misma referencia por cualquier Treasury activo conserva el usuario que completó originalmente y no duplica registros. No modifica inventario ni logística. A0/A1/A2 están versionados en `d0a73e2`. B3-B incorpora sus endpoints privados; B3 completo todavía no está disponible desde la interfaz.

La certificación local del 2026-10-18 cubrió seis escenarios MySQL con diez carreras cada uno, la regresión del retorno actualizado y A1 físico. Después aprobaron la clase MySQL completa (8 tests, 4071 assertions), SQLite A2 (112 tests, 635 assertions) y regresiones SQLite A1/B2 e inventario versionado (339 tests, 3803 assertions), con cobertura solapada. Solo se utilizó una instancia temporal de certificación, posteriormente eliminada. Los documentos técnicos detallan el alcance y la evidencia.

B3-B permite iniciar con `POST /api/v1/treasury/orders/{orderId}/refunds` y completar con `POST /api/v1/treasury/payment-refunds/{refundId}/complete`. Ambos exigen `auth:sanctum`, `active_user` e `is_treasury`. La autorización precede a buscar el recurso: anónimo recibe 401; otros roles o Treasury inactivo, 403; Treasury activo con identificador inválido o inexistente, 404 seguro.

Requests separados rechazan campos adicionales, estructuras anidadas, arrays, archivos y claves idempotentes del cliente. El Resource usa una lista explícita de IDs, estado, importe, moneda, medio de pago original, fechas, mensaje y un indicador visual de finalización; no revela motivos, referencias ni datos financieros privados. Solo el conflicto de dominio `PaymentRefundConflictException` se traduce a 409 seguro; `QueryException` y errores inesperados conservan 500 seguro con debug desactivado. Las respuestas exitosas y los conflictos impiden caché mediante `Cache-Control: no-store, private` y `Pragma: no-cache`.

Antes del ajuste posterior del matcher, la suite local B3-B aprobó en SQLite con **116 tests y 693 assertions** y en MySQL 8.4.3 temporal aislado con **116 tests seleccionados y 682 assertions** (114 ejecutados y dos skips preexistentes heredados A1; ningún caso HTTP omitido). Esa versión de la suite incluye 51 casos HTTP y 65 heredados. También aprobaron las regresiones SQLite directamente afectadas. El cierre de higiene anterior solo repitió focalizados de controles y trim; los documentos técnicos detallan la evidencia y sus límites. El temporal propio se verificó ausente.

Después se limitó la excepción de trim a la forma exacta de los dos POST, evitando que alcance rutas parecidas con segmentos extra o vacíos. Su nueva regresión aislada aprobó con **1 test/336 assertions** y los focalizados de payloads/controles con **24/96**. La suite SQLite actual aprobó con **117 tests/1029 assertions**. No se repitió MySQL, cuya evidencia anterior sigue siendo de 116 tests; el ajuste no cambia servicios financieros ni persistencia.

Este cierre HTTP B3-B no acredita devoluciones reales en producción ni se ha publicado o desplegado. Hostinger conserva el despliegue confirmado de dd2436b. Siguen pendientes frontend Treasury, integración de gateways, revisión de históricos antes de habilitar el flujo completo, notificaciones, monitoreo financiero y validación de cron, workers, correo y operación integral del entorno desplegado. No se declara certificación productiva integral.

La etapa local B3-C0 añade una evaluación interna de solo lectura con tres indicadores: posibilidad de iniciar, posibilidad de completar y necesidad de revisión manual. Comparte los predicados con A1/A2, sin ejecutar acciones financieras, abrir transacciones, bloquear ni modificar inventario. Un pedido normalmente no elegible no se considera corrupto automáticamente; la evidencia financiera incoherente bloquea ambas acciones. El POST conserva la revalidación transaccional final.

El historial y el detalle de presentación dejan de devolver el motivo privado de los eventos de reembolso; el contenido persistido permanece intacto y los motivos autorizados de otros eventos se conservan. No se devuelven referencias ni metadata de reembolso.

C0 aprobó disponibilidad en SQLite con **100 tests/867 assertions**, las regresiones A1/A2, HTTP B3-B, Reassignment y revisión Treasury, y MySQL 8.4.3 aislado con **100 tests seleccionados/856 assertions** (98 ejecutados y dos skips heredados A1; ningún caso C0 omitido), además de **2 tests/41 assertions** de historial. La suite C0 incluye 65 casos heredados. La instancia y los temporales propios se eliminaron. Este cierre interno no implementa todavía GET, listado de reembolsos ni interfaz Treasury, y no está publicado o desplegado; gateways y operación productiva siguen pendientes.
