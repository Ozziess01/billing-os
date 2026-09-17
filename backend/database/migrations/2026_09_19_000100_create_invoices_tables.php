<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->unsignedInteger('next_invoice_number')->default(1);
            $table->text('webhook_secret')->nullable();
        });

        Schema::create('invoices', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('customer_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('subscription_id')->nullable()->constrained()->nullOnDelete();
            $table->string('number', 30)->nullable();
            $table->string('status', 20);
            $table->char('currency', 3);
            $table->bigInteger('subtotal')->default(0);
            $table->bigInteger('discount')->default(0);
            $table->bigInteger('total')->default(0);
            $table->bigInteger('amount_paid')->default(0);
            $table->bigInteger('amount_due')->default(0);
            $table->string('description', 500)->nullable();
            $table->timestampTz('period_start')->nullable();
            $table->timestampTz('period_end')->nullable();
            $table->timestampTz('due_at')->nullable();
            $table->timestampTz('finalized_at')->nullable();
            $table->timestampTz('paid_at')->nullable();
            $table->timestampTz('voided_at')->nullable();
            $table->timestampTz('uncollectible_at')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'number']);
            $table->index(['organization_id', 'status']);
            $table->index('customer_id');
            $table->index('subscription_id');
        });

        Schema::create('invoice_items', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('price_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('description', 300);
            $table->unsignedInteger('quantity');
            $table->bigInteger('unit_amount');
            $table->bigInteger('amount');
            $table->char('currency', 3);
            $table->timestampTz('period_start')->nullable();
            $table->timestampTz('period_end')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->index('invoice_id');
        });

        DB::statement("ALTER TABLE invoices ADD CONSTRAINT invoices_status_known CHECK (status IN ('draft', 'open', 'paid', 'void', 'uncollectible'))");
        DB::statement('ALTER TABLE invoices ADD CONSTRAINT invoices_amounts_nonnegative CHECK (subtotal >= 0 AND discount >= 0 AND total >= 0 AND amount_paid >= 0 AND amount_due >= 0)');
        DB::statement('ALTER TABLE invoices ADD CONSTRAINT invoices_total_consistent CHECK (total = subtotal - discount AND amount_due = total - amount_paid)');
        DB::statement("ALTER TABLE invoices ADD CONSTRAINT invoices_number_when_finalized CHECK (status = 'draft' OR number IS NOT NULL)");
        DB::statement('ALTER TABLE invoice_items ADD CONSTRAINT invoice_items_quantity_positive CHECK (quantity > 0)');
        DB::statement('ALTER TABLE invoice_items ADD CONSTRAINT invoice_items_amount_consistent CHECK (unit_amount >= 0 AND amount = unit_amount * quantity)');

        // После финализации инвойс - финансовый документ: суммы, позиции и номер не меняются,
        // а из paid нельзя вернуться в open. Это держит сама база, а не только код.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION invoices_guard() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    IF OLD.status <> 'draft' THEN
                        RAISE EXCEPTION 'invoice % is finalized and cannot be deleted', OLD.id USING ERRCODE = 'restrict_violation';
                    END IF;
                    RETURN OLD;
                END IF;

                IF OLD.status <> NEW.status AND NOT (
                    (OLD.status = 'draft' AND NEW.status IN ('open', 'void'))
                    OR (OLD.status = 'open' AND NEW.status IN ('paid', 'void', 'uncollectible'))
                ) THEN
                    RAISE EXCEPTION 'invoice % cannot go from % to %', OLD.id, OLD.status, NEW.status USING ERRCODE = 'check_violation';
                END IF;

                IF OLD.status <> 'draft' AND (
                    NEW.currency <> OLD.currency
                    OR NEW.subtotal <> OLD.subtotal
                    OR NEW.discount <> OLD.discount
                    OR NEW.total <> OLD.total
                    OR NEW.customer_id <> OLD.customer_id
                    OR NEW.number IS DISTINCT FROM OLD.number
                    OR NEW.finalized_at IS DISTINCT FROM OLD.finalized_at
                ) THEN
                    RAISE EXCEPTION 'invoice % is finalized: financial fields are immutable', OLD.id USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER invoices_guard BEFORE UPDATE OR DELETE ON invoices
                FOR EACH ROW EXECUTE FUNCTION invoices_guard();

            CREATE OR REPLACE FUNCTION invoice_items_guard() RETURNS trigger AS $$
            DECLARE
                invoice_status text;
            BEGIN
                SELECT status INTO invoice_status FROM invoices WHERE id = COALESCE(NEW.invoice_id, OLD.invoice_id);
                IF invoice_status IS NOT NULL AND invoice_status <> 'draft' THEN
                    RAISE EXCEPTION 'invoice % is finalized: items are immutable', COALESCE(NEW.invoice_id, OLD.invoice_id) USING ERRCODE = 'check_violation';
                END IF;
                RETURN COALESCE(NEW, OLD);
            END
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER invoice_items_guard BEFORE INSERT OR UPDATE OR DELETE ON invoice_items
                FOR EACH ROW EXECUTE FUNCTION invoice_items_guard();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS invoice_items_guard ON invoice_items; DROP FUNCTION IF EXISTS invoice_items_guard();');
        DB::unprepared('DROP TRIGGER IF EXISTS invoices_guard ON invoices; DROP FUNCTION IF EXISTS invoices_guard();');
        Schema::dropIfExists('invoice_items');
        Schema::dropIfExists('invoices');
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn(['next_invoice_number', 'webhook_secret']);
        });
    }
};
