<?php

namespace Tests\Feature;

use App\Exceptions\TimeCalculationRunImmutableException;
use App\Models\AttendanceRecord;
use App\Models\Company;
use App\Models\Employee;
use App\Models\LaborRuleVersion;
use App\Models\TimeCalculationRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TimeCalculationRunImmutabilityTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Employee $employee;

    private LaborRuleVersion $ruleVersion;

    private AttendanceRecord $attendanceRecord;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
        $this->employee = Employee::factory()->create(['company_id' => $this->company->id]);
        $this->ruleVersion = LaborRuleVersion::factory()->create(['company_id' => $this->company->id]);
        $this->attendanceRecord = AttendanceRecord::factory()->create([
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'rule_version_id' => $this->ruleVersion->id,
        ]);
    }

    private function createRun(): TimeCalculationRun
    {
        return TimeCalculationRun::factory()->create([
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'rule_version_id' => $this->ruleVersion->id,
            'output_ref' => $this->attendanceRecord->id,
        ]);
    }

    public function test_updating_a_time_calculation_run_instance_throws()
    {
        $run = $this->createRun();

        $this->expectException(TimeCalculationRunImmutableException::class);

        $run->update(['inputs_hash' => 'tampered']);
    }

    public function test_deleting_a_time_calculation_run_instance_throws()
    {
        $run = $this->createRun();

        $this->expectException(TimeCalculationRunImmutableException::class);

        $run->delete();
    }

    public function test_updating_via_query_builder_throws()
    {
        $run = $this->createRun();

        $this->expectException(TimeCalculationRunImmutableException::class);

        TimeCalculationRun::query()->where('id', $run->id)->update(['inputs_hash' => 'tampered']);
    }

    public function test_deleting_via_query_builder_throws()
    {
        $run = $this->createRun();

        $this->expectException(TimeCalculationRunImmutableException::class);

        TimeCalculationRun::query()->where('id', $run->id)->delete();
    }

    public function test_a_run_has_no_updated_at_column()
    {
        $run = $this->createRun();

        $this->assertArrayNotHasKey('updated_at', $run->getAttributes());
    }

    public function test_a_run_belongs_to_its_employee_rule_version_and_attendance_record()
    {
        $run = $this->createRun();

        $this->assertTrue($run->employee->is($this->employee));
        $this->assertTrue($run->ruleVersion->is($this->ruleVersion));
        $this->assertTrue($run->attendanceRecord->is($this->attendanceRecord));
    }
}
