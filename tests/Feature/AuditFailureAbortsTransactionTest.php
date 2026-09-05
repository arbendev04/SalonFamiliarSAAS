<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Employee;
use App\Models\Role;
use App\Models\User;
use App\Models\UserCompanyMembership;
use App\Services\Attendance\AttendanceAdjustmentService;
use App\Services\Audit\AuditLogger;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-018: if AuditLogger::record() fails, the entire surrounding business
 * transaction must abort. AttendanceAdjustmentService::create() implements
 * this by construction — the audit call sits inside the same DB::transaction
 * as the business write (see its class docblock) — but nothing in the suite
 * previously forced the audit write to fail to prove the rollback actually
 * happens. This test closes that gap.
 */
class AuditFailureAbortsTransactionTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_failed_audit_log_write_rolls_back_the_entire_business_transaction()
    {
        $this->seed([PermissionSeeder::class, RoleSeeder::class]);

        $company = Company::factory()->create();
        $role = Role::query()->whereNull('company_id')->where('name', 'COMPANY_OWNER')->firstOrFail();
        $owner = User::factory()->create();

        UserCompanyMembership::create([
            'user_id' => $owner->id,
            'company_id' => $company->id,
            'role_id' => $role->id,
            'status' => 'active',
        ]);

        $employee = Employee::factory()->create(['company_id' => $company->id]);

        $this->mock(AuditLogger::class, function ($mock) {
            $mock->shouldReceive('record')->once()->andThrow(new \RuntimeException('Simulated audit log write failure.'));
        });

        $this->expectException(\RuntimeException::class);

        try {
            app(AttendanceAdjustmentService::class)->create(
                employee: $employee,
                requestedBy: $owner,
                type: 'add',
                originalEvent: null,
                originalValue: null,
                correctedValue: ['event_type' => 'clock_in', 'event_datetime' => '2026-02-10 08:00:00'],
                reason: 'Falta marcación de entrada.',
            );
        } finally {
            $this->assertDatabaseCount('attendance_adjustments', 0);
            $this->assertDatabaseCount('audit_logs', 0);
            $this->assertDatabaseCount('attendance_events', 0);
        }
    }
}
