# Facturación electrónica — Fase 2: cálculo tributario y snapshots

**Estado:** cálculo local y borradores; no emite comprobantes ni se conecta a SUNAT/proveedores.  
**Consulta normativa:** 2026-11-19.  
**Moneda y zona horaria:** PEN; `config('app.timezone')` (en esta instalación, `America/Lima`).

## Fuentes oficiales consultadas

- [SUNAT — IGV](https://emprender.sunat.gob.pe/principales-impuestos/impuesto-general-las-ventas-igv/impuesto-general-las-ventas): para 2026, tasa total 18%, compuesta por IGV 15.5% e IPM 2.5%; para 2027, IGV 15% e IPM 3%.
- [TUO de la Ley del IGV, artículo 14](https://www.sunat.gob.pe/legislacion/igv/ley/capitul5.htm): la base incluye el importe total a cargo del comprador y determinados cargos y gastos trasladados por el vendedor.
- [Reglamento de la Ley del IGV, artículo 13](https://www.sunat.gob.pe/legislacion/igv/regla/cap2s.htm): contempla cargos necesarios de entrega/prestación en la base bajo las condiciones del reglamento.
- [Guía XML de factura electrónica UBL 2.1](https://cpe.sunat.gob.pe/sites/default/files/inline-files/gu%C3%ADa%2Bxml%2Bfactura%2Bversion%202-1%2B1%2B0%20%282%29_0%20%282%29.pdf): referencia técnica de precios unitarios, extensiones de línea, descuentos e impuestos.
- [SUNAT — Informe sobre pagos anticipados](https://www.sunat.gob.pe/legislacion/oficios/2008/oficios/i2012008.htm): referencia para el momento tributario asociado a anticipos.

Las fuentes respaldan el tratamiento general descrito; no resuelven cómo distribuir un único costo de envío entre líneas con afectaciones distintas. Ese caso queda deliberadamente en revisión. La aplicación no presume una asignación.

## Decisiones del cálculo implementado

- La afectación se clasifica explícitamente por producto: `10` gravado, `20` exonerado y `30` inafecto. No se asigna valor a productos existentes durante la migración.
- La configuración tributaria separada guarda IGV e IPM en puntos base, fecha de vigencia, versión y política de precios incluidos. La pantalla administrativa muestra la zona del negocio; no hay “tasa vigente” antes de `effective_from`.
- Los precios del catálogo, promociones y envío son importes finales. Para cada línea se usa el `price` aplicado que el checkout ya guarda; `regular_price` y el descuento se conservan como referencia, sin aplicar la promoción una segunda vez.
- Los cálculos usan céntimos enteros. Para una línea gravada se obtiene la base incluida como `round_half_up(gross_cents × 10000 / (10000 + tasa_total_bps))`; el impuesto es la diferencia contra el importe final. IGV se redondea por línea extendida y el remanente de céntimos se asigna a IPM para que sus componentes sumen exactamente el impuesto redondeado. Cada línea se calcula y redondea de forma independiente.
- Ejemplo: S/ 118.00 con IGV 15.5% e IPM 2.5% produce base S/ 100.00, IGV S/ 15.50, IPM S/ 2.50 e impuesto total S/ 18.00. Tres unidades a S/ 0.05 producen una extensión final S/ 0.15, base redondeada S/ 0.13 e impuesto S/ 0.02.
- Envío gratuito y recojo (`shipping_amount = 0`) tienen desglose de envío cero. Un envío positivo con líneas todas gravadas se incluye una sola vez como accesorio gravado según la regla general consultada. Afectaciones mixtas generan `review_required`, conservan el total checkout y señalan `shipping_mixed_affectations`; hasta resolver el reparto, no están listos para emisión. Un envío positivo para líneas solo exoneradas/inafectas también queda `review_required` por falta de regla específica confirmada.
- Descuentos globales se marcan para revisión porque el flujo actual no los produce ni define su asignación tributaria; los descuentos de promoción sí se reflejan en el precio aplicado de cada línea.
- El snapshot conserva estado, versión de cálculo/configuración, afectación, tasas, importe final, base e impuestos por línea, envío, totales y procedencia. Las órdenes históricas conservan sus importes y quedan sin snapshot tributario (`pending_configuration`); no se backfillea una clasificación que no estaba registrada.
- La API de cotización del carrito firma una huella temporal de precios, afectaciones, configuración y envío. El checkout envía esa cotización al crear el pedido; una diferencia material provoca una respuesta 409 con el desglose actual y requiere confirmación del usuario incluso si el total permanece igual. El total comercial calculado en servidor no cambia por el desglose.

## Productos y administración

La lista/formulario administrativo admite una clasificación opcional. Los valores no son editables por clientes ni se añaden al Resource público. El Administrador mantiene una configuración tributaria con control de versión. Cambiar clasificación o tasas no reescribe snapshots ya guardados.

## Borradores y anticipos

Facturación toma el snapshot del pedido y de sus líneas; no recalcula una orden histórica con las tasas actuales. El borrador identifica afectación, base/impuesto, total, origen y datos faltantes. Un cálculo `pending_configuration`, `review_required` o histórico `pending_definition` recibe 409 al intentar emitir y no crea intentos. La emisión real permanece sin implementar.

Los abonos conservan sus importes y flujo existentes, independientes del cálculo. El snapshot de esta fase representa la venta completa del pedido, no el importe de cada abono. El efecto tributario de anticipos parciales y su aplicación a un documento posterior debe definirse en la fase de emisión. Varios borradores de importe completo no pueden interpretarse como acumulación de cobertura tributaria; la futura confirmación de emisión debe hacer cumplir esa regla.

## Restricciones conocidas / pendientes

- Envío cobrado con afectaciones mixtas: distribución tributaria pendiente de definición contable; se conserva el checkout, pero se bloquea la futura emisión.
- Envío positivo sobre operaciones exclusivamente exoneradas/inafectas: validación tributaria pendiente.
- Anticipos parciales y su aplicación a la emisión final: pendiente de fase posterior.
- Operaciones especiales (exportaciones, operaciones gratuitas, detracciones, percepciones, ISC, regímenes especiales y similares) no se clasifican automáticamente; requieren reglas específicas.
- Revisión visual QA completada en `http://127.0.0.1:8013`, con la conexión de Laravel verificada como `lubristore_einvoice_phase2_20261119_a7f3` y motor InnoDB. Se usaron productos, dirección, sucursal, usuario y pedido sintéticos; no se tocaron pedidos reales ni se inició un pago externo.
  - En escritorio y emulación móvil de 400 × 734 se revisaron checkout, clasificación, promociones y envío. En móvil los controles se apilan y el resumen tributario es legible sin desbordamiento horizontal. El borrador de Facturación se revisó en escritorio.
  - Un producto gravado a S/ 118.00 mostró base S/ 100.00, IGV S/ 15.50, IPM S/ 2.50 e impuesto total S/ 18.00, conservando total S/ 118.00.
  - Una línea promocional de S/ 130.00 a S/ 109.00 mostró ahorro de S/ 21.00 una sola vez. En el checkout mixto, el subtotal antes de oferta fue S/ 234.00, ahorro S/ 21.00 y subtotal aplicado S/ 213.00.
  - Las líneas exonerada e inafecta mostraron respectivamente base exonerada S/ 59.00 y base inafecta S/ 45.00, sin añadirles IGV/IPM. El recojo en sede y el destino de envío gratuito mostraron costo de envío S/ 0.00.
  - Para productos de afectaciones distintas con envío cobrado de S/ 15.00, el total comercial se conservó y se mostró el motivo `shipping_mixed_affectations` como pendiente de revisión contable; no se afirmó un impuesto para el envío.
  - Se confirmó el pedido sintético #6 por recojo, total S/ 118.00, sin iniciar cobro. El snapshot persistido conserva el precio aplicado, cantidad, afectación gravada, base S/ 100.00, impuesto S/ 18.00, IGV/IPM y envío cero. La consulta posterior confirmó `products.stock = inventario físico = 20`, con una unidad reservada para ese pedido.
  - Facturación abrió el borrador del pedido #6 usando el snapshot: línea de S/ 118.00, total histórico PEN 118.00 y desglose 100 + 18. La pantalla lo identifica como “Borrador · no emitido”, muestra datos faltantes y no ofrece acción para emitir. El endpoint de emisión aún responde `503 connection_not_implemented` y no emite ni crea un intento.
  - El tratamiento tributario de anticipos parciales sigue pendiente. Un cálculo completo de impuestos solo se etiqueta como “Cálculo tributario completo”; la interfaz no lo presenta como comprobante emitido ni como autorización de emisión. No se probó una emisión de anticipo porque esa funcionalidad no está implementada; deberá definirse una regla de anticipos antes de habilitar cualquier emisión.

## Migraciones locales

Fase 2 agrega:

- `2026_11_19_071100_create_business_tax_configurations_table.php`
- `2026_11_19_071110_add_tax_snapshots_to_orders_and_order_items.php`

No ejecutar sobre producción. Para aplicar solo estas dos migraciones en una instalación local que ya tiene la base de Fase 1:

```powershell
php artisan migrate --path=database/migrations/2026_11_19_071100_create_business_tax_configurations_table.php --path=database/migrations/2026_11_19_071110_add_tax_snapshots_to_orders_and_order_items.php
```

Verifica antes el nombre efectivo de la conexión. En esta validación las dos migraciones se probaron en el esquema aislado indicado arriba. Los snapshots existentes quedan nulos/pending; los pedidos y sus importes se preservan.
