<?php

namespace Database\Seeders;

use App\Models\DeliveryProvider;
use App\Models\Tenant;
use App\Models\TenantPaymentMethod;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class TenantSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = Tenant::create([
            'name' => 'Yangon Test Shop',
            'slug' => 'test-shop',
            'owner_name' => 'Test Owner',
            'owner_email' => 'owner@test-shop.test',
            'owner_phone' => '09123456789',
            'currency' => 'MMK',
            'timezone' => 'Asia/Yangon',
            'delivery_fee' => 2000,
            'is_active' => true,
        ]);

        $password = 'password';

        $user = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Test Owner',
            'email' => 'owner@test-shop.test',
            'password' => Hash::make($password),
            'role' => 'owner',
        ]);

        // The same call AuthService::register() makes. Without it the seeded
        // shop would have no subscription at all — a state no real tenant can
        // reach, and one that every entitlement gate would have to special-case.
        app(\App\Services\Billing\SubscriptionService::class)->startTrial($tenant);

        // Bound so BelongsToTenant's creating hook fills tenant_id on the
        // rows below, exactly as a real request would — never mass-assigned.
        app()->instance('tenant', $tenant);

        try {
            // Cash on delivery and a QR transfer: the two manual methods
            // that need no gateway and no onboarding, which is what most
            // shops here actually use.
            TenantPaymentMethod::create(['method' => 'cod', 'gateway' => null, 'is_enabled' => true, 'sort_order' => 0]);
            TenantPaymentMethod::create([
                'method' => 'qr_transfer', 'gateway' => null, 'is_enabled' => true, 'sort_order' => 1,
                'instructions' => 'Transfer to KBZPay 09123456789 and upload your screenshot.',
            ]);

            foreach ([
                ['name' => 'Royal Express', 'phone' => '09777000111', 'sort_order' => 0],
                ['name' => 'Ninja Van', 'phone' => '09777000222', 'sort_order' => 1],
                ['name' => 'Our own rider', 'phone' => null, 'sort_order' => 2, 'note' => 'Same-day, inside Yangon only.'],
            ] as $provider) {
                DeliveryProvider::create($provider);
            }
        } finally {
            app()->forgetInstance('tenant');
        }

        $this->command->info('Test tenant seeded.');
        $this->command->info('  X-Tenant-Slug: '.$tenant->slug);
        $this->command->info('  email:         '.$user->email);
        $this->command->info('  password:      '.$password);
        $this->command->info('  plan:          '.$tenant->subscription->plan.' (trial)');
    }
}
