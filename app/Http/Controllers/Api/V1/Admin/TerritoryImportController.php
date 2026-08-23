<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\TerritoryImport;
use App\Services\TerritoryImportService;
use Illuminate\Http\Request;

class TerritoryImportController extends Controller
{
    public function template()
    {
        $csv = "\xEF\xBB\xBF".implode(',', TerritoryImportService::HEADERS)."\r\n";

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="plantilla-catalogo-territorial-peru.csv"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function preview(Request $request, TerritoryImportService $service)
    {
        $data = $request->validate(['file' => 'required|file']);

        return response()->json($service->preview($data['file'], $request->user()));
    }

    public function confirm(Request $request, TerritoryImportService $service)
    {
        $data = $request->validate(['preview_token' => 'required|string|max:200']);

        return response()->json($service->confirm($data['preview_token'], $request->user()), 201);
    }

    public function report(Request $request, TerritoryImportService $service)
    {
        $data = $request->validate(['preview_token' => 'required|string|max:200']);

        return response($service->report($data['preview_token'], $request->user()), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="reporte-importacion-territorial.csv"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function index()
    {
        return TerritoryImport::with('user:id,name,email')->latest()->paginate(20);
    }

    public function show(TerritoryImport $territoryImport)
    {
        return $territoryImport->load('user:id,name,email');
    }
}
