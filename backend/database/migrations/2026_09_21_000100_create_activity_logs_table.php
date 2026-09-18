<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('actor_type', 20);
            $table->string('actor_id', 26)->nullable();
            $table->string('actor_label', 200)->nullable();
            $table->string('action', 60);
            $table->string('resource_type', 40)->nullable();
            $table->string('resource_id', 26)->nullable();
            $table->jsonb('metadata')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 300)->nullable();
            $table->timestampTz('created_at');

            $table->index(['organization_id', 'created_at']);
            $table->index(['resource_type', 'resource_id']);
            $table->index('action');
        });

        DB::statement("ALTER TABLE activity_logs ADD CONSTRAINT activity_logs_actor_type_known CHECK (actor_type IN ('user', 'api_key', 'customer', 'system'))");

        // Журнал только дописывается. UPDATE/DELETE проходит лишь у обслуживания (ротация),
        // которое явно выставляет флаг в своей транзакции: SET LOCAL billingos.audit_maintenance = '1'
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION activity_logs_immutable() RETURNS trigger AS $$
            BEGIN
                IF current_setting('billingos.audit_maintenance', true) = '1' THEN
                    RETURN COALESCE(NEW, OLD);
                END IF;
                RAISE EXCEPTION 'activity_logs is append-only (% denied)', TG_OP USING ERRCODE = 'restrict_violation';
            END
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER activity_logs_immutable BEFORE UPDATE OR DELETE ON activity_logs
                FOR EACH ROW EXECUTE FUNCTION activity_logs_immutable();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS activity_logs_immutable ON activity_logs; DROP FUNCTION IF EXISTS activity_logs_immutable();');
        Schema::dropIfExists('activity_logs');
    }
};
