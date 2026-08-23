# Catálogo territorial: transición e importación futura

El catálogo territorial se entrega vacío. Esta implementación no descarga, inventa ni inserta departamentos, provincias, distritos o ubigeos.

## Transición

- `users.addresses` permanece intacto. El checkout lo presenta como información heredada para revisión manual.
- Las filas existentes de `user_addresses`, `branches` y `shipping_rates` conservan sus campos textuales y reciben relaciones territoriales nullable sin backfill.
- Una dirección nueva o editada exige departamento, provincia y distrito activos y consistentes. Los textos y el ubigeo se copian desde el catálogo como snapshot operativo.
- Una sede histórica elegible sigue disponible para pickup aunque todavía no tenga `district_id`. Al editarla, el administrador debe regularizar su territorio.
- Una tarifa histórica sin `district_id` conserva temporalmente la resolución textual estricta y normalizada. Al editarla o reactivarla debe seleccionarse un distrito del catálogo.
- Los pedidos mantienen sus snapshots; renombrar el catálogo no altera pedidos, pagos, reservas ni movimientos históricos.

Cuando todos los registros hayan sido revisados, una migración futura podrá hacer obligatorias las relaciones territoriales. Esa migración debe ejecutarse solo después de validar que no existan relaciones nulas.

## Formato recomendado para una importación oficial futura

La importación debe provenir de una fuente oficial verificada y procesarse primero en modo validación. Formato CSV UTF-8 sugerido:

```csv
department_code,department_name,province_code,province_name,district_code,district_name,ubigeo
```

Reglas mínimas:

- no asumir códigos o ubigeos ausentes;
- rechazar duplicados después de normalizar tildes, mayúsculas y espacios;
- validar unicidad global de `ubigeo` cuando exista;
- validar que cada provincia tenga un único departamento y cada distrito una única provincia;
- producir un informe de altas, coincidencias y conflictos antes de escribir datos;
- ejecutar la escritura completa dentro de una transacción.
