<?php

namespace Database\Factories;

use App\Models\AbsenceRecord;
use App\Models\Company;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AbsenceRecord>
 */
class AbsenceRecordFactory extends Factory
{
    protected $model = AbsenceRecord::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'employee_id' => Employee::factory(),
            'date' => '2026-02-10',
            'leave_record_id' => null,
            'justified' => false,
            'source' => 'time_calculation',
        ];
    }
}
