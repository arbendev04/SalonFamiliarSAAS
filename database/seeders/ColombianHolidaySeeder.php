<?php

namespace Database\Seeders;

use App\Models\Holiday;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Seeds the platform-default (company_id = null) Colombian public holiday
 * calendar for the current year and the next.
 *
 * SCOPE DECISION: Colombia observes 18 official holidays a year. Only the 6
 * fixed-date holidays that Ley Emiliani (Law 51 of 1983) does NOT shift are
 * seeded here:
 *
 *   - Año Nuevo (Jan 1)
 *   - Día del Trabajo (May 1)
 *   - Independencia de Colombia (Jul 20)
 *   - Batalla de Boyacá (Aug 7)
 *   - Inmaculada Concepción (Dec 8)
 *   - Navidad (Dec 25)
 *
 * The remaining 12 are deliberately NOT seeded:
 *
 *   - 10 Ley Emiliani-shifted fixed-date holidays (Reyes Magos, San José,
 *     San Pedro y San Pablo, Asunción de la Virgen, Día de la Raza, Todos
 *     los Santos, Independencia de Cartagena) plus the 3 moveable-feast
 *     ones that Ley Emiliani also shifts to the following Monday (Ascensión
 *     del Señor, Corpus Christi, Sagrado Corazón).
 *   - 2 Easter-dependent, non-shifted holidays (Jueves Santo, Viernes
 *     Santo).
 *
 * Getting the shift-to-Monday rule or the moveable Easter date wrong would
 * seed an incorrect legal holiday date, which is worse than seeding none —
 * this project's rule against inventing unvalidated legal/business facts
 * applies to precise Colombian public-holiday determination. This seeder's
 * job is only to prove the platform-default mechanism works end-to-end with
 * real data, not to be a legally-exhaustive Colombian holiday authority. A
 * real company can add the remaining 12 manually via the HolidayController
 * UI (a later commit), or a future seeder enhancement can compute them once
 * the Easter-computus and Ley Emiliani shift rules are validated.
 */
class ColombianHolidaySeeder extends Seeder
{
    /**
     * Month/day pairs for the fixed-date, non-Emiliani-shifted holidays.
     *
     * @var array<int, array{month: int, day: int, name: string}>
     */
    private const FIXED_HOLIDAYS = [
        ['month' => 1, 'day' => 1, 'name' => 'Año Nuevo'],
        ['month' => 5, 'day' => 1, 'name' => 'Día del Trabajo'],
        ['month' => 7, 'day' => 20, 'name' => 'Independencia de Colombia'],
        ['month' => 8, 'day' => 7, 'name' => 'Batalla de Boyacá'],
        ['month' => 12, 'day' => 8, 'name' => 'Inmaculada Concepción'],
        ['month' => 12, 'day' => 25, 'name' => 'Navidad'],
    ];

    public function run(): void
    {
        $currentYear = (int) Carbon::now()->format('Y');

        foreach ([$currentYear, $currentYear + 1] as $year) {
            foreach (self::FIXED_HOLIDAYS as $holiday) {
                $date = Carbon::create($year, $holiday['month'], $holiday['day'])->format('Y-m-d');

                Holiday::query()->updateOrCreate(
                    ['company_id' => null, 'date' => $date],
                    ['name' => $holiday['name']],
                );
            }
        }
    }
}
