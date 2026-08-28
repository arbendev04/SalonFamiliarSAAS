<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use App\Models\UserCompanyMembership;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BranchTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

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
    }

    public function test_creating_a_branch_succeeds()
    {
        $this->actingAs($this->owner)->post(route('branches.store'), [
            'name' => 'Sede Norte',
            'timezone' => 'America/Bogota',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $branch = Branch::query()->where('name', 'Sede Norte')->firstOrFail();

        $this->assertSame($this->company->id, $branch->company_id);
        $this->assertSame('America/Bogota', $branch->timezone);
    }

    public function test_a_user_only_sees_branches_from_their_own_company()
    {
        $otherCompany = Company::factory()->create();

        Branch::factory()->create(['company_id' => $this->company->id, 'name' => 'Sede Propia']);
        Branch::factory()->create(['company_id' => $otherCompany->id, 'name' => 'Sede Ajena']);

        $response = $this->actingAs($this->owner)->get(route('branches.index'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('branches/Index')
            ->has('branches', 1)
            ->where('branches.0.name', 'Sede Propia'),
        );
    }

    public function test_updating_a_branch_succeeds()
    {
        $branch = Branch::factory()->create(['company_id' => $this->company->id, 'name' => 'Sede Vieja']);

        $this->actingAs($this->owner)->put(route('branches.update', $branch), [
            'name' => 'Sede Nueva',
            'timezone' => 'America/Bogota',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('Sede Nueva', $branch->fresh()->name);
    }

    public function test_a_branch_belonging_to_another_company_cannot_be_updated()
    {
        $otherCompany = Company::factory()->create();
        $foreignBranch = Branch::factory()->create(['company_id' => $otherCompany->id]);

        // The active company is resolved onto the session by
        // SetCurrentCompany, which runs after route-model-binding
        // middleware. A prior request establishes that session state, just
        // like a real user who is already logged in and browsing before
        // they hit an update endpoint.
        $client = $this->actingAs($this->owner);
        $client->get(route('branches.index'));

        $client->put(route('branches.update', $foreignBranch), [
            'name' => 'Intento Cruzado',
            'timezone' => 'America/Bogota',
        ])->assertNotFound();
    }

    public function test_deleting_a_branch_soft_deletes_it()
    {
        $branch = Branch::factory()->create(['company_id' => $this->company->id]);

        $this->actingAs($this->owner)->delete(route('branches.destroy', $branch))
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSoftDeleted($branch);
    }

    public function test_a_user_without_the_branches_write_permission_is_denied()
    {
        $employeeRole = Role::query()->whereNull('company_id')->where('name', 'EMPLOYEE')->firstOrFail();

        $rankAndFile = User::factory()->create();

        UserCompanyMembership::create([
            'user_id' => $rankAndFile->id,
            'company_id' => $this->company->id,
            'role_id' => $employeeRole->id,
            'status' => 'active',
        ]);

        $this->actingAs($rankAndFile)->post(route('branches.store'), [
            'name' => 'Sede Prohibida',
            'timezone' => 'America/Bogota',
        ])->assertForbidden();
    }

    public function test_a_user_without_the_branches_read_permission_is_denied()
    {
        $employeeRole = Role::query()->whereNull('company_id')->where('name', 'EMPLOYEE')->firstOrFail();

        $rankAndFile = User::factory()->create();

        UserCompanyMembership::create([
            'user_id' => $rankAndFile->id,
            'company_id' => $this->company->id,
            'role_id' => $employeeRole->id,
            'status' => 'active',
        ]);

        $this->actingAs($rankAndFile)
            ->get(route('branches.index'))
            ->assertForbidden();
    }
}
