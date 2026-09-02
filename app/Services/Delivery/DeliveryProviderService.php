<?php

namespace App\Services\Delivery;

use App\Models\DeliveryProvider;

/** CRUD for a shop's courier list, filtered by the ambient tenant scope. */
class DeliveryProviderService
{
    public function create(array $data): DeliveryProvider
    {
        return DeliveryProvider::create($data);
    }

    public function update(DeliveryProvider $provider, array $data): DeliveryProvider
    {
        $provider->update($data);

        return $provider->fresh();
    }

    /**
     * A hard delete, safe ONLY because orders snapshot delivery_provider_name:
     * the FK is nullOnDelete, so past orders lose the link but keep the name.
     * Without that snapshot this would have to be a soft delete.
     *
     * What's lost is grouping by id — the intended trade, since a shop deleting
     * a courier is saying it's done with it.
     */
    public function delete(DeliveryProvider $provider): void
    {
        $provider->delete();
    }
}
