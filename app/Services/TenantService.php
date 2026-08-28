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
     * Explicitly allowlisted plain columns. Defence-in-depth behind
     * UpdateTenantRequest's whitelist: the service builds its update array
     * by hand anyway (it has to compute logo_path from an upload and merge
     * settings), so naming the fields costs nothing and means a future
     * loosening of validation can't reach slug, is_active or currency.
     */
    private const PROFILE_FIELDS = ['name', 'address', 'business_phone', 'business_email', 'allows_delivery', 'allows_pickup'];

    /**
     * Keys this service owns inside the shared settings JSON blob.
     */
    private const SETTINGS_KEYS = ['business_hours', 'social_links'];

    public function __construct(private readonly ImageUploadService $imageUploadService) {}

    /**
     * The row is re-fetched with lockForUpdate() for two reasons, both the
     * same shape as ProductService::addImages()'s lock: the settings merge
     * below is a read-modify-write (two concurrent PATCHes would otherwise
     * both read the pre-state and last-writer-wins would drop one key), and
     * it guarantees a trustworthy read of the old image paths before they're
     * overwritten.
     *
     * File ordering, mirroring addImages()/deleteImage():
     *   - New files are written BEFORE the DB update, and the catch block
     *     deletes them on any failure — disk I/O can't roll back, so
     *     without that a rollback would strand orphaned files.
     *   - Old files are deleted only via DB::afterCommit, and only after
     *     update() has already succeeded. Registering them earlier would
     *     be worse than useless: outside a transaction afterCommit() fires
     *     immediately, so a speculative registration would delete a live
     *     file on a later failure.
     *
     * The residual risk is deliberately one-directional. If the commit
     * itself fails after the try block exits, a newly written file is
     * orphaned — wasted bytes. What can never happen is a committed row
     * pointing at a file that's already gone. Same bias as addImages().
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
     * Merges only this service's own keys into the existing settings blob
     * rather than replacing it — settings is a shared bucket, and a future
     * unrelated feature storing e.g. settings.receipt_footer must not be
     * silently wiped by a shop-profile save.
     *
     * Social platform keys merge individually too, so a client can clear one
     * link (facebook: null) without resending the rest. Null/empty values are
     * stripped rather than stored, so the JSON never accumulates tombstones.
     *
     * Returns null when the request touched neither key, so the caller can
     * leave settings entirely alone.
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
