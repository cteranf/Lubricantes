# Índice de documentación

La documentación de LubriStore reúne descripción funcional, contratos técnicos, auditorías y resultados de validación. Los informes fechados son evidencia de un corte: verifica código, pruebas, configuración y base efectiva antes de usarlos como descripción actual.

## Orientación general

- [Resumen funcional](RESUMEN_FUNCIONAL_LUBRISTORE.md): capacidades de negocio y contexto histórico. Algunas secciones anteriores al módulo de facturación electrónica están desactualizadas; consulta las fases 1–5 y el código actual para ese tema.
- [Informe técnico](INFORME_TECNICO_LUBRISTORE.md): arquitectura, API, modelos, reglas y operación; contiene cortes históricos y debe leerse con sus fechas.
- [Actualización PHP 8.5 / Laravel 13](ACTUALIZACION_PHP85_LARAVEL13.md): versión, adaptación, plataforma y evidencia de pruebas de esa actualización.
- [Cierre técnico de facturación y plataforma](CIERRE_TECNICO_FACTURACION_Y_PHP85_LARAVEL13.md): inventario, configuración reproducible, pruebas y pendientes del corte documentado.

## Facturación electrónica

- [Fase 1: base del módulo](FACTURACION_ELECTRONICA_FASE_1.md)
- [Fase 2: cálculo tributario y snapshots](FACTURACION_ELECTRONICA_FASE_2.md)
- [Fase 3: facturas y transporte Beta](FACTURACION_ELECTRONICA_FASE_3.md)
- [Fase 4: boletas y resúmenes locales](FACTURACION_ELECTRONICA_FASE_4_BOLETAS.md)
- [Fase 5: transporte Beta de resúmenes](FACTURACION_ELECTRONICA_FASE_5_RESUMENES_BETA.md)
- [Auditoría Composer de Fase 3 (JSON)](FACTURACION_ELECTRONICA_FASE_3_COMPOSER_AUDIT.json)

Los informes documentan capacidades y pruebas, no autorizan producción ni demuestran que SUNAT haya aceptado un documento. `LOCAL-SIM`, respuestas interceptadas y llamadas externas son categorías diferentes.

## Inventario, pagos y datos comerciales

- [Fase 2 de inventario: sedes, almacenes y reservas](FASE_2_INVENTARIO.md)
- [Auditoría del flujo de pagos y Tesorería](AUDITORIA_FLUJO_PAGOS_Y_TESORERIA.md): informe evolutivo; propuestas y cierres históricos están marcados por fase/fecha.
- [Auditoría de configuración del negocio y documentos de venta](AUDITORIA_CONFIGURACION_NEGOCIO_Y_DOCUMENTOS_VENTA.md): análisis inicial y etapas posteriores; las tablas de carencias del corte inicial no representan necesariamente el estado actual.
- [PDF comerciales](../app/Services/CommercialOrderPdfService.php): resumen del pedido y constancia de pago privados, independientes de SUNAT.
- [Certificación local de auditoría general](CERTIFICACION_LOCAL_AUDITORIA_GENERAL.md)
- [Certificación local de pagos remotos](CERTIFICACION_LOCAL_PAGOS_REMOTOS.md)
- [Certificación local de tablas administrativas](CERTIFICACION_LOCAL_TABLAS_ADMINISTRATIVAS.md)
- [Certificación local de verificación de correo](CERTIFICACION_LOCAL_VERIFICACION_CORREO.md)

## Datos territoriales

- [Transición del catálogo territorial](territorial-catalog-transition.md)
- [Seeder territorial oficial](official-territory-seeder.md)

## Cautelas al interpretar el historial

- [Resumen funcional, sección “Posibles ampliaciones”](RESUMEN_FUNCIONAL_LUBRISTORE.md) conserva el corte de noviembre 18 que dice que la emisión/registro de boletas y facturas aún requiere diseño. Las fases 1–5 y los servicios/rutas electrónicos que existen en el árbol actual son posteriores; para capacidades vigentes consulta esos documentos y verifica el código. Esto no prueba emisión real ni aceptación SUNAT.
- [Auditoría de configuración del negocio](AUDITORIA_CONFIGURACION_NEGOCIO_Y_DOCUMENTOS_VENTA.md) declara expresamente un alcance estático en un commit anterior y contiene una matriz de carencias previa a las etapas posteriores.
- [Auditoría de pagos y Tesorería](AUDITORIA_FLUJO_PAGOS_Y_TESORERIA.md) mezcla propuestas iniciales con cierres posteriores. Usa el encabezado/fecha de cada fase y confirma contra servicios y middleware actuales.
- Los comandos de migración, estado de datos, resultados de pruebas y estado de despliegue pertenecen al entorno/corte que el documento identifica. Nunca asumas que se aplicaron en la base o instalación que estás usando.

## Punto de venta (POS)

- [Diseño técnico de POS y caja básica](POS_DISENO_TECNICO.md): estado de la base transaccional implementada, límites actuales y plan pendiente para la interfaz de venta y confirmación de cobro.
