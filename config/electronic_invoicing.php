<?php

return [
    // Commercial entitlement is deployment-controlled and cannot be edited in the profile API.
    'enabled' => (bool) env('ELECTRONIC_INVOICING_ENABLED', false),
];
