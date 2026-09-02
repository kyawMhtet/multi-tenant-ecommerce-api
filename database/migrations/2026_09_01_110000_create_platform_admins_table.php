<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Staff of the PLATFORM, not of any shop. A separate table from `users`,
     * and that separation is the entire security design rather than tidiness.
     *
     * The tempting alternative — an is_super_admin flag, or a User row with
     * tenant_id = null — is actively dangerous here. TenantScope::apply() only
     * adds its filter when currentTenantId() is truthy, and currentTenantId()
     * falls back to auth()->user()?->tenant_id. A null-tenant User therefore
     * resolves to null, the scope adds NO WHERE CLAUSE AT ALL, and that
     * account silently reads every tenant's products, orders and customers
     * through the existing endpoints. Not by design — by accident of a null
     * check.
     *
     * With a separate model there is no tenant_id to be null and no row in
     * `users` to authenticate as, so a platform admin cannot be resolved into
     * a tenant context even by a bug. The isolation is structural rather than
     * conditional — the same reason ResolveTenant derives the tenant from the
     * token owner instead of validating a header.
     *
     * No tenant_id column, no BelongsToTenant, and deliberately no
     * relationship to any tenant-owned model.
     */
    public function up(): void
    {
        Schema::create('platform_admins', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            // Its own unique index, independent of users.email. The same
            // person may legitimately be both a platform admin and the owner
            // of a shop on the platform, and those are different accounts
            // with different powers.
            $table->string('email')->unique();
            $table->string('password');
            // Revoking access without deleting the row, so the audit trail on
            // approved invoices keeps naming who approved them.
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_login_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_admins');
    }
};
