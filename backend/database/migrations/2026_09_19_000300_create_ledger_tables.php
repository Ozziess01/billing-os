<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ledger_accounts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20);
            $table->char('currency', 3);
            $table->string('name', 80);
            $table->timestamps();

            $table->unique(['organization_id', 'type', 'currency']);
        });

        Schema::create('ledger_transactions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('type', 30);
            $table->char('currency', 3);
            $table->bigInteger('amount');
            $table->string('description', 300);
            $table->string('reference_type', 30);
            $table->string('reference_id', 26);
            $table->timestampTz('posted_at');
            $table->jsonb('metadata')->nullable();
            $table->timestamp('created_at')->nullable();

            // одна проводка на одно событие: повторный вебхук или retry job не задвоят запись
            $table->unique(['type', 'reference_type', 'reference_id']);
            $table->index(['organization_id', 'posted_at']);
            $table->index(['reference_type', 'reference_id']);
        });

        Schema::create('ledger_entries', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('transaction_id')->constrained('ledger_transactions')->restrictOnDelete();
            $table->foreignUlid('account_id')->constrained('ledger_accounts')->restrictOnDelete();
            $table->bigInteger('debit')->default(0);
            $table->bigInteger('credit')->default(0);
            $table->timestamp('created_at')->nullable();

            $table->index('transaction_id');
            $table->index('account_id');
        });

        DB::statement("ALTER TABLE ledger_accounts ADD CONSTRAINT ledger_accounts_type_known CHECK (type IN ('cash', 'receivable', 'revenue', 'adjustments'))");
        DB::statement("ALTER TABLE ledger_transactions ADD CONSTRAINT ledger_transactions_type_known CHECK (type IN ('invoice', 'payment', 'refund', 'adjustment'))");
        DB::statement('ALTER TABLE ledger_transactions ADD CONSTRAINT ledger_transactions_amount_positive CHECK (amount > 0)');
        // у строки ровно одна сторона: либо дебет, либо кредит, и обе неотрицательные
        DB::statement('ALTER TABLE ledger_entries ADD CONSTRAINT ledger_entries_one_side CHECK (debit >= 0 AND credit >= 0 AND (debit = 0) <> (credit = 0))');

        DB::unprepared(<<<'SQL'
            -- Леджер append-only: ни одна проводка не редактируется и не удаляется, корректировки - новыми проводками
            CREATE OR REPLACE FUNCTION ledger_immutable() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'ledger is append-only (% on %)', TG_OP, TG_TABLE_NAME USING ERRCODE = 'restrict_violation';
            END
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER ledger_transactions_immutable BEFORE UPDATE OR DELETE ON ledger_transactions
                FOR EACH ROW EXECUTE FUNCTION ledger_immutable();
            CREATE TRIGGER ledger_entries_immutable BEFORE UPDATE OR DELETE ON ledger_entries
                FOR EACH ROW EXECUTE FUNCTION ledger_immutable();

            -- Баланс проверяется на commit (отложенный constraint-триггер): несбалансированная проводка не зафиксируется
            CREATE OR REPLACE FUNCTION ledger_transaction_balanced() RETURNS trigger AS $$
            DECLARE
                debits bigint;
                credits bigint;
                lines int;
            BEGIN
                SELECT coalesce(sum(debit), 0), coalesce(sum(credit), 0), count(*)
                    INTO debits, credits, lines
                    FROM ledger_entries WHERE transaction_id = NEW.transaction_id;
                IF lines < 2 OR debits <> credits THEN
                    RAISE EXCEPTION 'ledger transaction % is not balanced: debit %, credit %', NEW.transaction_id, debits, credits USING ERRCODE = 'check_violation';
                END IF;
                RETURN NULL;
            END
            $$ LANGUAGE plpgsql;

            CREATE CONSTRAINT TRIGGER ledger_entries_balanced AFTER INSERT ON ledger_entries
                DEFERRABLE INITIALLY DEFERRED
                FOR EACH ROW EXECUTE FUNCTION ledger_transaction_balanced();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS ledger_entries_balanced ON ledger_entries; DROP FUNCTION IF EXISTS ledger_transaction_balanced();');
        DB::unprepared('DROP TRIGGER IF EXISTS ledger_entries_immutable ON ledger_entries; DROP TRIGGER IF EXISTS ledger_transactions_immutable ON ledger_transactions; DROP FUNCTION IF EXISTS ledger_immutable();');
        Schema::dropIfExists('ledger_entries');
        Schema::dropIfExists('ledger_transactions');
        Schema::dropIfExists('ledger_accounts');
    }
};
