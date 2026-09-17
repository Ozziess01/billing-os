<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prices', function (Blueprint $table) {
            // licensed - фиксированная сумма за период × количество; metered - по отчётам об использовании
            $table->string('usage_type', 10)->default('licensed');
            // цена за единицу использования в минорных единицах с дробью: €0.001/запрос = 0.1 цента
            $table->decimal('unit_amount_decimal', 24, 8)->nullable();
        });

        Schema::create('usage_events', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('subscription_item_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('customer_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('quantity');
            $table->timestampTz('timestamp');
            $table->string('idempotency_key', 120)->nullable();
            $table->foreignUlid('invoice_item_id')->nullable()->constrained()->nullOnDelete();
            $table->jsonb('metadata')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['subscription_item_id', 'timestamp']);
            $table->index('invoice_item_id');
        });

        Schema::create('coupons', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('code', 40);
            $table->string('name', 120);
            $table->string('type', 10);
            $table->unsignedSmallInteger('percent_off')->nullable();
            $table->bigInteger('amount_off')->nullable();
            $table->char('currency', 3)->nullable();
            $table->string('duration', 10)->default('forever');
            $table->timestampTz('redeem_by')->nullable();
            $table->unsignedInteger('max_redemptions')->nullable();
            $table->unsignedInteger('times_redeemed')->default(0);
            $table->foreignUlid('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('active')->default(true);
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'code']);
        });

        Schema::create('coupon_redemptions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('coupon_id')->constrained()->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('subscription_id')->nullable()->constrained()->cascadeOnDelete();
            $table->timestampTz('redeemed_at');

            $table->index(['coupon_id', 'customer_id']);
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->foreignUlid('coupon_id')->nullable()->constrained()->nullOnDelete();
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->foreignUlid('coupon_id')->nullable()->constrained()->nullOnDelete();
        });

        DB::statement("ALTER TABLE prices ADD CONSTRAINT prices_usage_type_known CHECK (usage_type IN ('licensed', 'metered'))");
        DB::statement("ALTER TABLE prices ADD CONSTRAINT prices_metered_has_decimal CHECK (usage_type = 'licensed' OR (unit_amount_decimal IS NOT NULL AND unit_amount_decimal >= 0))");
        DB::statement('ALTER TABLE usage_events ADD CONSTRAINT usage_events_quantity_positive CHECK (quantity > 0)');
        // повтор отчёта с тем же ключом не создаёт второго события
        DB::statement('CREATE UNIQUE INDEX usage_events_org_key_unique ON usage_events (organization_id, idempotency_key) WHERE idempotency_key IS NOT NULL');
        DB::statement("ALTER TABLE coupons ADD CONSTRAINT coupons_type_known CHECK (type IN ('percent', 'fixed'))");
        DB::statement("ALTER TABLE coupons ADD CONSTRAINT coupons_duration_known CHECK (duration IN ('once', 'forever'))");
        DB::statement("ALTER TABLE coupons ADD CONSTRAINT coupons_value_matches_type CHECK ((type = 'percent' AND percent_off BETWEEN 1 AND 100 AND amount_off IS NULL) OR (type = 'fixed' AND amount_off > 0 AND currency IS NOT NULL AND percent_off IS NULL))");
        DB::statement('ALTER TABLE coupons ADD CONSTRAINT coupons_redemptions_within_limit CHECK (max_redemptions IS NULL OR times_redeemed <= max_redemptions)');
        DB::statement('CREATE UNIQUE INDEX coupon_redemptions_subscription_unique ON coupon_redemptions (coupon_id, subscription_id) WHERE subscription_id IS NOT NULL');
    }

    public function down(): void
    {
        Schema::table('invoices', fn (Blueprint $table) => $table->dropConstrainedForeignId('coupon_id'));
        Schema::table('subscriptions', fn (Blueprint $table) => $table->dropConstrainedForeignId('coupon_id'));
        Schema::dropIfExists('coupon_redemptions');
        Schema::dropIfExists('coupons');
        Schema::dropIfExists('usage_events');
        DB::statement('ALTER TABLE prices DROP CONSTRAINT IF EXISTS prices_metered_has_decimal');
        DB::statement('ALTER TABLE prices DROP CONSTRAINT IF EXISTS prices_usage_type_known');
        Schema::table('prices', fn (Blueprint $table) => $table->dropColumn(['usage_type', 'unit_amount_decimal']));
    }
};
