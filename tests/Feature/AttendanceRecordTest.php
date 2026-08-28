<?php

namespace Tests\Feature;

use App\Models\AttendanceRecord;
use App\Models\Company;
use App\Models\Employee;
use App\Models\LaborRuleVersion;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Minimal factory/relationship/cast sanity coverage for this schema-only
 * commit. Behavioral coverage for how attendance_records is populated
 * belongs to the time calculation engine commit later in this sequence.
 */
class AttendanceRecordTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_record_can_be_created_via_factory_with_its_relationships()
    {
        $company = Company::factory()->create();
        $employee = Employee::factory()->create(['company_id' => $company->id]);
        $ruleVersion = LaborRuleVersion::factory()->create(['company_id' => $company->id]);

        $record = AttendanceRecord::factory()->create([
            'company_id' => $company->id,
            'employee_id' => $employee->id,
            'rule_version_id' => $ruleVersion->id,
        ]);

        $this->assertTrue($record->employee->is($employee));
        $this->assertTrue($record->ruleVersion->is($ruleVersion));
        $this->assertIsArray($record->planned_json);
        $this->assertIsArray($record->worked_json);
        $this->assertSame($record->date->format('Y-m-d'), $record->date->format('Y-m-d'));
    }

    public function test_a_second_record_for_the_same_employee_and_date_violates_the_unique_constraint()
    {
        $company = Company::factory()->create();
        $employee = Employee::factory()->create(['company_id' => $company->id]);

        AttendanceRecord::factory()->create([
            'company_id' => $company->id,
            'employee_id' => $employee->id,
            'date' => '2026-02-10',
        ]);

        $this->expectException(QueryException::class);

        AttendanceRecord::factory()->create([
            'company_id' => $company->id,
            'employee_id' => $employee->id,
            'date' => '2026-02-10',
        ]);
    }
}
