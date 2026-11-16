<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\BrandResource;
use App\Models\Brand;

class BrandController extends Controller
{
    public function index()
    {
        return response()->json(BrandResource::collection(Brand::all())->resolve(request()));
    }

    public function show($slug)
    {
        return response()->json((new BrandResource(Brand::where('slug', $slug)->firstOrFail()))->resolve(request()));
    }
}
