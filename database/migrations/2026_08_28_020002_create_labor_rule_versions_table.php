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
        Schema::create('labor_rule_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // Nullable, denormalized/inherited from the parent
            // labor_rules.company_id — null still means a platform-wide
            // default, same isolation pattern as labor_rules itself.
            $table->foreignUuid('company_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignUuid('labor_rule_id')->constrained()->cascadeOnDelete();
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->json('parameters');
            // users.id is an integer PK (see 0001_01_01_000000_create_users_table.php),
            // unlike the uuid tables above — foreignId(), not foreignUuid().
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['labor_rule_id', 'effective_from', 'effective_to']);
        });

        // .ai/05-DATABASE.md requires the DB to reject overlapping versions
        // for the same labor rule. Postgres can enforce this directly via
        // an EXCLUDE constraint (same pattern as employment_contracts); the
        // fallback for sqlite is service-level validation in the FormRequest.
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');
            DB::statement(<<<'SQL'
                ALTER TABLE labor_rule_versions
                ADD CONSTRAINT labor_rule_versions_no_overlap
                EXCLUDE USING gist (
                    labor_rule_id WITH =,
                    daterange(effective_from, COALESCE(effective_to, 'infinity'::date), '[]') WITH &&
                )
            SQL);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('labor_rule_versions');
    }
};
