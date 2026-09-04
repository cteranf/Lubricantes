# Seeder territorial oficial

`OfficialTerritorySeeder` carga la línea base territorial de LubriStore desde
`database/data/territories/inei_ubigeo_2022_1891.csv`.

Fuente: INEI, dataset **Ubigeos (Códigos de Ubicación Geográfica)**, recurso
**Data Completa - Ubigeos**. El archivo original declarado por el catálogo es
`UBIGEO 2022_1891 distritos.xlsx` (catálogo 2022, publicado en septiembre de
2023). El CSV normalizado contiene 1,891 distritos, 25 departamentos y 196
provincias. SHA-256 del XLSX original:
`4270794025045dc2a994e16f9b6dd508c3e0019cb26dfab1d339d1ec57d7d77e`.
SHA-256 del CSV: `3e05506c50da1982c13bb4b48cde1bb40c01c349e0151d84a878865561eeb41a`.

La transformación conserva las siete columnas del contrato y trata todos los
códigos como texto: departamento (2), provincia (4), distrito/UBIGEO (6).
Los códigos provinciales y distritales se validan por prefijo jerárquico.

El seeder valida checksum, BOM, cabeceras, cantidades, nombres, duplicados y
jerarquías antes de abrir una transacción. Solo crea identidades ausentes;
conserva IDs, no elimina, desactiva ni renombra registros existentes. Cualquier
conflicto aborta y revierte todo. No crea `TerritoryImport` ni requiere usuario.

Para una instalación nueva:

```text
php artisan migrate
php artisan db:seed --class=OfficialTerritorySeeder
```

Las actualizaciones posteriores deben realizarse mediante Administración →
División territorial → Importar catálogo, usando vista previa, reporte y
confirmación. El catálogo de 2022 es una línea base y no se afirma que sea
permanentemente vigente.
