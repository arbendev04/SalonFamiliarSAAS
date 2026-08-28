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
        Schema::table('work_schedule_days', function (Blueprint $table) {
            // Optional planned break window for the day. Both columns are
            // set together or left both null — see
            // StoreWorkScheduleTemplateRequest::withValidator() — and, when
            // present, ShiftGenerator auto-creates the matching shift_breaks
            // row (see .ai/08-SHIFTS.md).
            $table->time('break_start_time')->nullable()->after('end_time');
            $table->time('break_end_time')->nullable()->after('break_start_time');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('work_schedule_days', function (Blueprint $table) {
            $table->dropColumn(['break_start_time', 'break_end_time']);
        });
    }
};
