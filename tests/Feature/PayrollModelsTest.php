<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Employee;
use App\Models\EmploymentContract;
use App\Models\PayrollConceptDefinition;
use App\Models\PayrollDeductionPlan;
use App\Models\PayrollEntry;
use App\Models\PayrollEntryLine;
use App\Models\PayrollPeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Minimal factory/relationship sanity coverage for the payroll models that
 * have no dedicated behavioral test yet — the real behavioral tests land in
 * later commits once the payroll services actually write to these tables
 * (see composed-knitting-dusk.md's commit sequence). PayrollAdjustment's
 * immutability guard has its own dedicated
 * PayrollAdjustmentImmutabilityTest.
 */
class PayrollModelsTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Employee $employee;

    private EmploymentContract $contract;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
        $this->employee = Employee::factory()->create(['company_id' => $this->company->id]);
        $this->contract = EmploymentContract::factory()->create([
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
        ]);
    }

    public function test_a_payroll_period_can_be_created_and_belongs_to_its_company()
    {
        $period = PayrollPeriod::factory()->create([
            'company_id' => $this->company->id,
            'period_type' => 'biweekly',
            'status' => 'open',
        ]);

        $this->assertTrue($period->company->is($this->company));
        $this->assertSame('biweekly', $period->period_type);
        $this->assertSame('open', $period->status);
    }

    public function test_a_platform_default_payroll_concept_definition_has_a_null_company_id()
    {
        $concept = PayrollConceptDefinition::factory()->create([
            'company_id' => null,
            'code' => 'BASE_SALARY',
            'type' => 'earning',
            'calculation_method' => 'fixed',
        ]);

        $this->assertNull($concept->company_id);
        $this->assertSame('BASE_SALARY', $concept->code);
    }

    public function test_a_payroll_entry_belongs_to_its_period_employee_and_contract()
    {
        $period = PayrollPeriod::factory()->create(['company_id' => $this->company->id]);
        $entry = PayrollEntry::factory()->create([
            'company_id' => $this->company->id,
            'payroll_period_id' => $period->id,
            'employee_id' => $this->employee->id,
            'contract_id' => $this->contract->id,
        ]);

        $this->assertTrue($entry->payrollPeriod->is($period));
        $this->assertTrue($entry->employee->is($this->employee));
        $this->assertTrue($entry->contract->is($this->contract));
        $this->assertTrue($this->employee->payrollEntries->contains($entry));
        $this->assertTrue($this->contract->payrollEntries->contains($entry));
    }

    public function test_a_payroll_entry_line_belongs_to_its_entry_concept_and_contract()
    {
        $period = PayrollPeriod::factory()->create(['company_id' => $this->company->id]);
        $entry = PayrollEntry::factory()->create([
            'company_id' => $this->company->id,
            'payroll_period_id' => $period->id,
            'employee_id' => $this->employee->id,
            'contract_id' => $this->contract->id,
        ]);
        $concept = PayrollConceptDefinition::factory()->create(['company_id' => $this->company->id]);
        $line = PayrollEntryLine::factory()->create([
            'company_id' => $this->company->id,
            'payroll_entry_id' => $entry->id,
            'concept_id' => $concept->id,
            'contract_id' => $this->contract->id,
        ]);

        $this->assertTrue($line->payrollEntry->is($entry));
        $this->assertTrue($line->concept->is($concept));
        $this->assertTrue($line->contract->is($this->contract));
        $this->assertTrue($entry->lines->contains($line));
        $this->assertTrue($this->contract->payrollEntryLines->contains($line));
    }

    public function test_a_payroll_deduction_plan_belongs_to_its_employee_and_concept()
    {
        $concept = PayrollConceptDefinition::factory()->create(['company_id' => $this->company->id]);
        $plan = PayrollDeductionPlan::factory()->create([
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'concept_id' => $concept->id,
        ]);

        $this->assertTrue($plan->employee->is($this->employee));
        $this->assertTrue($plan->concept->is($concept));
        $this->assertTrue($this->employee->payrollDeductionPlans->contains($plan));
    }
}
