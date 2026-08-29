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
        Schema::create('leave_records', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('employee_id')->constrained()->cascadeOnDelete();
            // restrictOnDelete, not cascade: a leave type already used in a
            // real request must not vanish, the delete is blocked instead.
            $table->foreignUuid('leave_type_id')->constrained()->restrictOnDelete();
            $table->date('date_from');
            $table->date('date_to');
            $table->string('status');
            // users.id is an integer PK (see 0001_01_01_000000_create_users_table.php),
            // unlike the uuid tables above — foreignId(), not foreignUuid().
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('document_ref')->nullable();
            $table->string('reason');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['employee_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('leave_records');
    }
};
