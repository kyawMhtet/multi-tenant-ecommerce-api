<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreProductVariantRequest;
use App\Http\Requests\StoreRestockRequest;
use App\Http\Requests\UpdateProductVariantRequest;
use App\Http\Resources\ProductVariantResource;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\ProductService;
use App\Services\StockService;
use Illuminate\Http\Response;

class ProductVariantController extends Controller
{
    public function __construct(
        private readonly ProductService $productService,
        private readonly StockService $stockService,
    ) {}

    public function store(StoreProductVariantRequest $request, Product $product)
    {
        $variant = $this->productService->addVariant($product, $request->validated());

        return (new ProductVariantResource($variant))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function update(UpdateProductVariantRequest $request, Product $product, ProductVariant $variant)
    {
        $variant = $this->productService->updateVariant($product, $variant, $request->validated());

        return new ProductVariantResource($variant);
    }

    public function restock(StoreRestockRequest $request, Product $product, ProductVariant $variant)
    {
        $variant = $this->stockService->receivePurchase(
            $product,
            $variant,
            (float) $request->validated('quantity'),
            $request->validated('unit_cost'),
            $request->validated('note'),
        );

        // restock() never touches images, so they're never loaded by the
        // service — loadMissing() here keeps this endpoint's response
        // shape consistent with store()/update()'s (images always
        // present, even if empty) without a wasted query on either side.
        return new ProductVariantResource($variant->loadMissing('images'));
    }
}
