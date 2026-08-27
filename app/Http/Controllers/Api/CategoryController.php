<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\CategoryResource;
use App\Models\Category;

class CategoryController extends Controller
{
    /**
     * Unpaginated on purpose: this exists to populate pickers/dropdowns
     * (e.g. the products filter's category_id), and a shop's category list
     * is admin-curated and small — unlike products, which paginate because
     * the catalog itself can grow large.
     */
    public function index()
    {
        return CategoryResource::collection(Category::orderBy('name')->get());
    }
}
