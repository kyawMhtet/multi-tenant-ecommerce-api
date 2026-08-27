<?php

namespace Database\Seeders;

use App\Models\Tenant;
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
            // plan/subscription_status are intentionally NOT set here —
            // they were removed from Tenant::$fillable (nothing in the app
            // writes them), so they'd be silently discarded anyway. The
            // 'trial' column defaults produce the same result.
            'currency' => 'MMK',
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

        $this->command->info('Test tenant seeded.');
        $this->command->info('  X-Tenant-Slug: '.$tenant->slug);
        $this->command->info('  email:         '.$user->email);
        $this->command->info('  password:      '.$password);
    }
}
