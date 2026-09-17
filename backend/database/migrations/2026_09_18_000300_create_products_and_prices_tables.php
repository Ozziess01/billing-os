<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name', 200);
            $table->text('description')->nullable();
            $table->boolean('active')->default(true);
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'active']);
        });

        Schema::create('prices', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('product_id')->constrained()->cascadeOnDelete();
            $table->string('nickname', 120)->nullable();
            $table->char('currency', 3);
            $table->bigInteger('unit_amount');
            $table->string('billing_interval', 10);
            $table->unsignedSmallInteger('interval_count')->default(1);
            $table->boolean('active')->default(true);
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'active']);
            $table->index('product_id');
        });

        // деньги - только целые неотрицательные минорные единицы; интервал - из фиксированного списка
        DB::statement('ALTER TABLE prices ADD CONSTRAINT prices_unit_amount_nonnegative CHECK (unit_amount >= 0)');
        DB::statement('ALTER TABLE prices ADD CONSTRAINT prices_interval_count_positive CHECK (interval_count BETWEEN 1 AND 365)');
        DB::statement("ALTER TABLE prices ADD CONSTRAINT prices_billing_interval_known CHECK (billing_interval IN ('day', 'week', 'month', 'year'))");
        DB::statement('ALTER TABLE prices ADD CONSTRAINT prices_currency_upper CHECK (currency = upper(currency) AND length(currency) = 3)');
    }

    public function down(): void
    {
        Schema::dropIfExists('prices');
        Schema::dropIfExists('products');
    }
};
