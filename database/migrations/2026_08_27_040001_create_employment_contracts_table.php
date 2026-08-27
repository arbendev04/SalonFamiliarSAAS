<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('employment_contracts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('position_id')->nullable()->constrained()->nullOnDelete();
            $table->string('contract_type');
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->decimal('base_salary', 12, 2);
            $table->string('status')->default('active');
            $table->timestamps();
        });

        // .ai/05-DATABASE.md requires the DB to reject overlapping active
        // contracts for the same employee. Postgres can enforce this
        // directly via an EXCLUDE constraint; the doc explicitly allows
        // falling back to service-level validation where that expression
        // isn't viable (see StoreEmploymentContractRequest), which is what
        // covers this in the sqlite test environment.
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');
            DB::statement(<<<'SQL'
                ALTER TABLE employment_contracts
                ADD CONSTRAINT employment_contracts_no_overlap
                EXCLUDE USING gist (
                    employee_id WITH =,
                    daterange(start_date, COALESCE(end_date, 'infinity'::date), '[]') WITH &&
                )
            SQL);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('employment_contracts');
    }
};
