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
        Schema::create('social_security_concept_definitions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // Nullable: null means a platform default catalog entry, not
            // scoped to any single company. No platform-default rows are
            // seeded this phase — every row is company-created via CRUD.
            $table->foreignUuid('company_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('code');
            $table->string('name');
            // Routes this concept to whichever affiliation `type` it consumes.
            // Deliberately not a foreign key: it matches whatever `type`
            // values a company defines on its own social_security_entities
            // rows, which are free-form strings, not a shared lookup table.
            $table->string('entity_type');
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
        Schema::dropIfExists('social_security_concept_definitions');
    }
};
