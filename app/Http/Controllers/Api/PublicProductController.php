<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\IndexPublicProductRequest;
use App\Http\Resources\StorefrontProductResource;
use App\Services\StorefrontProductService;

class PublicProductController extends Controller
{
    public function __construct(private readonly StorefrontProductService $storefrontProductService) {}

    /**
     * A shop's own catalog listing — sits behind the 'tenant' middleware
     * (X-Tenant-Slug), unlike show() below, since a storefront homepage
     * always knows its own tenant. 'tenant' isn't eager-loaded per item
     * here (unlike findPublicVariant()'s single-product load), so
     * StorefrontProductResource's 'shop' key is cleanly omitted from every
     * row — the homepage already has that from GET /public/shop once for
     * its layout, not repeated per product.
     */
    public function index(IndexPublicProductRequest $request)
    {
        return StorefrontProductResource::collection(
            $this->storefrontProductService->listPublicProducts($request->validated())
        );
    }

    public function show(string $slug)
    {
        $variant = $this->storefrontProductService->findPublicVariant($slug);

        return new StorefrontProductResource($variant->product);
    }
}
