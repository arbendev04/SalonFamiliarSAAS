<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Employee;
use App\Models\NoveltyRecord;
use App\Models\NoveltyType;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<NoveltyRecord>
 */
class NoveltyRecordFactory extends Factory
{
    protected $model = NoveltyRecord::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'employee_id' => Employee::factory(),
            'novelty_type_id' => NoveltyType::factory(),
            'date_from' => '2026-02-10',
            'date_to' => '2026-02-10',
            'source_type' => 'leave_records',
            'source_id' => (string) Str::uuid(),
            'status' => 'approved',
        ];
    }
}
