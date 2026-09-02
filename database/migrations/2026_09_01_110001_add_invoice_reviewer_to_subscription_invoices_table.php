<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Renames the approval audit columns to REVIEW columns, and makes the
     * reviewer a real foreign key now that platform_admins exists.
     *
     * The rename matters because a human ruling on a bank transfer can rule
     * either way. `approved_by` holding the id of someone who REJECTED a
     * forged screenshot would be a column whose name lies, and audit columns
     * that lie are worse than none. So:
     *
     *   reviewed_by / reviewed_at — who ruled on this claim, and when
     *   status                    — what they decided (paid / failed)
     *   paid_at                   — when money was confirmed received
     *
     * `approved_by` was always documented as becoming an FK to
     * platform_admins and NEVER to `users` — every user belongs to a tenant,
     * so that FK would model "a shop approved its own payment". This makes
     * good on that.
     *
     * No data migration: nothing has ever been reviewed, because until now
     * there was no code path that could review anything.
     *
     * nullOnDelete, not cascade — deleting a staff account must not delete the
     * record of payments they confirmed. An approval with an unknown reviewer
     * is a worse audit trail than a named one, and a far better one than a
     * missing invoice.
     */
    public function up(): void
    {
        Schema::table('subscription_invoices', function (Blueprint $table) {
            $table->dropColumn('approved_by');
            $table->renameColumn('approved_at', 'reviewed_at');
        });

        Schema::table('subscription_invoices', function (Blueprint $table) {
            $table->foreignId('reviewed_by')
                ->nullable()
                ->after('proof_path')
                ->constrained('platform_admins')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('subscription_invoices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reviewed_by');
        });

        Schema::table('subscription_invoices', function (Blueprint $table) {
            $table->renameColumn('reviewed_at', 'approved_at');
            $table->string('approved_by')->nullable()->after('proof_path');
        });
    }
};
