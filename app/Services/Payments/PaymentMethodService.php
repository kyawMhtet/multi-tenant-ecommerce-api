<?php

namespace App\Services\Payments;

use App\Models\TenantPaymentMethod;
use App\Services\ImageUploadService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Throwable;

class PaymentMethodService
{
    public function __construct(private readonly ImageUploadService $imageUploadService) {}

    /**
     * Creates or updates one method for a shop, keyed on the method code.
     *
     * Upsert rather than create/update as separate operations because
     * (tenant_id, method) is unique by design — a shop configures each
     * method once and toggles it with is_enabled, so its QR and
     * instructions survive being switched off and on again. A client that
     * had to know whether a row already existed would just be reproducing
     * that constraint badly.
     *
     * The gateway is resolved from the catalogue, never taken from input:
     * which processor backs a method isn't the shop's choice.
     *
     * File ordering mirrors TenantService::update() exactly — new file
     * written before the DB write and deleted by the catch if anything
     * fails; the old file deleted only via DB::afterCommit once the write
     * is known to have committed. Registering that delete earlier would be
     * worse than useless: outside a transaction afterCommit() fires
     * immediately, so a later failure would take a live file with it.
     */
    public function upsert(array $data): TenantPaymentMethod
    {
        [$method, $oldQr] = DB::transaction(function () use ($data) {
            $method = TenantPaymentMethod::firstOrNew(['method' => $data['method']]);
            $oldQr = $method->qr_path;
            $newQr = null;

            try {
                $attributes = Arr::only($data, ['is_enabled', 'sort_order', 'instructions']);
                $attributes['gateway'] = PaymentMethodCatalog::gatewayFor($data['method']);

                if (($file = $data['qr'] ?? null) instanceof UploadedFile) {
                    $newQr = $this->imageUploadService->store($file, 'payment-methods/'.app('tenant')->id);
                    $attributes['qr_path'] = $newQr;
                } elseif (! empty($data['remove_qr'])) {
                    $attributes['qr_path'] = null;
                }

                $method->fill($attributes)->save();
            } catch (Throwable $e) {
                if ($newQr !== null) {
                    $this->imageUploadService->delete($newQr);
                }

                throw $e;
            }

            // Only queue the old file for deletion if it was actually
            // replaced or cleared — a save that never touched the QR must
            // leave it alone.
            $replaced = array_key_exists('qr_path', $attributes) && $attributes['qr_path'] !== $oldQr;

            return [$method, $replaced ? $oldQr : null];
        });

        if ($oldQr !== null) {
            DB::afterCommit(fn () => $this->imageUploadService->delete($oldQr));
        }

        return $method;
    }
}
