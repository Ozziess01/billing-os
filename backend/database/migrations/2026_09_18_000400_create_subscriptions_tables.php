<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('customer_id')->constrained()->restrictOnDelete();
            $table->string('status', 20);
            $table->char('currency', 3);
            $table->timestampTz('trial_ends_at')->nullable();
            $table->timestampTz('current_period_start');
            $table->timestampTz('current_period_end');
            $table->boolean('cancel_at_period_end')->default(false);
            $table->timestampTz('canceled_at')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'status']);
            $table->index('customer_id');
            $table->index('current_period_end');
        });

        Schema::create('subscription_items', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('subscription_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('price_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('quantity')->default(1);
            $table->timestamps();

            $table->unique(['subscription_id', 'price_id']);
        });

        DB::statement("ALTER TABLE subscriptions ADD CONSTRAINT subscriptions_status_known CHECK (status IN ('trialing', 'active', 'past_due', 'canceled', 'incomplete'))");
        DB::statement('ALTER TABLE subscriptions ADD CONSTRAINT subscriptions_period_order CHECK (current_period_end > current_period_start)');
        DB::statement('ALTER TABLE subscription_items ADD CONSTRAINT subscription_items_quantity_positive CHECK (quantity > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_items');
        Schema::dropIfExists('subscriptions');
    }
};
