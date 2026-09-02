<?php

namespace App\Services\Payments;

use App\Models\TenantPaymentMethod;
use App\Services\Billing\PlanFeature;
use App\Services\Billing\PlanGate;
use App\Services\ImageUploadService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Throwable;

class PaymentMethodService
{
    public function __construct(
        private readonly ImageUploadService $imageUploadService,
        private readonly PlanGate $plans,
    ) {}

    /**
     * Upsert, because (tenant_id, method) is unique by design: a shop configures
     * each method once and toggles is_enabled, so its QR and instructions
     * survive being switched off and on.
     *
     * The gateway comes from the catalogue, never from input — which processor
     * backs a method isn't the shop's choice.
     *
     * File ordering mirrors TenantService::update(); see there for why the old
     * file is deleted only via afterCommit.
     */
    public function upsert(array $data): TenantPaymentMethod
    {
        $this->ensureMayUseGateway($data);

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

            // Only if actually replaced or cleared — a save that never touched
            // the QR must leave it alone.
            $replaced = array_key_exists('qr_path', $attributes) && $attributes['qr_path'] !== $oldQr;

            return [$method, $replaced ? $oldQr : null];
        });

        if ($oldQr !== null) {
            DB::afterCommit(fn () => $this->imageUploadService->delete($oldQr));
        }

        return $method;
    }

    /**
     * Gates ENABLING a method that needs a processor — today exactly `card`,
     * via Stripe Connect. Keyed on the catalogue's gateway rather than on the
     * method name, so a second processor is covered without being remembered
     * here; PlanFeature::CardPayments is named for what a shop owner sees,
     * and cards are what a processor means in this catalogue today.
     *
     * Manual methods (cod, qr_transfer) are never gated. They are the primary
     * path in this market, and a plan that could not take cash on delivery
     * would not be a plan anyone could sell a shop.
     *
     * DISABLING is never gated: is_enabled=false must always be allowed, or a
     * downgraded shop would be unable to switch off the method it can no
     * longer use.
     */
    private function ensureMayUseGateway(array $data): void
    {
        $needsProcessor = PaymentMethodCatalog::gatewayFor($data['method']) !== null;

        if ($needsProcessor && ($data['is_enabled'] ?? false)) {
            $this->plans->ensureFeature(PlanFeature::CardPayments);
        }
    }
}
