<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Employee;
use App\Models\EmploymentContract;
use App\Models\PayrollEntry;
use App\Models\PayrollPeriod;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PayrollEntry>
 */
class PayrollEntryFactory extends Factory
{
    protected $model = PayrollEntry::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'payroll_period_id' => PayrollPeriod::factory(),
            'employee_id' => Employee::factory(),
            'contract_id' => EmploymentContract::factory(),
            'status' => 'calculated',
            'gross_total' => 0,
            'deductions_total' => 0,
            'net_total' => 0,
        ];
    }
}
