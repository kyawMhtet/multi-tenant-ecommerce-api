<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreDeliveryProviderRequest;
use App\Http\Requests\UpdateDeliveryProviderRequest;
use App\Http\Resources\DeliveryProviderResource;
use App\Models\DeliveryProvider;
use App\Services\Delivery\DeliveryProviderService;
use Illuminate\Http\Response;

class DeliveryProviderController extends Controller
{
    public function __construct(private readonly DeliveryProviderService $providers) {}

    /**
     * Unpaginated, same reasoning as CategoryController::index(): this
     * populates a picker on the order screen, and a shop's courier list is
     * admin-curated and small.
     */
    public function index()
    {
        return DeliveryProviderResource::collection(DeliveryProvider::ordered()->get());
    }

    public function store(StoreDeliveryProviderRequest $request)
    {
        $provider = $this->providers->create($request->validated());

        return (new DeliveryProviderResource($provider))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function update(UpdateDeliveryProviderRequest $request, DeliveryProvider $provider)
    {
        return new DeliveryProviderResource(
            $this->providers->update($provider, $request->validated())
        );
    }

    public function destroy(DeliveryProvider $provider)
    {
        $this->providers->delete($provider);

        return response()->noContent();
    }
}
