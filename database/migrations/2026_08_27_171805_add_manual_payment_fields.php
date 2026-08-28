<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Support for manual payment methods — a shop's own QR code (PromptPay,
     * KBZPay, WavePay) that the customer scans and pays directly.
     *
     * This is the primary path for this product's actual users: Myanmar
     * nationals running shops and restaurants in Thailand, who frequently
     * can't complete a card processor's KYC (Thai bank account, work permit,
     * Thai-national assumptions in the forms). Money moves straight between
     * customer and shop; the platform only displays an image the shop
     * uploaded, so there is no KYC, no licensing and no liability here.
     *
     * qr_path is a real column rather than a key inside `config` for the
     * same reason tenants.logo_path is: replacing an image is a read-old/
     * write-new operation, and a column is a single atomic assignment while
     * a JSON sub-key is a read-modify-write that silently clobbers under
     * concurrent saves. A future "purge a deleted tenant's files" job also
     * wants SELECT qr_path, not JSON_EXTRACT.
     *
     * instructions is free text the shop writes for its own customers
     * ("KBZPay 09xxxxxxxxx, name U Aung — send screenshot after paying").
     * Deliberately not structured into account_name/account_number columns:
     * what a shop needs to tell a customer varies by wallet, by bank, and
     * by country, and guessing that shape now would just produce fields
     * half of them leave blank.
     */
    public function up(): void
    {
        Schema::table('tenant_payment_methods', function (Blueprint $table) {
            $table->string('qr_path')->nullable()->after('gateway');
            $table->text('instructions')->nullable()->after('qr_path');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_payment_methods', function (Blueprint $table) {
            $table->dropColumn(['qr_path', 'instructions']);
        });
    }
};
