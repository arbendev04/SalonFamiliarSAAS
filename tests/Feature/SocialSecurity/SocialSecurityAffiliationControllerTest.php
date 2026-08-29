<?php

namespace Tests\Feature\SocialSecurity;

use App\Models\Company;
use App\Models\Employee;
use App\Models\Role;
use App\Models\SocialSecurityAffiliation;
use App\Models\SocialSecurityEntity;
use App\Models\User;
use App\Models\UserCompanyMembership;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SocialSecurityAffiliationControllerTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Employee $employee;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionSeeder::class, RoleSeeder::class]);

        $this->company = Company::factory()->create();
        $this->employee = Employee::factory()->create(['company_id' => $this->company->id]);
        $this->owner = $this->userWithRole('COMPANY_OWNER', $this->company);
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

    public function test_index_returns_the_employees_full_affiliation_history_and_the_entity_types_still_missing_an_active_affiliation()
    {
        $entityA = SocialSecurityEntity::factory()->create(['company_id' => $this->company->id, 'type' => 'CATEGORY_A', 'name' => 'Entidad de prueba A']);
        SocialSecurityEntity::factory()->create(['company_id' => $this->company->id, 'type' => 'CATEGORY_B', 'name' => 'Entidad de prueba B']);

        $closed = SocialSecurityAffiliation::factory()->create([
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'entity_id' => $entityA->id,
            'entity_type' => 'CATEGORY_A',
            'start_date' => '2025-01-01',
            'end_date' => '2025-05-31',
        ]);
        $active = SocialSecurityAffiliation::factory()->create([
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'entity_id' => $entityA->id,
            'entity_type' => 'CATEGORY_A',
            'start_date' => '2025-06-01',
            'end_date' => null,
        ]);

        $response = $this->actingAs($this->owner)->get(route('employees.social-security-affiliations.index', $this->employee));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('employees/SocialSecurityAffiliations')
            ->where('employee.id', $this->employee->id)
            ->has('affiliations', 2)
            ->where('affiliations.0.id', $active->id)
            ->where('affiliations.0.is_active', true)
            ->where('affiliations.1.id', $closed->id)
            ->where('affiliations.1.is_active', false)
            ->has('entities', 2)
            ->where('entityTypesWithoutActiveAffiliation', ['CATEGORY_B'])
            ->where('canManage', true)
        );
    }

    public function test_index_is_denied_without_social_security_manage_permission()
    {
        $rankAndFile = $this->userWithRole('EMPLOYEE', $this->company);

        $this->actingAs($rankAndFile)
            ->get(route('employees.social-security-affiliations.index', $this->employee))
            ->assertForbidden();
    }

    public function test_store_creates_a_first_affiliation_via_affiliate_when_none_exists_yet_for_that_entity_type()
    {
        $entity = SocialSecurityEntity::factory()->create(['company_id' => $this->company->id, 'type' => 'CATEGORY_A']);

        $this->actingAs($this->owner)
            ->post(route('employees.social-security-affiliations.store', $this->employee), [
                'employee_id' => $this->employee->id,
                'entity_id' => $entity->id,
                'affiliation_number' => 'AFF-0001',
                'start_date' => '2025-01-01',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(1, SocialSecurityAffiliation::query()->where('employee_id', $this->employee->id)->count());

        $affiliation = SocialSecurityAffiliation::query()->where('employee_id', $this->employee->id)->firstOrFail();

        $this->assertSame($entity->id, $affiliation->entity_id);
        $this->assertSame('CATEGORY_A', $affiliation->entity_type);
        $this->assertSame('AFF-0001', $affiliation->affiliation_number);
        $this->assertNull($affiliation->end_date);
    }

    public function test_store_reassigns_by_closing_the_old_affiliation_and_opening_a_new_one_when_one_already_exists_for_that_entity_type()
    {
        $oldEntity = SocialSecurityEntity::factory()->create(['company_id' => $this->company->id, 'type' => 'CATEGORY_A']);
        $newEntity = SocialSecurityEntity::factory()->create(['company_id' => $this->company->id, 'type' => 'CATEGORY_A']);

        $original = SocialSecurityAffiliation::factory()->create([
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'entity_id' => $oldEntity->id,
            'entity_type' => 'CATEGORY_A',
            'start_date' => '2025-01-01',
            'end_date' => null,
        ]);

        $this->actingAs($this->owner)
            ->post(route('employees.social-security-affiliations.store', $this->employee), [
                'employee_id' => $this->employee->id,
                'entity_id' => $newEntity->id,
                'affiliation_number' => 'AFF-NEW',
                'start_date' => '2025-06-01',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $original->refresh();
        $this->assertSame('2025-05-31', $original->end_date->toDateString());

        $this->assertSame(2, SocialSecurityAffiliation::query()->where('employee_id', $this->employee->id)->count());

        $reassigned = SocialSecurityAffiliation::query()
            ->where('employee_id', $this->employee->id)
            ->where('entity_id', $newEntity->id)
            ->firstOrFail();

        $this->assertSame('AFF-NEW', $reassigned->affiliation_number);
        $this->assertSame('2025-06-01', $reassigned->start_date->toDateString());
        $this->assertNull($reassigned->end_date);
    }

    /**
     * A start_date BEFORE the currently active affiliation's own start_date
     * is not a legitimate reassignment target — SocialSecurityAffiliation::
     * activeFor() finds no match that early, so the request falls into the
     * "new affiliation" path (see SocialSecurityAffiliationController::store())
     * and must still be rejected by StoreSocialSecurityAffiliationRequest's
     * same-entity_type overlap guard. A submission with the same shape as a
     * legitimate reassign (same entity_type, later start_date) is
     * deliberately NOT used here — that scenario is exactly what
     * test_store_reassigns_... exercises as a success case.
     */
    public function test_store_rejects_an_overlapping_date_range()
    {
        $entity = SocialSecurityEntity::factory()->create(['company_id' => $this->company->id, 'type' => 'CATEGORY_A']);

        SocialSecurityAffiliation::factory()->create([
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'entity_id' => $entity->id,
            'entity_type' => 'CATEGORY_A',
            'start_date' => '2025-06-01',
            'end_date' => null,
        ]);

        $anotherEntitySameType = SocialSecurityEntity::factory()->create(['company_id' => $this->company->id, 'type' => 'CATEGORY_A']);

        $this->actingAs($this->owner)
            ->post(route('employees.social-security-affiliations.store', $this->employee), [
                'employee_id' => $this->employee->id,
                'entity_id' => $anotherEntitySameType->id,
                'start_date' => '2025-01-01',
            ])
            ->assertSessionHasErrors('start_date');

        $this->assertSame(1, SocialSecurityAffiliation::query()->where('employee_id', $this->employee->id)->count());
    }

    public function test_store_is_denied_without_social_security_manage_permission()
    {
        $rankAndFile = $this->userWithRole('EMPLOYEE', $this->company);
        $entity = SocialSecurityEntity::factory()->create(['company_id' => $this->company->id, 'type' => 'CATEGORY_A']);

        $this->actingAs($rankAndFile)
            ->post(route('employees.social-security-affiliations.store', $this->employee), [
                'employee_id' => $this->employee->id,
                'entity_id' => $entity->id,
                'start_date' => '2025-01-01',
            ])
            ->assertForbidden();

        $this->assertSame(0, SocialSecurityAffiliation::query()->count());
    }

    public function test_an_employee_from_another_company_is_not_visible_or_actionable()
    {
        $foreignEmployee = Employee::factory()->create(['company_id' => Company::factory()->create()->id]);

        $client = $this->actingAs($this->owner);

        // Same warm-up requirement documented on
        // PayrollDeductionPlanControllerTest::test_an_employee_from_another_company_is_not_visible_or_actionable
        // — a request against the owner's own employee first establishes the
        // session's active company before the tenant scope can reject the
        // foreign employee.
        $client->get(route('employees.social-security-affiliations.index', $this->employee));

        $client->get(route('employees.social-security-affiliations.index', $foreignEmployee))->assertNotFound();

        $entity = SocialSecurityEntity::factory()->create(['company_id' => $this->company->id, 'type' => 'CATEGORY_A']);
        $client->post(route('employees.social-security-affiliations.store', $foreignEmployee), [
            'employee_id' => $foreignEmployee->id,
            'entity_id' => $entity->id,
            'start_date' => '2025-01-01',
        ])->assertNotFound();
    }
}
