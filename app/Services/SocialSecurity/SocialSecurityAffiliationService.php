<?php

namespace App\Services\SocialSecurity;

use App\Exceptions\NoActiveSocialSecurityAffiliationException;
use App\Models\Employee;
use App\Models\SocialSecurityAffiliation;
use App\Models\SocialSecurityEntity;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Same template as App\Services\Leave\LeaveRecordService: each public method
 * wraps its business write and a single AuditLogger::record() call in one
 * DB::transaction() (ADR-018). Neither method re-checks the overlap
 * constraint already enforced by StoreSocialSecurityAffiliationRequest
 * (Postgres EXCLUDE plus its own withValidator() fallback) — same division
 * of responsibility LeaveRecordService/OvertimeRecordService already use
 * with their own FormRequests. No controller exists yet for this service
 * (deferred to a later commit of composed-knitting-dusk.md).
 */
class SocialSecurityAffiliationService
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * Creates the first (or an additional, different entity_type)
     * affiliation for an employee. `entity_type` is always derived from the
     * given entity — never trusted from a caller-supplied value, same
     * requirement StoreSocialSecurityAffiliationRequest::withValidator()
     * already enforces for the write guard.
     */
    public function affiliate(
        Employee $employee,
        SocialSecurityEntity $entity,
        CarbonInterface $startDate,
        ?string $affiliationNumber,
        User $createdBy,
    ): SocialSecurityAffiliation {
        return DB::transaction(function () use ($employee, $entity, $startDate, $affiliationNumber, $createdBy) {
            $affiliation = SocialSecurityAffiliation::create([
                'company_id' => $employee->company_id,
                'employee_id' => $employee->id,
                'entity_id' => $entity->id,
                'entity_type' => $entity->type,
                'affiliation_number' => $affiliationNumber,
                'start_date' => $startDate->toDateString(),
                'end_date' => null,
            ]);

            $this->auditLogger->record(
                user: $createdBy,
                action: 'social_security_affiliation.created',
                entityType: 'social_security_affiliations',
                entityId: $affiliation->id,
                oldValue: null,
                newValue: [
                    'entity_id' => $affiliation->entity_id,
                    'entity_type' => $affiliation->entity_type,
                    'affiliation_number' => $affiliation->affiliation_number,
                    'start_date' => $affiliation->start_date->toDateString(),
                ],
            );

            return $affiliation;
        });
    }

    /**
     * Closes the employee's currently-active affiliation for the new
     * entity's `entity_type` (end_date set to the day before $effectiveDate,
     * same boundary convention as the day-before closing used across this
     * codebase's other effective-dated ranges) and opens a new one starting
     * exactly at $effectiveDate. Because the new start date is computed as
     * exactly the day after the closed row's new end date, no overlap can
     * occur by construction — the DB-level EXCLUDE guard is never at risk
     * here.
     *
     * A single audit entry describes the whole reassignment (close + open),
     * matching the one-audit-call-per-business-transaction convention used
     * throughout this codebase (LeaveRecordService, OvertimeRecordService)
     * even when a transition touches more than one field or row.
     *
     * @throws NoActiveSocialSecurityAffiliationException when no affiliation
     *                                                    of the new entity's type is currently active for this employee
     * @throws InvalidArgumentException when $effectiveDate is not strictly after the currently-active
     *                                  affiliation's start_date
     */
    public function reassign(
        Employee $employee,
        SocialSecurityEntity $newEntity,
        CarbonInterface $effectiveDate,
        ?string $affiliationNumber,
        User $createdBy,
    ): SocialSecurityAffiliation {
        $current = SocialSecurityAffiliation::activeFor($employee->id, $newEntity->type, $effectiveDate);

        if ($current === null) {
            throw new NoActiveSocialSecurityAffiliationException($employee->id, $newEntity->type, $effectiveDate);
        }

        if ($effectiveDate->toDateString() <= $current->start_date->toDateString()) {
            throw new InvalidArgumentException(
                'La fecha de reasignación debe ser posterior a la fecha de inicio de la afiliación vigente.'
            );
        }

        return DB::transaction(function () use ($employee, $newEntity, $effectiveDate, $affiliationNumber, $createdBy, $current) {
            $closedEndDate = $effectiveDate->copy()->subDay();

            $current->update(['end_date' => $closedEndDate->toDateString()]);

            $new = SocialSecurityAffiliation::create([
                'company_id' => $employee->company_id,
                'employee_id' => $employee->id,
                'entity_id' => $newEntity->id,
                'entity_type' => $newEntity->type,
                'affiliation_number' => $affiliationNumber,
                'start_date' => $effectiveDate->toDateString(),
                'end_date' => null,
            ]);

            $this->auditLogger->record(
                user: $createdBy,
                action: 'social_security_affiliation.reassigned',
                entityType: 'social_security_affiliations',
                entityId: $new->id,
                oldValue: [
                    'affiliation_id' => $current->id,
                    'entity_id' => $current->entity_id,
                    'end_date' => $closedEndDate->toDateString(),
                ],
                newValue: [
                    'entity_id' => $new->entity_id,
                    'entity_type' => $new->entity_type,
                    'affiliation_number' => $new->affiliation_number,
                    'start_date' => $new->start_date->toDateString(),
                ],
            );

            return $new;
        });
    }
}
