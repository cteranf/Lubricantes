# Certificación local del piloto de tablas administrativas

## Alcance

La migración certificada cubre únicamente **Pedidos / Preparación y entrega**. Las demás secciones administrativas todavía no se consideran migradas.

El piloto utiliza PrimeVue DataTable **4.5.4** y el tema Aura de `@primeuix/themes` **2.0.2**. Laravel conserva la paginación, los filtros y el ordenamiento en el servidor.

## Funcionalidad certificada

- Tamaños de página de 15, 25, 50 y 100 filas.
- Ordenamiento limitado a columnas permitidas.
- Búsqueda global y filtros avanzados por los campos del piloto.
- Filtros adaptados a escritorio y a la vista responsive.
- Sincronización de filtros y paginación con la URL, incluida la navegación atrás y adelante.
- Descarte de respuestas obsoletas para evitar que una solicitud anterior reemplace resultados más recientes.
- Locale en español, expansión de filas en móvil y mensajes de error fijos y seguros.

## Backend

La solicitud usa un FormRequest estricto, validación de formas canónicas y límites para sus parámetros. La validación de nombres de parámetros inspecciona la query string original antes de `parse_str`, rechaza aliases y duplicados tras la decodificación canónica, y no registra ni expone la query cruda.

El listado responde mediante un Resource explícito. Los importes se validan y comparan en unidades monetarias menores, y los filtros de fecha aplican límites diarios coherentes con la zona horaria configurada. Las relaciones se cargan para prevenir N+1 y se conserva un conteo estable de consultas.

## Evidencia SQLite

| Ejecución | Tests | Assertions |
| --- | ---: | ---: |
| `AdminOrderListingTest` | 48 | 414 |
| `AdminOrdersFrontendTest` | 9 | 360 |
| `VersionedInventoryReservationReadTest` | 9 | 40 |
| `AdminOrdersTreasurySubmissionTest` | 8 | 98 |
| Conteo focalizado de consultas | 1 | 7 |

Estas ejecuciones incluyen pruebas y escenarios solapados. Sus conteos no deben sumarse como si fueran una certificación acumulada independiente.

## Evidencia MySQL aislado

| Ejecución | Tests | Assertions |
| --- | ---: | ---: |
| `AdminOrderListingTest` | 48 | 414 |
| `VersionedInventoryReservationReadTest` | 9 | 40 |
| `AdminOrdersTreasurySubmissionTest` | 8 | 98 |

Las pruebas MySQL se realizaron contra el entorno local aislado. No representan acceso ni certificación de producción.

## Calidad

- `npm run build`: aprobado.
- Pint: aprobado.
- `php -l`: aprobado.
- `git diff --check`: limpio.
- Se observaron avisos no bloqueantes de Browserslist, tamaño del chunk y conversión de saltos LF/CRLF.

## Validación visual manual

El usuario aprobó la validación visual local de escritorio, tablet y móvil. La revisión cubrió filtros, paginación, expansión, retorno del foco, locale y ausencia de IDs duplicados.

## Límites

La certificación es local. No hubo acceso a producción, despliegue ni migraciones productivas. No existe un harness dinámico Vue para estas pruebas: las comprobaciones frontend son contractuales y textuales, complementadas por la validación visual manual indicada arriba.

Solo Pedidos está completado. No se afirma que las demás secciones estén migradas.

## Plan posterior

Las siguientes áreas se abordarán por separado:

1. Catálogo.
2. Inventario y logística.
3. Ventas y atención.
4. Pagos.
5. Contenido.
6. Administración.
7. Análisis.

## Despliegue pendiente

El despliegue del piloto todavía está pendiente. En el entorno correspondiente, el procedimiento previsto es:

```sh
git pull
npm ci
npm run build
php artisan optimize:clear
```

Este piloto no requiere una migración nueva. Antes de habilitarlo, deben verificarse los permisos y la configuración habitual del entorno y realizarse un smoke test administrativo.
