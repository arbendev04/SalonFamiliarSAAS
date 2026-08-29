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
        Schema::create('social_security_entities', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // Nullable: null means a platform default catalog entry, not
            // scoped to any single company. No platform-default rows are
            // seeded this phase — every row is company-created via CRUD —
            // but the column still exists structurally for that pattern.
            $table->foreignUuid('company_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->string('name');
            $table->string('code');
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'code']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('social_security_entities');
    }
};
