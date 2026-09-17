<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('key', 120);
            $table->char('request_hash', 64);
            $table->string('status', 20);
            $table->unsignedSmallInteger('response_status')->nullable();
            // тело ответа как есть: байт в байт при повторе
            $table->text('response_body')->nullable();
            $table->timestampTz('expires_at');
            $table->timestamps();

            $table->unique(['organization_id', 'key']);
            $table->index('expires_at');
        });

        DB::statement("ALTER TABLE idempotency_keys ADD CONSTRAINT idempotency_keys_status_known CHECK (status IN ('processing', 'completed'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
    }
};
