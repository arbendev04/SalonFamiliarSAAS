<?php

namespace Tests\Feature\SocialSecurity;

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Role;
use App\Models\SocialSecurityEntity;
use App\Models\User;
use App\Models\UserCompanyMembership;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SocialSecurityEntityControllerTest extends TestCase
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

    public function test_index_shows_the_companys_own_entities_and_still_resolves_a_manually_inserted_platform_default()
    {
        // No platform default is ever seeded in production for this catalog
        // (see composed-knitting-dusk.md), but a manually-inserted row here
        // proves effectiveForCompany() still resolves one correctly.
        SocialSecurityEntity::factory()->create(['company_id' => null, 'code' => 'PLA-01', 'name' => 'Entidad plataforma']);
        SocialSecurityEntity::factory()->create(['company_id' => $this->company->id, 'code' => 'EMP-01', 'name' => 'Entidad propia']);

        $response = $this->actingAs($this->owner)->get(route('social-security.entities.index'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('social-security/Entities')
            ->has('entities', 2)
            ->where('entities.0.name', 'Entidad plataforma')
            ->where('entities.0.is_platform_default', true)
            ->where('entities.1.name', 'Entidad propia')
            ->where('entities.1.is_platform_default', false),
        );
    }

    public function test_creating_an_entity_succeeds_with_current_company_id()
    {
        $this->actingAs($this->owner)->post(route('social-security.entities.store'), [
            'type' => 'CATEGORY_A',
            'name' => 'Entidad nueva',
            'code' => 'NEW-01',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $entity = SocialSecurityEntity::query()->where('code', 'NEW-01')->firstOrFail();

        $this->assertSame($this->company->id, $entity->company_id);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'social_security_entity.created',
            'entity_type' => 'social_security_entities',
            'entity_id' => $entity->id,
        ]);
    }

    public function test_updating_the_companys_own_entity_succeeds()
    {
        $entity = SocialSecurityEntity::factory()->create(['company_id' => $this->company->id, 'name' => 'Nombre viejo']);

        $this->actingAs($this->owner)->put(route('social-security.entities.update', $entity), [
            'type' => $entity->type,
            'name' => 'Nombre nuevo',
            'code' => $entity->code,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('Nombre nuevo', $entity->fresh()->name);

        $auditLog = AuditLog::query()
            ->where('entity_type', 'social_security_entities')
            ->where('entity_id', $entity->id)
            ->where('action', 'social_security_entity.updated')
            ->firstOrFail();

        $this->assertSame('Nombre viejo', $auditLog->old_value['name']);
        $this->assertSame('Nombre nuevo', $auditLog->new_value['name']);
    }

    public function test_deleting_the_companys_own_entity_soft_deletes_it()
    {
        $entity = SocialSecurityEntity::factory()->create(['company_id' => $this->company->id]);

        $this->actingAs($this->owner)->delete(route('social-security.entities.destroy', $entity))
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSoftDeleted($entity);

        $auditLog = AuditLog::query()
            ->where('entity_type', 'social_security_entities')
            ->where('entity_id', $entity->id)
            ->where('action', 'social_security_entity.deleted')
            ->firstOrFail();

        $this->assertNull($auditLog->new_value);
    }

    public function test_updating_a_platform_default_entity_is_rejected()
    {
        $entity = SocialSecurityEntity::factory()->create(['company_id' => null]);

        $client = $this->actingAs($this->owner);
        $client->get(route('social-security.entities.index'));

        $client->put(route('social-security.entities.update', $entity), [
            'type' => $entity->type,
            'name' => 'Intento de editar default',
            'code' => $entity->code,
        ])->assertNotFound();

        $this->assertNotSame('Intento de editar default', $entity->fresh()->name);
    }

    public function test_deleting_a_platform_default_entity_is_rejected()
    {
        $entity = SocialSecurityEntity::factory()->create(['company_id' => null]);

        $client = $this->actingAs($this->owner);
        $client->get(route('social-security.entities.index'));

        $client->delete(route('social-security.entities.destroy', $entity))->assertNotFound();

        $this->assertNull($entity->fresh()->deleted_at);
    }

    public function test_updating_another_companys_entity_is_rejected()
    {
        $otherCompany = Company::factory()->create();
        $foreignEntity = SocialSecurityEntity::factory()->create(['company_id' => $otherCompany->id]);

        $client = $this->actingAs($this->owner);
        $client->get(route('social-security.entities.index'));

        $client->put(route('social-security.entities.update', $foreignEntity), [
            'type' => $foreignEntity->type,
            'name' => 'Intento cruzado',
            'code' => $foreignEntity->code,
        ])->assertNotFound();
    }

    public function test_deleting_another_companys_entity_is_rejected()
    {
        $otherCompany = Company::factory()->create();
        $foreignEntity = SocialSecurityEntity::factory()->create(['company_id' => $otherCompany->id]);

        $client = $this->actingAs($this->owner);
        $client->get(route('social-security.entities.index'));

        $client->delete(route('social-security.entities.destroy', $foreignEntity))->assertNotFound();
    }

    public function test_creating_an_entity_with_a_duplicate_code_within_the_same_company_is_rejected()
    {
        SocialSecurityEntity::factory()->create(['company_id' => $this->company->id, 'code' => 'DUP-01']);

        $this->actingAs($this->owner)->post(route('social-security.entities.store'), [
            'type' => 'CATEGORY_A',
            'name' => 'Otra entidad',
            'code' => 'DUP-01',
        ])->assertSessionHasErrors('code');
    }

    public function test_creating_an_entity_with_the_same_code_as_a_different_company_is_allowed()
    {
        $otherCompany = Company::factory()->create();
        SocialSecurityEntity::factory()->create(['company_id' => $otherCompany->id, 'code' => 'SHARED-01']);

        $this->actingAs($this->owner)->post(route('social-security.entities.store'), [
            'type' => 'CATEGORY_A',
            'name' => 'Entidad compartida',
            'code' => 'SHARED-01',
        ])->assertRedirect()->assertSessionHasNoErrors();
    }

    public function test_a_user_without_the_social_security_manage_permission_is_denied_on_index()
    {
        $rankAndFile = $this->createEmployeeUser();

        $this->actingAs($rankAndFile)->get(route('social-security.entities.index'))->assertForbidden();
    }

    public function test_a_user_without_the_social_security_manage_permission_is_denied_on_store()
    {
        $rankAndFile = $this->createEmployeeUser();

        $this->actingAs($rankAndFile)->post(route('social-security.entities.store'), [
            'type' => 'CATEGORY_A',
            'name' => 'Entidad prohibida',
            'code' => 'PRO-01',
        ])->assertForbidden();
    }

    public function test_a_user_without_the_social_security_manage_permission_is_denied_on_update()
    {
        $entity = SocialSecurityEntity::factory()->create(['company_id' => $this->company->id]);
        $rankAndFile = $this->createEmployeeUser();

        $this->actingAs($rankAndFile)->put(route('social-security.entities.update', $entity), [
            'type' => $entity->type,
            'name' => 'Nombre prohibido',
            'code' => $entity->code,
        ])->assertForbidden();
    }

    public function test_a_user_without_the_social_security_manage_permission_is_denied_on_destroy()
    {
        $entity = SocialSecurityEntity::factory()->create(['company_id' => $this->company->id]);
        $rankAndFile = $this->createEmployeeUser();

        $this->actingAs($rankAndFile)->delete(route('social-security.entities.destroy', $entity))->assertForbidden();
    }

    private function createEmployeeUser(): User
    {
        $employeeRole = Role::query()->whereNull('company_id')->where('name', 'EMPLOYEE')->firstOrFail();

        $user = User::factory()->create();

        UserCompanyMembership::create([
            'user_id' => $user->id,
            'company_id' => $this->company->id,
            'role_id' => $employeeRole->id,
            'status' => 'active',
        ]);

        return $user;
    }
}
