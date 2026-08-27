<?php

namespace Tests\Unit;

use App\Exceptions\AmbiguousContractException;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmploymentContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class EmploymentContractLookupTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_the_contract_in_force_on_a_given_date()
    {
        $company = Company::factory()->create();
        $employee = Employee::factory()->create(['company_id' => $company->id]);

        $closedContract = EmploymentContract::factory()->create([
            'company_id' => $company->id,
            'employee_id' => $employee->id,
            'start_date' => '2024-01-01',
            'end_date' => '2024-06-30',
        ]);

        $currentContract = EmploymentContract::factory()->create([
            'company_id' => $company->id,
            'employee_id' => $employee->id,
            'start_date' => '2024-07-01',
            'end_date' => null,
        ]);

        $this->assertSame(
            $closedContract->id,
            EmploymentContract::activeForEmployeeAt($employee->id, Carbon::parse('2024-03-15'))->id,
        );

        $this->assertSame(
            $currentContract->id,
            EmploymentContract::activeForEmployeeAt($employee->id, Carbon::parse('2025-01-01'))->id,
        );
    }

    public function test_it_returns_null_when_no_contract_is_in_force_on_that_date()
    {
        $company = Company::factory()->create();
        $employee = Employee::factory()->create(['company_id' => $company->id]);

        EmploymentContract::factory()->create([
            'company_id' => $company->id,
            'employee_id' => $employee->id,
            'start_date' => '2024-01-01',
            'end_date' => '2024-06-30',
        ]);

        $this->assertNull(
            EmploymentContract::activeForEmployeeAt($employee->id, Carbon::parse('2023-12-31')),
        );
    }

    public function test_it_rejects_an_ambiguous_lookup_when_two_contracts_overlap_without_a_proper_close()
    {
        $company = Company::factory()->create();
        $employee = Employee::factory()->create(['company_id' => $company->id]);

        // Simulates data that reached an inconsistent state (e.g. inserted
        // outside the validated HTTP flow) — the lookup must refuse to
        // guess which contract applies rather than silently pick one.
        EmploymentContract::factory()->create([
            'company_id' => $company->id,
            'employee_id' => $employee->id,
            'start_date' => '2024-01-01',
            'end_date' => null,
        ]);

        EmploymentContract::factory()->create([
            'company_id' => $company->id,
            'employee_id' => $employee->id,
            'start_date' => '2024-06-01',
            'end_date' => null,
        ]);

        $this->expectException(AmbiguousContractException::class);

        EmploymentContract::activeForEmployeeAt($employee->id, Carbon::parse('2024-07-01'));
    }
}
