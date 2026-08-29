<?php

namespace Tests\Unit;

use App\Exceptions\InvalidLeaveRecordStatusException;
use App\Exceptions\MissingNoveltyTypeForLeaveTypeException;
use App\Models\AbsenceRecord;
use App\Models\AttendanceRecord;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Employee;
use App\Models\LaborRule;
use App\Models\LaborRuleVersion;
use App\Models\LeaveRecord;
use App\Models\LeaveType;
use App\Models\NoveltyRecord;
use App\Models\NoveltyType;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\User;
use App\Models\UserCompanyMembership;
use App\Services\Leave\LeaveRecordService;
use App\Services\Tenancy\CurrentCompany;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * No LeaveRecordController exists yet (deferred to a later commit), so
 * unlike AttendanceAdjustmentTest this exercises the service directly
 * rather than through HTTP routes — the same convention already used by
 * tests/Unit/TimeCalculationEngineTest.php for a service with no
 * controller. CurrentCompany is set manually (no SetCurrentCompany
 * middleware to run it for us), matching
 * tests/Unit/HasPlatformOrCompanyDefaultTest.php.
 */
class LeaveRecordServiceTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Employee $employee;

    private LeaveRecordService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionSeeder::class, RoleSeeder::class]);

        $this->company = Company::factory()->create();
        app(CurrentCompany::class)->set($this->company);

        $this->employee = Employee::factory()->create(['company_id' => $this->company->id]);
        $this->service = app(LeaveRecordService::class);
    }

    private function userWithRole(string $roleName, Company $company): User
    {
        $role = Role::query()->whereNull('company_id')->where('name', $roleName)->firstOrFail();
        $user = User::factory()->create();

        UserCompanyMembership::create([
            'user_id' => $user->id,
            'company_id' => $company->id,
            'role_id' => $role->id,
            'status' => 'active',
        ]);

        return $user;
    }

    /**
     * RoleSeeder currently grants `leave.create` and `leave.approve`
     * together on every role that has either (COMPANY_OWNER, ADMIN,
     * HR_MANAGER, SUPERVISOR all have both) — there is no seeded role today
     * that isolates "can request but not auto-approve" for the leave
     * module the way SUPERVISOR does for attendance.adjust/
     * attendance.approve_adjustment. A custom role granting only
     * `leave.create` is used instead, to prove ADR-032's derivation is by
     * permission code, not by role name.
     */
    private function userWithOnlyLeaveCreatePermission(Company $company): User
    {
        $role = Role::create(['company_id' => null, 'name' => 'LEAVE_REQUESTER_ONLY', 'is_system' => false]);
        $role->permissions()->sync([
            Permission::query()->where('code', 'leave.create')->firstOrFail()->id,
        ]);

        $user = User::factory()->create();

        UserCompanyMembership::create([
            'user_id' => $user->id,
            'company_id' => $company->id,
            'role_id' => $role->id,
            'status' => 'active',
        ]);

        return $user;
    }

    /**
     * A platform-default leave_type/novelty_type pair sharing `code`, the
     * correlation key used by generateNoveltyAndAbsence(). Mirrors
     * EssentialNoveltyCatalogSeeder's shape without depending on it.
     */
    private function correlatedCatalogPair(string $code, bool $affectsTimeCalc = true): LeaveType
    {
        $leaveType = LeaveType::factory()->create(['company_id' => null, 'code' => $code]);

        NoveltyType::factory()->create([
            'company_id' => null,
            'code' => $code,
            'affects_time_calc' => $affectsTimeCalc,
        ]);

        return $leaveType;
    }

    public function test_create_by_a_user_with_leave_approve_permission_auto_approves_and_generates_the_cascade()
    {
        $leaveType = $this->correlatedCatalogPair('VACACIONES');
        $owner = $this->userWithRole('COMPANY_OWNER', $this->company);

        $record = $this->service->create(
            employee: $this->employee,
            requestedBy: $owner,
            leaveType: $leaveType,
            dateFrom: Carbon::parse('2026-03-02'),
            dateTo: Carbon::parse('2026-03-04'),
            reason: 'Vacaciones programadas.',
        );

        $this->assertSame('approved', $record->status);
        $this->assertSame($owner->id, $record->approved_by);

        $novelty = NoveltyRecord::query()
            ->where('source_type', 'leave_records')
            ->where('source_id', $record->id)
            ->first();

        $this->assertNotNull($novelty);
        $this->assertSame('approved', $novelty->status);
        $this->assertSame('2026-03-02', $novelty->date_from->toDateString());
        $this->assertSame('2026-03-04', $novelty->date_to->toDateString());

        $absences = AbsenceRecord::query()->where('leave_record_id', $record->id)->orderBy('date')->get();
        $this->assertCount(3, $absences);
        $this->assertSame(['2026-03-02', '2026-03-03', '2026-03-04'], $absences->map->date->map->toDateString()->all());
        $this->assertTrue($absences->every(fn (AbsenceRecord $absence) => $absence->justified === true));
        $this->assertTrue($absences->every(fn (AbsenceRecord $absence) => $absence->source === 'leave_approval'));

        $this->assertSame(
            1,
            AuditLog::query()->where('entity_id', $record->id)->where('action', 'leave_record.approved')->count(),
        );
    }

    public function test_create_by_a_user_without_leave_approve_permission_stays_pending_without_the_cascade()
    {
        $leaveType = $this->correlatedCatalogPair('PERMISO');
        $requester = $this->userWithOnlyLeaveCreatePermission($this->company);

        $record = $this->service->create(
            employee: $this->employee,
            requestedBy: $requester,
            leaveType: $leaveType,
            dateFrom: Carbon::parse('2026-03-02'),
            dateTo: Carbon::parse('2026-03-02'),
            reason: 'Permiso personal.',
        );

        $this->assertSame('pending', $record->status);
        $this->assertNull($record->approved_by);
        $this->assertSame(0, NoveltyRecord::query()->count());
        $this->assertSame(0, AbsenceRecord::query()->count());

        $this->assertSame(
            1,
            AuditLog::query()->where('entity_id', $record->id)->where('action', 'leave_record.created')->count(),
        );
    }

    public function test_approve_on_a_pending_record_transitions_and_generates_the_cascade_only_then()
    {
        $leaveType = $this->correlatedCatalogPair('INCAPACIDAD');
        $requester = $this->userWithOnlyLeaveCreatePermission($this->company);
        $owner = $this->userWithRole('COMPANY_OWNER', $this->company);

        $record = $this->service->create(
            employee: $this->employee,
            requestedBy: $requester,
            leaveType: $leaveType,
            dateFrom: Carbon::parse('2026-03-02'),
            dateTo: Carbon::parse('2026-03-03'),
            reason: 'Incapacidad médica.',
        );

        $this->assertSame(0, AbsenceRecord::query()->count());
        $this->assertSame(0, NoveltyRecord::query()->count());

        $approved = $this->service->approve($record, $owner);

        $this->assertSame('approved', $approved->status);
        $this->assertSame($owner->id, $approved->approved_by);
        $this->assertSame(2, AbsenceRecord::query()->where('leave_record_id', $record->id)->count());
        $this->assertSame(1, NoveltyRecord::query()->where('source_id', $record->id)->count());

        $this->assertSame(
            1,
            AuditLog::query()->where('entity_id', $record->id)->where('action', 'leave_record.approved')->count(),
        );
    }

    public function test_approve_only_operates_on_a_pending_record()
    {
        $record = LeaveRecord::factory()->create([
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'status' => 'approved',
        ]);

        $owner = $this->userWithRole('COMPANY_OWNER', $this->company);

        $this->expectException(InvalidLeaveRecordStatusException::class);

        $this->service->approve($record, $owner);
    }

    public function test_reject_only_operates_on_a_pending_record()
    {
        $record = LeaveRecord::factory()->create([
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'status' => 'rejected',
        ]);

        $owner = $this->userWithRole('COMPANY_OWNER', $this->company);

        $this->expectException(InvalidLeaveRecordStatusException::class);

        $this->service->reject($record, $owner);
    }

    public function test_reject_on_a_pending_record_transitions_without_generating_the_cascade()
    {
        $leaveType = $this->correlatedCatalogPair('AUSENCIA');
        $requester = $this->userWithOnlyLeaveCreatePermission($this->company);
        $owner = $this->userWithRole('COMPANY_OWNER', $this->company);

        $record = $this->service->create(
            employee: $this->employee,
            requestedBy: $requester,
            leaveType: $leaveType,
            dateFrom: Carbon::parse('2026-03-02'),
            dateTo: Carbon::parse('2026-03-02'),
            reason: 'Ausencia a justificar.',
        );

        $rejected = $this->service->reject($record, $owner);

        $this->assertSame('rejected', $rejected->status);
        $this->assertSame(0, NoveltyRecord::query()->count());
        $this->assertSame(0, AbsenceRecord::query()->count());

        $this->assertSame(
            1,
            AuditLog::query()->where('entity_id', $record->id)->where('action', 'leave_record.rejected')->count(),
        );
    }

    public function test_a_novelty_type_that_does_not_affect_time_calc_skips_the_absence_cascade()
    {
        $leaveType = $this->correlatedCatalogPair('SIN_IMPACTO', affectsTimeCalc: false);
        $owner = $this->userWithRole('COMPANY_OWNER', $this->company);

        $record = $this->service->create(
            employee: $this->employee,
            requestedBy: $owner,
            leaveType: $leaveType,
            dateFrom: Carbon::parse('2026-03-02'),
            dateTo: Carbon::parse('2026-03-03'),
            reason: 'Novedad que no afecta el cálculo de tiempo.',
        );

        $this->assertSame(1, NoveltyRecord::query()->where('source_id', $record->id)->count());
        $this->assertSame(0, AbsenceRecord::query()->where('leave_record_id', $record->id)->count());
    }

    public function test_a_missing_novelty_type_for_the_leave_types_code_throws_and_rolls_back_the_creation()
    {
        // No matching NoveltyType exists for this code — the two catalogs
        // have drifted out of sync.
        $leaveType = LeaveType::factory()->create(['company_id' => null, 'code' => 'CODIGO_HUERFANO']);
        $owner = $this->userWithRole('COMPANY_OWNER', $this->company);

        $this->expectException(MissingNoveltyTypeForLeaveTypeException::class);

        try {
            $this->service->create(
                employee: $this->employee,
                requestedBy: $owner,
                leaveType: $leaveType,
                dateFrom: Carbon::parse('2026-03-02'),
                dateTo: Carbon::parse('2026-03-02'),
                reason: 'Código sin novelty_type correspondiente.',
            );
        } finally {
            $this->assertSame(0, LeaveRecord::query()->count());
        }
    }

    public function test_a_missing_novelty_type_for_the_leave_types_code_throws_and_rolls_back_the_approval()
    {
        $leaveType = LeaveType::factory()->create(['company_id' => null, 'code' => 'CODIGO_HUERFANO']);
        $requester = $this->userWithOnlyLeaveCreatePermission($this->company);
        $owner = $this->userWithRole('COMPANY_OWNER', $this->company);

        $record = $this->service->create(
            employee: $this->employee,
            requestedBy: $requester,
            leaveType: $leaveType,
            dateFrom: Carbon::parse('2026-03-02'),
            dateTo: Carbon::parse('2026-03-02'),
            reason: 'Código sin novelty_type correspondiente.',
        );

        $this->assertSame('pending', $record->status);

        $this->expectException(MissingNoveltyTypeForLeaveTypeException::class);

        try {
            $this->service->approve($record, $owner);
        } finally {
            $record->refresh();
            $this->assertSame('pending', $record->status);
        }
    }

    public function test_tenant_isolation_a_leave_record_from_another_company_is_not_visible()
    {
        $otherCompany = Company::factory()->create();
        $foreignRecord = LeaveRecord::factory()->create(['company_id' => $otherCompany->id]);

        // $this->company is already the active company from setUp().
        $this->assertNull(LeaveRecord::query()->find($foreignRecord->id));
    }

    public function test_approving_a_leave_record_triggers_recalculation_for_the_date_range()
    {
        $laborRule = LaborRule::factory()->create([
            'company_id' => $this->company->id,
            'rule_type' => 'STANDARD_WORKWEEK',
        ]);
        LaborRuleVersion::factory()->create([
            'company_id' => $this->company->id,
            'labor_rule_id' => $laborRule->id,
            'effective_from' => '2026-01-01',
            'effective_to' => null,
            'parameters' => ['tolerance_minutes' => 15, 'rounding_minutes' => 5],
        ]);

        $shift = Shift::factory()->create([
            'company_id' => $this->company->id,
            'date' => '2026-03-02',
            'start_datetime' => '2026-03-02 06:00:00',
            'end_datetime' => '2026-03-02 14:00:00',
            'crosses_midnight' => false,
        ]);
        ShiftAssignment::factory()->create([
            'company_id' => $this->company->id,
            'shift_id' => $shift->id,
            'employee_id' => $this->employee->id,
            'status' => 'assigned',
        ]);

        $leaveType = $this->correlatedCatalogPair('VACACIONES');
        $owner = $this->userWithRole('COMPANY_OWNER', $this->company);

        // No attendance events recorded for the shift's date: a full
        // absence, which calculateForDate() can classify without throwing.
        $this->service->create(
            employee: $this->employee,
            requestedBy: $owner,
            leaveType: $leaveType,
            dateFrom: Carbon::parse('2026-03-02'),
            dateTo: Carbon::parse('2026-03-02'),
            reason: 'Vacaciones con turno planificado.',
        );

        $this->assertDatabaseHas('attendance_records', [
            'employee_id' => $this->employee->id,
            'date' => '2026-03-02',
        ]);
        $this->assertDatabaseHas('time_calculation_runs', [
            'employee_id' => $this->employee->id,
            'date' => '2026-03-02',
        ]);
    }

    public function test_approving_a_leave_record_succeeds_even_without_a_configured_labor_rule_version()
    {
        // A shift exists so the engine attempts a real calculation, but no
        // LaborRule/LaborRuleVersion is configured for this company at all.
        $shift = Shift::factory()->create([
            'company_id' => $this->company->id,
            'date' => '2026-03-02',
            'start_datetime' => '2026-03-02 06:00:00',
            'end_datetime' => '2026-03-02 14:00:00',
            'crosses_midnight' => false,
        ]);
        ShiftAssignment::factory()->create([
            'company_id' => $this->company->id,
            'shift_id' => $shift->id,
            'employee_id' => $this->employee->id,
            'status' => 'assigned',
        ]);

        $leaveType = $this->correlatedCatalogPair('VACACIONES');
        $owner = $this->userWithRole('COMPANY_OWNER', $this->company);

        $record = $this->service->create(
            employee: $this->employee,
            requestedBy: $owner,
            leaveType: $leaveType,
            dateFrom: Carbon::parse('2026-03-02'),
            dateTo: Carbon::parse('2026-03-02'),
            reason: 'Vacaciones sin regla laboral configurada.',
        );

        $this->assertSame('approved', $record->status);
        $this->assertSame(
            1,
            AuditLog::query()->where('entity_id', $record->id)->where('action', 'leave_record.approved')->count(),
        );
        $this->assertSame(0, AttendanceRecord::query()->where('employee_id', $this->employee->id)->count());
    }
}
