<?php

namespace App\Services\TimeCalculation;

use App\Models\Employee;
use App\Models\NoveltyRecord;
use Illuminate\Support\Carbon;

/**
 * Resolves whether an approved novelty covers a given employee+date, per the
 * "Permiso/ausencia aprobada sin marcación física" case in
 * .ai/09-TIME-CALCULATION.md (Casos especiales): "cuando existe un
 * `novelty_records` vigente para esa fecha... el motor trata el tiempo
 * planificado cubierto por esa novedad como justificado". This is the sole
 * input the TimeCalculationEngine (wired in a later commit) needs from
 * Overtime/Novedades to classify a full-day absence as justified instead of
 * missing.
 *
 * Today, `novelty_records` are only ever generated from an approved
 * `leave_records` row (`add`-type attendance adjustments never create a
 * novelty — see AttendanceNetEventsResolver's docblock). Nothing in the
 * codebase — no Postgres constraint, no StoreLeaveRecordRequest validation —
 * prevents two APPROVED `leave_records` for the same employee with
 * overlapping date ranges, unlike `employment_contracts` (EXCLUDE USING gist
 * + StoreEmploymentContractRequest::withValidator() fallback) or
 * ShiftAssignment::overlapsForEmployee(). So two APPROVED novelty_records
 * covering the same employee/date are possible today. This is the same
 * shape of ambiguity as the one already resolved in
 * AttendanceNetEventsResolver / the PENDING DECISION at 07-ATTENDANCE.md
 * (Flujo 2, punto 4) and documented as a new PENDING DECISION near the
 * `leave_records`/`novelty_records` entries of 04-DOMAIN-MODEL.md: the most
 * recently created APPROVED novelty wins entirely, never a merge between
 * the two.
 */
class NoveltyRecordLookup
{
    public function resolve(Employee $employee, Carbon $date): ?NoveltyRecord
    {
        return NoveltyRecord::query()
            ->where('employee_id', $employee->id)
            ->where('status', 'approved')
            ->where('date_from', '<=', $date->toDateString())
            ->where('date_to', '>=', $date->toDateString())
            ->orderByDesc('created_at')
            ->first();
    }
}
