---
document: Resumen funcional de LubriStore
document_type: functional-overview
version: 1.0
generated_at: 2026-09-04
project: LubriStore
---

# LubriStore

## Propósito

LubriStore es una tienda digital especializada en lubricantes. Permite mostrar productos, recibir pedidos, reservar existencias y coordinar entrega a domicilio o recojo en una sede.

## Módulos disponibles

- Catálogo de productos, categorías, marcas e imágenes.
- Carrito y checkout.
- Direcciones de clientes.
- Inventario por sedes y almacenes.
- Reservas temporales de stock.
- Pago con tarjeta, transferencia, contraentrega y pago en sede según configuración.
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

## Pagos

La plataforma puede trabajar con Mercado Pago y con métodos manuales. Las confirmaciones de tarjeta se verifican con el proveedor y los eventos se procesan de forma idempotente. Transferencias y cobros al entregar o recoger requieren revisión operativa según las reglas configuradas.

## Delivery y pickup

Delivery utiliza zonas y tarifas configurables. Pickup muestra únicamente sedes activas que hayan sido habilitadas para recojo y conserva la información de la sede elegida en el pedido. La preparación, el despacho, los intentos fallidos y la confirmación final quedan registrados.

## Seguridad

Las cuentas utilizan autenticación basada en tokens. El acceso administrativo está separado del acceso de clientes. El servidor vuelve a validar precios, disponibilidad, propiedad de pedidos, cobertura y pagos; el navegador no puede confirmar por sí solo una transacción financiera.

## Beneficios

- Venta especializada con experiencia de compra centralizada.
- Control de stock por ubicación.
- Menor riesgo de sobreventa mediante reservas.
- Opciones flexibles de entrega.
- Historial completo para atención y operación.
- Preparación y despacho organizados desde el mismo panel.
- Base preparada para incorporar nuevas sedes, tarifas y canales de pago.

## Alcance actual

El sistema está operativo para desarrollo local y cuenta con pruebas automatizadas de los flujos principales. La puesta en marcha de cada entorno requiere configurar sus productos, ubicaciones, tarifas, inventario, correo, colas, credenciales de pago, dominio seguro y procedimientos de operación.

## Requisitos para implantación

Antes de atender clientes reales se deben registrar las sedes y almacenes definitivos, cargar el inventario, aprobar las zonas y tarifas comerciales, configurar los métodos de pago, habilitar correo y colas, publicar el webhook de pagos mediante HTTPS, preparar repartidores y vehículos y ejecutar pruebas de aceptación y recuperación.

## Posibles ampliaciones

Entre las ampliaciones naturales están nuevas pasarelas, tarifas por peso o distancia, más puntos de recojo, automatización de notificaciones, aplicaciones para repartidores, reportes comerciales y pruebas de interfaz automatizadas.

Este resumen está dirigido a clientes y responsables de negocio. Para detalles técnicos, contratos y procedimientos de mantenimiento consulte `INFORME_TECNICO_LUBRISTORE.md`.
