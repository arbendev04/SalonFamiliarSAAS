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
        Schema::create('generated_documents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->string('reference_entity_type');
            $table->uuid('reference_entity_id');
            $table->string('storage_ref');
            // users.id is an integer PK (see 0001_01_01_000000_create_users_table.php),
            // unlike the uuid tables above — foreignId(), not foreignUuid().
            $table->foreignId('generated_by')->constrained('users')->cascadeOnDelete();
            $table->timestamp('generated_at')->useCurrent();
            $table->unsignedInteger('version')->default(1);

            $table->index(['reference_entity_type', 'reference_entity_id']);
            $table->unique(['reference_entity_type', 'reference_entity_id', 'version']);

            // generated_documents is INSERT-only (INMUTABLE per .ai/14-PDF.md):
            // no updated_at column, same pattern as payroll_adjustments — a
            // correction produces a new versioned row, the row is never
            // touched again after creation.
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('generated_documents');
    }
};
