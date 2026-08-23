<?php

return [
    'max_kilobytes' => (int) env('TERRITORY_IMPORT_MAX_KILOBYTES', 2048),
    'max_rows' => (int) env('TERRITORY_IMPORT_MAX_ROWS', 10000),
    'preview_rows' => (int) env('TERRITORY_IMPORT_PREVIEW_ROWS', 50),
    'preview_ttl_minutes' => (int) env('TERRITORY_IMPORT_PREVIEW_TTL_MINUTES', 60),
];
