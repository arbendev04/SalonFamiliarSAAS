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
        Schema::create('novelty_types', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // Nullable: null means a platform default catalog entry, not
            // scoped to any single company.
            $table->foreignUuid('company_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('code');
            $table->string('name');
            $table->boolean('affects_time_calc')->default(false);
            $table->boolean('affects_payroll')->default(false);
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
        Schema::dropIfExists('novelty_types');
    }
};
