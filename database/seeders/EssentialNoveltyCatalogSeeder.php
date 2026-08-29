<?php

namespace Database\Seeders;

use App\Models\LeaveType;
use App\Models\NoveltyType;
use Illuminate\Database\Seeder;

/**
 * Seeds the platform-default (company_id = null) catalog rows for the 4
 * essential novelty types named in .ai/25-MVP-SCOPE.md (Fase 8, MVP cut):
 * AUSENCIA, PERMISO, VACACIONES, INCAPACIDAD. "Festivo" (holiday) is the 5th
 * essential named there but lives in its own `holidays` table/seeder
 * (ColombianHolidaySeeder), not here.
 *
 * The same `code` is used across both `leave_types` and `novelty_types` for
 * a given concept (e.g. `AUSENCIA` in both tables). There is no foreign key
 * between the two tables — this shared code is the correlation key
 * `LeaveRecordService` (a later commit) will use to resolve "which
 * novelty_type corresponds to this leave_type" when a leave_records row is
 * approved.
 *
 * `affects_time_calc = true` on all 4: none of the 4 essentials should NOT
 * affect time calculation — each one removes expected presence from the
 * employee's planned day. `affects_payroll = false` on all 4 is a
 * placeholder default, not a resolved business rule: payroll effects will
 * be derived downstream from time calculation in a later phase, rather than
 * modeled as a direct flag on the novelty type yet.
 */
class EssentialNoveltyCatalogSeeder extends Seeder
{
    /**
     * @var array<string, string>
     */
    private const CATALOG = [
        'AUSENCIA' => 'Ausencia',
        'PERMISO' => 'Permiso',
        'VACACIONES' => 'Vacaciones',
        'INCAPACIDAD' => 'Incapacidad',
    ];

    public function run(): void
    {
        foreach (self::CATALOG as $code => $name) {
            LeaveType::query()->updateOrCreate(
                ['company_id' => null, 'code' => $code],
                ['name' => $name],
            );

            NoveltyType::query()->updateOrCreate(
                ['company_id' => null, 'code' => $code],
                [
                    'name' => $name,
                    'affects_time_calc' => true,
                    'affects_payroll' => false,
                ],
            );
        }
    }
}
