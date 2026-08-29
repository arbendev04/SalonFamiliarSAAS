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
        Schema::create('novelty_records', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('employee_id')->constrained()->cascadeOnDelete();
            // restrictOnDelete, not cascade: a novelty type already used in a
            // real record must not vanish, the delete is blocked instead.
            $table->foreignUuid('novelty_type_id')->constrained()->restrictOnDelete();
            $table->date('date_from');
            $table->date('date_to');
            // Polymorphic discriminator: source_type/source_id point at
            // leave_records, overtime_records, or attendance_adjustments
            // indistinctly. No DB-level foreign key is possible across
            // different tables, this is validated at the service layer.
            $table->string('source_type');
            $table->string('source_id');
            $table->string('status');
            $table->timestamps();

            $table->index(['employee_id', 'date_from', 'date_to']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('novelty_records');
    }
};
