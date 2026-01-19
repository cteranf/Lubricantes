<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Category;
use Illuminate\Http\Request;

class CategoryController extends Controller
{
    public function index()
    {
        return Category::whereNull('parent_id')->with('children')->get();
    }

    public function show($slug)
    {
        return Category::with('children')->where('slug', $slug)->firstOrFail();
    }
}
