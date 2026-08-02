<?php

namespace App\Contracts;

use App\Models\OrderDelivery;

interface CourierProviderInterface
{
    public function createShipment(OrderDelivery $delivery): array;
    public function getTracking(OrderDelivery $delivery): array;
}
