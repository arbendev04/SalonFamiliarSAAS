<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * `worked_json` documents what the employee actually clocked; it must
     * keep meaning exactly that. `justification_json` is deliberately a
     * separate column because it documents something different: WHY nothing
     * needed to be clocked on a day the engine treats as fully justified
     * (e.g. an approved leave). Conflating the two would blur `worked_json`'s
     * existing documented meaning.
     *
     * Shape when set (not enforced by the DB, documented here only):
     * {'novelty_record_id': string, 'novelty_type_code': string}
     * Null when there is no justification for the day.
     *
     * `justified_minutes` holds minutes of planned time covered by an
     * approved novelty instead of counting toward `missing_minutes`. This is
     * schema-only: the time calculation engine does not populate either
     * column yet (that lands in a later commit).
     */
    public function up(): void
    {
        Schema::table('attendance_records', function (Blueprint $table) {
            $table->unsignedInteger('justified_minutes')->default(0)->after('missing_minutes');
            $table->json('justification_json')->nullable()->after('justified_minutes');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('attendance_records', function (Blueprint $table) {
            $table->dropColumn(['justified_minutes', 'justification_json']);
        });
    }
};
