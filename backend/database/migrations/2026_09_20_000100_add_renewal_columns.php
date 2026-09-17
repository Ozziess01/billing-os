<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            // платёжный метод у провайдера, которым списываем продления
            $table->string('default_payment_method', 120)->nullable();
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->string('cancel_reason', 40)->nullable();
            $table->timestampTz('ended_at')->nullable();
        });

        Schema::table('invoices', function (Blueprint $table) {
            // автосписание: когда пробовать в следующий раз и сколько автоматических попыток уже было
            $table->boolean('auto_collect')->default(false);
            $table->timestampTz('next_payment_attempt_at')->nullable();
            $table->unsignedSmallInteger('collection_attempts')->default(0);

            $table->index('next_payment_attempt_at');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->boolean('automatic')->default(false);
        });

        DB::statement("ALTER TABLE subscriptions ADD CONSTRAINT subscriptions_cancel_reason_known CHECK (cancel_reason IS NULL OR cancel_reason IN ('requested', 'period_end', 'payment_failed', 'portal'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE subscriptions DROP CONSTRAINT IF EXISTS subscriptions_cancel_reason_known');
        Schema::table('payments', fn (Blueprint $table) => $table->dropColumn('automatic'));
        Schema::table('invoices', fn (Blueprint $table) => $table->dropColumn(['auto_collect', 'next_payment_attempt_at', 'collection_attempts']));
        Schema::table('subscriptions', fn (Blueprint $table) => $table->dropColumn(['cancel_reason', 'ended_at']));
        Schema::table('customers', fn (Blueprint $table) => $table->dropColumn('default_payment_method'));
    }
};
