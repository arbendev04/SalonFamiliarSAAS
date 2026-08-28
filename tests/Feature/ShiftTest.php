<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Role;
use App\Models\Shift;
use App\Models\User;
use App\Models\UserCompanyMembership;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShiftTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionSeeder::class, RoleSeeder::class]);

        $ownerRole = Role::query()->whereNull('company_id')->where('name', 'COMPANY_OWNER')->firstOrFail();

        $this->company = Company::factory()->create();
        $this->owner = User::factory()->create();

        UserCompanyMembership::create([
            'user_id' => $this->owner->id,
            'company_id' => $this->company->id,
            'role_id' => $ownerRole->id,
            'status' => 'active',
        ]);

        $this->employee = Employee::factory()->create(['company_id' => $this->company->id]);
    }

    public function test_the_shifts_index_page_exposes_the_companys_employees_for_reassignment()
    {
        $colleague = Employee::factory()->create(['company_id' => $this->company->id, 'full_name' => 'Colega']);
        $foreignEmployee = Employee::factory()->create(['company_id' => Company::factory()->create()->id]);

        $response = $this->actingAs($this->owner)->get(route('employees.shifts.index', $this->employee));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('employees/Shifts')
            ->has('employees', 2)
            ->where('employees', fn ($employees) => collect($employees)
                ->pluck('id')
                ->contains($colleague->id)
                && ! collect($employees)->pluck('id')->contains($foreignEmployee->id)),
        );
    }

    public function test_a_manual_shift_is_created_and_assigned_to_the_employee()
    {
        $this->actingAs($this->owner)->post(route('employees.shifts.store', $this->employee), [
            'date' => '2026-02-10',
            'start_datetime' => '2026-02-10 08:00:00',
            'end_datetime' => '2026-02-10 12:00:00',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $shift = Shift::query()->where('date', '2026-02-10')->firstOrFail();

        $this->assertSame($this->company->id, $shift->company_id);
        $this->assertSame('manual', $shift->source);
        $this->assertSame($this->employee->id, $shift->assignments()->first()->employee_id);
    }

    public function test_a_split_shift_is_a_single_shift_with_a_break_covering_the_gap()
    {
        $this->actingAs($this->owner)->post(route('employees.shifts.store', $this->employee), [
            'date' => '2026-02-10',
            'start_datetime' => '2026-02-10 08:00:00',
            'end_datetime' => '2026-02-10 20:00:00',
        ]);

        $shift = Shift::query()->where('date', '2026-02-10')->firstOrFail();

        $this->actingAs($this->owner)->post(route('shifts.breaks.store', $shift), [
            'planned_start' => '2026-02-10 12:00:00',
            'planned_end' => '2026-02-10 16:00:00',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(1, Shift::query()->where('date', '2026-02-10')->count());
        $this->assertSame(1, $shift->breaks()->count());
    }

    public function test_a_double_shift_the_same_day_is_two_independent_non_overlapping_shifts()
    {
        $this->actingAs($this->owner)->post(route('employees.shifts.store', $this->employee), [
            'date' => '2026-02-10',
            'start_datetime' => '2026-02-10 06:00:00',
            'end_datetime' => '2026-02-10 10:00:00',
        ])->assertSessionHasNoErrors();

        $this->actingAs($this->owner)->post(route('employees.shifts.store', $this->employee), [
            'date' => '2026-02-10',
            'start_datetime' => '2026-02-10 16:00:00',
            'end_datetime' => '2026-02-10 20:00:00',
        ])->assertSessionHasNoErrors();

        $this->assertSame(2, Shift::query()->where('date', '2026-02-10')->count());
    }

    public function test_assigning_an_overlapping_shift_to_the_same_employee_is_rejected()
    {
        $this->actingAs($this->owner)->post(route('employees.shifts.store', $this->employee), [
            'date' => '2026-02-10',
            'start_datetime' => '2026-02-10 06:00:00',
            'end_datetime' => '2026-02-10 14:00:00',
        ]);

        $response = $this->actingAs($this->owner)->post(route('employees.shifts.store', $this->employee), [
            'date' => '2026-02-10',
            'start_datetime' => '2026-02-10 12:00:00',
            'end_datetime' => '2026-02-10 20:00:00',
        ]);

        $response->assertSessionHasErrors('start_datetime');
        $this->assertSame(1, Shift::query()->count());
    }

    public function test_a_branch_belonging_to_another_company_cannot_be_assigned_to_a_shift()
    {
        $foreignBranch = Branch::factory()->create(['company_id' => Company::factory()->create()->id]);

        $this->actingAs($this->owner)->post(route('employees.shifts.store', $this->employee), [
            'branch_id' => $foreignBranch->id,
            'date' => '2026-02-10',
            'start_datetime' => '2026-02-10 06:00:00',
            'end_datetime' => '2026-02-10 14:00:00',
        ])->assertSessionHasErrors('branch_id');
    }
}
