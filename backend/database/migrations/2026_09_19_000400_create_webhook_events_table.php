<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_events', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('organization_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('provider', 30);
            $table->string('event_id', 120);
            $table->string('type', 60);
            $table->jsonb('payload');
            $table->string('status', 20);
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('error')->nullable();
            $table->timestampTz('processed_at')->nullable();
            $table->timestamps();

            // тот же event_id провайдера - обработан не более одного раза
            $table->unique(['provider', 'event_id']);
            $table->index(['organization_id', 'created_at']);
            $table->index('status');
        });

        DB::statement("ALTER TABLE webhook_events ADD CONSTRAINT webhook_events_status_known CHECK (status IN ('received', 'processing', 'processed', 'ignored', 'failed'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_events');
    }
};
