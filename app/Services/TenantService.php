<?php

namespace App\Services;

use App\Models\Tenant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Throwable;

class TenantService
{
    /**
     * Defence-in-depth behind UpdateTenantRequest's whitelist — the service
     * builds this array by hand anyway, so naming the fields costs nothing and
     * means loosened validation can't reach slug, is_active or currency.
     */
    private const PROFILE_FIELDS = ['name', 'address', 'business_phone', 'business_email', 'timezone', 'allows_delivery', 'allows_pickup', 'delivery_fee'];

    /**
     * Keys this service owns inside the shared settings JSON blob.
     */
    private const SETTINGS_KEYS = ['business_hours', 'social_links'];

    public function __construct(private readonly ImageUploadService $imageUploadService) {}

    /**
     * lockForUpdate() because the settings merge is a read-modify-write: two
     * concurrent PATCHes would otherwise both read the pre-state and one key
     * would be lost.
     *
     * File ordering: new files are written BEFORE the update and deleted by
     * the catch on any failure; old files only via afterCommit, AFTER update()
     * succeeded. Registering the delete earlier is worse than useless —
     * outside a transaction afterCommit fires immediately.
     *
     * The residual risk is one-directional on purpose: a failed commit orphans
     * a new file (wasted bytes), but a committed row can never point at a file
     * that's already gone.
     */
    public function update(Tenant $tenant, array $data): Tenant
    {
        [$updated, $toDelete] = DB::transaction(function () use ($tenant, $data) {
            $locked = Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();

            $oldPaths = ['logo' => $locked->logo_path, 'cover' => $locked->cover_path];
            $newPaths = [];
            $toDelete = [];
            $attributes = Arr::only($data, self::PROFILE_FIELDS);

            try {
                foreach (['logo', 'cover'] as $image) {
                    $column = "{$image}_path";

                    if (($file = $data[$image] ?? null) instanceof UploadedFile) {
                        $attributes[$column] = $this->imageUploadService->store($file, 'tenants/'.$locked->id);
                        $newPaths[] = $attributes[$column];
                    } elseif (! empty($data["remove_{$image}"])) {
                        $attributes[$column] = null;
                    } else {
                        continue;
                    }

                    if ($oldPaths[$image] !== null) {
                        $toDelete[] = $oldPaths[$image];
                    }
                }

                if ($settings = $this->mergedSettings($locked, $data)) {
                    $attributes['settings'] = $settings;
                }

                $locked->update($attributes);
            } catch (Throwable $e) {
                foreach ($newPaths as $path) {
                    $this->imageUploadService->delete($path);
                }

                throw $e;
            }

            return [$locked, $toDelete];
        });

        foreach ($toDelete as $path) {
            DB::afterCommit(fn () => $this->imageUploadService->delete($path));
        }

        return $updated;
    }

    /**
     * Merges rather than replaces: settings is a shared bucket, and a future
     * feature storing settings.receipt_footer must not be wiped by a profile
     * save. Platform keys merge individually so a client can clear one link
     * without resending the rest. Nulls are stripped, never stored.
     */
    private function mergedSettings(Tenant $tenant, array $data): ?array
    {
        if (! Arr::hasAny($data, self::SETTINGS_KEYS)) {
            return null;
        }

        $settings = $tenant->settings ?? [];

        if (array_key_exists('business_hours', $data)) {
            $settings['business_hours'] = $data['business_hours'];
        }

        if (array_key_exists('social_links', $data)) {
            $links = array_merge($settings['social_links'] ?? [], $data['social_links'] ?? []);
            $settings['social_links'] = array_filter($links, fn ($value) => $value !== null && $value !== '');
        }

        return array_filter($settings, fn ($value) => $value !== null);
    }
}
