<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\ShippingRateService;
use Illuminate\Http\Request;

class CheckoutShippingQuoteController extends Controller
{
    public function __invoke(Request $request, ShippingRateService $rates)
    {
        $data = $request->validate(['address_id' => 'required|integer']);
        $address = $request->user()->savedAddresses()->where('is_active', true)->findOrFail($data['address_id']);
        $quote = $rates->quote($address);
        unset($quote['rate'], $quote['zone']);

        return response()->json($quote);
    }
}
