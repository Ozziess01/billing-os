<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('external_id', 120)->nullable();
            $table->string('name', 200);
            $table->string('email', 254)->nullable();
            $table->text('description')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'created_at']);
            $table->index(['organization_id', 'email']);
        });

        // external_id - идентификатор клиента в системе интегратора: уникален в организации, если задан
        DB::statement('CREATE UNIQUE INDEX customers_org_external_id_unique ON customers (organization_id, external_id) WHERE external_id IS NOT NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
