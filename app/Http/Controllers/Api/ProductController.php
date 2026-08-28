<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\IndexProductRequest;
use App\Http\Requests\StoreProductRequest;
use App\Http\Requests\UpdateProductRequest;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use App\Services\ProductService;
use Illuminate\Http\Response;

class ProductController extends Controller
{
    public function __construct(private readonly ProductService $productService) {}

    public function index(IndexProductRequest $request)
    {
        return ProductResource::collection(
            $this->productService->listProducts($request->filters())
        );
    }


    public function store(StoreProductRequest $request)
    {
        $product = $this->productService->createProduct($request->validated());

        return (new ProductResource($product))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(Product $product)
    {
        return new ProductResource($product->load('variants.images', 'images'));
    }

    public function update(UpdateProductRequest $request, Product $product)
    {
        $product = $this->productService->updateProduct($product, $request->validated());

        return new ProductResource($product->load('variants.images', 'images'));
    }

    public function destroy(Product $product)
    {
        $this->productService->deleteProduct($product);

        return response()->json(null, Response::HTTP_NO_CONTENT);
    }
}
