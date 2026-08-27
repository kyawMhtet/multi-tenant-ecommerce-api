<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * No WithoutModelEvents here (deliberately removed): ProductSeeder
     * relies on BelongsToTenant's `creating` hook to auto-fill tenant_id
     * on every Product/ProductVariant/StockMovement/Category it creates.
     * That trait suppresses all model events for the whole seeding run,
     * which would silently break that auto-fill and fail on a NOT NULL
     * constraint instead.
     */
    public function run(): void
    {
        $this->call(TenantSeeder::class);
        $this->call(ProductSeeder::class);
        $this->call(OrderSeeder::class);
    }
}
