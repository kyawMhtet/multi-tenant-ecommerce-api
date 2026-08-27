<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class ProductImageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            // The stored value is a storage-relative path (see the
            // product_images migration) — never handed back as-is, since
            // nothing outside ImageUploadService should need to know
            // which disk or URL prefix it lives under.
            'url' => Storage::disk('public')->url($this->path),
            'sort_order' => $this->sort_order,
        ];
    }
}
