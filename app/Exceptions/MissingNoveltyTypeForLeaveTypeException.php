<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when an approved leave_records row needs to resolve the matching
 * novelty_type for its leave_type's `code` (see
 * App\Services\Leave\LeaveRecordService::generateNoveltyAndAbsence()) and no
 * NoveltyType — platform default or company override, via
 * HasPlatformOrCompanyDefault::scopeEffectiveForCompany() — shares that
 * code.
 *
 * This is treated as a data-integrity gap, not a recoverable no-op: the 4
 * essential codes are seeded together in lockstep by
 * EssentialNoveltyCatalogSeeder, so this can only happen if the two catalogs
 * drifted out of sync (a leave_type created, or its matching novelty_type
 * deleted, without the other). Silently producing no novelty_records/
 * absence_records would leave an "approved" leave with no trace for
 * downstream time calculation to consult — worse than failing loudly inside
 * the same transaction that would have persisted the approval, so the
 * approval never lands half-done and can be retried once the catalogs are
 * reconciled.
 */
class MissingNoveltyTypeForLeaveTypeException extends RuntimeException
{
    public function __construct(string $leaveTypeCode, ?string $companyId)
    {
        parent::__construct(
            "No existe un novelty_type (de plataforma ni de la empresa {$companyId}) con code '{$leaveTypeCode}' para generar la novedad correspondiente a esta ausencia aprobada."
        );
    }
}
