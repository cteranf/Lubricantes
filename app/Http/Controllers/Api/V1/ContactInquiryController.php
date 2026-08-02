<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreContactInquiryRequest;
use App\Services\ContactFormSecurity;
use App\Services\ContactInquiryService;
use Illuminate\Http\Request;

class ContactInquiryController extends Controller
{
    public function context(Request $request, ContactFormSecurity $security)
    {
        $data = $request->validate(['submission_token' => ['required', 'uuid']]);
        return response()->json($security->context($data['submission_token']));
    }

    public function store(StoreContactInquiryRequest $request, ContactInquiryService $service)
    {
        $result = $service->create(
            $request->safe()->except(['website', 'form_started_at', 'form_signature', 'form_token']),
            $request->user('sanctum'),
            $request->ip()
        );

        return response()->json([
            'message' => 'Recibimos tu consulta. Nuestro equipo se comunicará contigo a la brevedad.',
            'reference' => $result['inquiry']->public_id,
        ], $result['created'] ? 201 : 200);
    }
}
