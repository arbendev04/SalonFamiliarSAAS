<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('payroll_entry_lines', function (Blueprint $table) {
            // Same pattern as deduction_plan_id: the payroll entry line
            // still references payroll_concept_definitions (a different
            // catalog) for its concept; this FK adds traceability to the
            // specific social security contribution that generated the
            // line. Nullable because only social-security deduction lines
            // carry it — earning lines and other deduction lines never do.
            $table->foreignUuid('social_security_contribution_id')
                ->nullable()
                ->after('deduction_plan_id')
                ->constrained('social_security_contributions')
                ->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payroll_entry_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('social_security_contribution_id');
        });
    }
};
