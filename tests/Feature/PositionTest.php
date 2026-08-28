<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Position;
use App\Models\Role;
use App\Models\User;
use App\Models\UserCompanyMembership;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PositionTest extends TestCase
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

    public function test_creating_a_position_succeeds()
    {
        $this->actingAs($this->owner)->post(route('positions.store'), [
            'code' => 'PAN-01',
            'title' => 'Panadero',
            'department' => 'Bakery',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $position = Position::query()->where('code', 'PAN-01')->firstOrFail();

        $this->assertSame($this->company->id, $position->company_id);
        $this->assertSame('Panadero', $position->title);
    }

    public function test_a_user_only_sees_positions_from_their_own_company()
    {
        $otherCompany = Company::factory()->create();

        Position::factory()->create(['company_id' => $this->company->id, 'code' => 'PROPIA', 'title' => 'Propia']);
        Position::factory()->create(['company_id' => $otherCompany->id, 'code' => 'AJENA', 'title' => 'Ajena']);

        $response = $this->actingAs($this->owner)->get(route('positions.index'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('positions/Index')
            ->has('positions', 1)
            ->where('positions.0.code', 'PROPIA'),
        );
    }

    public function test_updating_a_position_succeeds()
    {
        $position = Position::factory()->create(['company_id' => $this->company->id, 'title' => 'Vendedor']);

        $this->actingAs($this->owner)->put(route('positions.update', $position), [
            'code' => $position->code,
            'title' => 'Vendedor Senior',
            'department' => 'Sales',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('Vendedor Senior', $position->fresh()->title);
    }

    public function test_a_position_belonging_to_another_company_cannot_be_updated()
    {
        $otherCompany = Company::factory()->create();
        $foreignPosition = Position::factory()->create(['company_id' => $otherCompany->id]);

        // The active company is resolved onto the session by
        // SetCurrentCompany, which runs after route-model-binding
        // middleware. A prior request establishes that session state, just
        // like a real user who is already logged in and browsing before
        // they hit an update endpoint.
        $client = $this->actingAs($this->owner);
        $client->get(route('positions.index'));

        $client->put(route('positions.update', $foreignPosition), [
            'code' => $foreignPosition->code,
            'title' => 'Intento Cruzado',
        ])->assertNotFound();
    }

    public function test_deleting_a_position_soft_deletes_it()
    {
        $position = Position::factory()->create(['company_id' => $this->company->id]);

        $this->actingAs($this->owner)->delete(route('positions.destroy', $position))
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSoftDeleted($position);
    }

    public function test_a_user_without_the_positions_write_permission_is_denied()
    {
        $employeeRole = Role::query()->whereNull('company_id')->where('name', 'EMPLOYEE')->firstOrFail();

        $rankAndFile = User::factory()->create();

        UserCompanyMembership::create([
            'user_id' => $rankAndFile->id,
            'company_id' => $this->company->id,
            'role_id' => $employeeRole->id,
            'status' => 'active',
        ]);

        $this->actingAs($rankAndFile)->post(route('positions.store'), [
            'code' => 'PROHIBIDO',
            'title' => 'No permitido',
        ])->assertForbidden();
    }

    public function test_a_user_without_the_positions_read_permission_is_denied()
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
            ->get(route('positions.index'))
            ->assertForbidden();
    }

    public function test_a_duplicate_position_code_within_the_same_company_is_rejected()
    {
        Position::factory()->create(['company_id' => $this->company->id, 'code' => 'DUP-01']);

        $this->actingAs($this->owner)->post(route('positions.store'), [
            'code' => 'DUP-01',
            'title' => 'Otro puesto',
        ])->assertSessionHasErrors('code');
    }

    public function test_the_same_position_code_is_allowed_across_two_different_companies()
    {
        $otherCompany = Company::factory()->create();
        Position::factory()->create(['company_id' => $otherCompany->id, 'code' => 'SHARED-01']);

        $this->actingAs($this->owner)->post(route('positions.store'), [
            'code' => 'SHARED-01',
            'title' => 'Puesto compartido',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(
            $this->company->id,
            Position::query()->where('company_id', $this->company->id)->where('code', 'SHARED-01')->firstOrFail()->company_id,
        );
    }

    public function test_updating_a_position_without_changing_its_code_is_accepted()
    {
        $position = Position::factory()->create(['company_id' => $this->company->id, 'code' => 'KEEP-01']);

        $this->actingAs($this->owner)->put(route('positions.update', $position), [
            'code' => 'KEEP-01',
            'title' => 'Nombre actualizado',
        ])->assertRedirect()->assertSessionHasNoErrors();
    }
}
