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
        Schema::create('social_security_affiliations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('entity_id')->constrained('social_security_entities')->restrictOnDelete();
            // Denormalized copy of the referenced entity's own `type` at
            // write time, populated by the service layer, never written
            // directly by the user. A Postgres EXCLUDE constraint can't
            // reference a column on a joined table, so this column exists
            // purely to make "at most one active affiliation per type"
            // expressible without a join.
            $table->string('entity_type');
            $table->string('affiliation_number')->nullable();
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->timestamps();
        });

        // HISTORIAL semantics: no soft-delete. Closing an affiliation means
        // setting end_date, never deleting the row.
        //
        // .ai/05-DATABASE.md requires the DB to reject overlapping active
        // affiliations of the same type for the same employee. Postgres can
        // enforce this directly via an EXCLUDE constraint; sqlite falls back
        // to service-level validation (see StoreSocialSecurityAffiliationRequest,
        // a later commit), mirroring the employment_contracts pattern.
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');
            DB::statement(<<<'SQL'
                ALTER TABLE social_security_affiliations
                ADD CONSTRAINT social_security_affiliations_no_overlap
                EXCLUDE USING gist (
                    employee_id WITH =,
                    entity_type WITH =,
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
        Schema::dropIfExists('social_security_affiliations');
    }
};
