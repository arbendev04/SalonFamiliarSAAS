<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Employee;
use App\Models\PayrollInformation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PayrollInformation>
 */
class PayrollInformationFactory extends Factory
{
    protected $model = PayrollInformation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'employee_id' => Employee::factory(),
            'bank_account_enc' => fake()->numerify('##########'),
            'tax_regime' => 'simplificado',
        ];
    }
}
