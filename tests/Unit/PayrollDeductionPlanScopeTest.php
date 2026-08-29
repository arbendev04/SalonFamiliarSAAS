<?php

namespace Tests\Unit;

use App\Models\Company;
use App\Models\Employee;
use App\Models\PayrollDeductionPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PayrollDeductionPlanScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_for_returns_only_plans_for_the_given_employee_with_remaining_greater_than_zero()
    {
        $company = Company::factory()->create();
        $employee = Employee::factory()->create(['company_id' => $company->id]);

        $activePlan = PayrollDeductionPlan::factory()->create([
            'company_id' => $company->id,
            'employee_id' => $employee->id,
            'remaining' => 100000,
        ]);

        $results = PayrollDeductionPlan::query()->activeFor($employee->id)->get();

        $this->assertCount(1, $results);
        $this->assertSame($activePlan->id, $results->first()->id);
    }

    public function test_active_for_excludes_a_plan_with_zero_remaining()
    {
        $company = Company::factory()->create();
        $employee = Employee::factory()->create(['company_id' => $company->id]);

        PayrollDeductionPlan::factory()->create([
            'company_id' => $company->id,
            'employee_id' => $employee->id,
            'remaining' => 0,
        ]);

        $results = PayrollDeductionPlan::query()->activeFor($employee->id)->get();

        $this->assertCount(0, $results);
    }

    public function test_active_for_excludes_a_plan_belonging_to_a_different_employee()
    {
        $company = Company::factory()->create();
        $employee = Employee::factory()->create(['company_id' => $company->id]);
        $otherEmployee = Employee::factory()->create(['company_id' => $company->id]);

        PayrollDeductionPlan::factory()->create([
            'company_id' => $company->id,
            'employee_id' => $otherEmployee->id,
            'remaining' => 100000,
        ]);

        $results = PayrollDeductionPlan::query()->activeFor($employee->id)->get();

        $this->assertCount(0, $results);
    }
}
