<?php

namespace Tests\Feature;

use App\Exceptions\PayrollAdjustmentImmutableException;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmploymentContract;
use App\Models\PayrollAdjustment;
use App\Models\PayrollEntry;
use App\Models\PayrollPeriod;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PayrollAdjustmentImmutabilityTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private PayrollEntry $entry;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
        $employee = Employee::factory()->create(['company_id' => $this->company->id]);
        $contract = EmploymentContract::factory()->create([
            'company_id' => $this->company->id,
            'employee_id' => $employee->id,
        ]);
        $period = PayrollPeriod::factory()->create(['company_id' => $this->company->id]);
        $this->user = User::factory()->create();
        $this->entry = PayrollEntry::factory()->create([
            'company_id' => $this->company->id,
            'payroll_period_id' => $period->id,
            'employee_id' => $employee->id,
            'contract_id' => $contract->id,
        ]);
    }

    private function createAdjustment(): PayrollAdjustment
    {
        return PayrollAdjustment::factory()->create([
            'company_id' => $this->company->id,
            'payroll_entry_id' => $this->entry->id,
            'created_by' => $this->user->id,
        ]);
    }

    public function test_updating_a_payroll_adjustment_instance_throws()
    {
        $adjustment = $this->createAdjustment();

        $this->expectException(PayrollAdjustmentImmutableException::class);

        $adjustment->update(['reason' => 'tampered']);
    }

    public function test_deleting_a_payroll_adjustment_instance_throws()
    {
        $adjustment = $this->createAdjustment();

        $this->expectException(PayrollAdjustmentImmutableException::class);

        $adjustment->delete();
    }

    public function test_updating_via_query_builder_throws()
    {
        $adjustment = $this->createAdjustment();

        $this->expectException(PayrollAdjustmentImmutableException::class);

        PayrollAdjustment::query()->where('id', $adjustment->id)->update(['reason' => 'tampered']);
    }

    public function test_deleting_via_query_builder_throws()
    {
        $adjustment = $this->createAdjustment();

        $this->expectException(PayrollAdjustmentImmutableException::class);

        PayrollAdjustment::query()->where('id', $adjustment->id)->delete();
    }

    public function test_an_adjustment_has_no_updated_at_column()
    {
        $adjustment = $this->createAdjustment();

        $this->assertArrayNotHasKey('updated_at', $adjustment->getAttributes());
    }

    public function test_an_adjustment_belongs_to_its_payroll_entry_and_creator()
    {
        $adjustment = $this->createAdjustment();

        $this->assertTrue($adjustment->payrollEntry->is($this->entry));
        $this->assertTrue($adjustment->createdBy->is($this->user));
    }
}
