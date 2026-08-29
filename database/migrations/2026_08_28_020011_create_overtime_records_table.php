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
        Schema::create('overtime_records', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('shift_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('detected_minutes');
            $table->unsignedInteger('requested_minutes')->nullable();
            $table->unsignedInteger('authorized_minutes')->nullable();
            $table->string('status');
            $table->timestamps();

            // One overtime record per shift assignment — this is what makes
            // the idempotent-upsert detection logic in the time calculation
            // engine race-safe.
            $table->unique(['employee_id', 'shift_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('overtime_records');
    }
};
