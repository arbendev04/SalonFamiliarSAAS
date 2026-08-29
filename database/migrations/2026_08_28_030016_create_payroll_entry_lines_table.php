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
        Schema::create('payroll_entry_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('payroll_entry_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('concept_id')->constrained('payroll_concept_definitions')->restrictOnDelete();
            // Not in the abbreviated .ai/05-DATABASE.md list, but required by
            // .ai/10-PAYROLL.md's own rule 2 and acceptance criterion #2:
            // a contract split mid-period must produce multiple
            // payroll_entry_lines with distinct contract_id. Nullable
            // because not every line (e.g. a fixed deduction) is tied to a
            // specific contract sub-range.
            $table->foreignUuid('contract_id')->nullable()->constrained('employment_contracts')->restrictOnDelete();
            $table->string('type');
            $table->decimal('quantity', 12, 4)->nullable();
            $table->decimal('rate', 12, 4)->nullable();
            $table->decimal('amount', 12, 2);
            $table->timestamps();

            $table->index('payroll_entry_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payroll_entry_lines');
    }
};
