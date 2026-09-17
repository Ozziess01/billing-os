<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('customer_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('invoice_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('attempt_number');
            $table->string('status', 20);
            $table->char('currency', 3);
            $table->bigInteger('amount');
            $table->bigInteger('amount_refunded')->default(0);
            $table->string('provider', 30);
            $table->string('provider_payment_id', 120)->nullable();
            $table->string('payment_method', 120);
            $table->string('failure_code', 60)->nullable();
            $table->string('failure_message', 500)->nullable();
            $table->jsonb('next_action')->nullable();
            $table->timestampTz('succeeded_at')->nullable();
            $table->timestampTz('failed_at')->nullable();
            $table->timestampTz('canceled_at')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->unique(['invoice_id', 'attempt_number']);
            $table->unique(['provider', 'provider_payment_id']);
            $table->index(['organization_id', 'status']);
            $table->index('customer_id');
        });

        Schema::create('refunds', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('payment_id')->constrained()->restrictOnDelete();
            $table->string('status', 20);
            $table->char('currency', 3);
            $table->bigInteger('amount');
            $table->string('reason', 300)->nullable();
            $table->string('provider_refund_id', 120)->nullable();
            $table->string('failure_message', 500)->nullable();
            $table->timestampTz('succeeded_at')->nullable();
            $table->timestampTz('failed_at')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->index('payment_id');
            $table->index(['organization_id', 'status']);
        });

        DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_status_known CHECK (status IN ('pending', 'processing', 'succeeded', 'failed', 'canceled'))");
        DB::statement('ALTER TABLE payments ADD CONSTRAINT payments_amount_positive CHECK (amount > 0)');
        // возвращённое никогда не больше списанного - инвариант держит база
        DB::statement('ALTER TABLE payments ADD CONSTRAINT payments_refunded_within_amount CHECK (amount_refunded >= 0 AND amount_refunded <= amount)');
        // один инвойс - одна попытка в полёте: параллельные POST /payments упираются в этот индекс
        DB::statement("CREATE UNIQUE INDEX payments_one_in_flight ON payments (invoice_id) WHERE status IN ('pending', 'processing')");

        DB::statement("ALTER TABLE refunds ADD CONSTRAINT refunds_status_known CHECK (status IN ('pending', 'succeeded', 'failed'))");
        DB::statement('ALTER TABLE refunds ADD CONSTRAINT refunds_amount_positive CHECK (amount > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('refunds');
        Schema::dropIfExists('payments');
    }
};
