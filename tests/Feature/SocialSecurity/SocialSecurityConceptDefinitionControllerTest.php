<?php

namespace Tests\Feature\SocialSecurity;

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Role;
use App\Models\SocialSecurityConceptDefinition;
use App\Models\User;
use App\Models\UserCompanyMembership;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SocialSecurityConceptDefinitionControllerTest extends TestCase
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

    public function test_index_shows_the_companys_own_concepts_and_still_resolves_a_manually_inserted_platform_default()
    {
        // No platform default is ever seeded in production for this catalog
        // (see composed-knitting-dusk.md), but a manually-inserted row here
        // proves effectiveForCompany() still resolves one correctly.
        SocialSecurityConceptDefinition::factory()->create(['company_id' => null, 'code' => 'PLA-01', 'name' => 'Concepto plataforma']);
        SocialSecurityConceptDefinition::factory()->create(['company_id' => $this->company->id, 'code' => 'EMP-01', 'name' => 'Concepto propio']);

        $response = $this->actingAs($this->owner)->get(route('social-security.concept-definitions.index'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('social-security/ConceptDefinitions')
            ->has('concepts', 2)
            ->where('concepts.0.name', 'Concepto plataforma')
            ->where('concepts.0.is_platform_default', true)
            ->where('concepts.1.name', 'Concepto propio')
            ->where('concepts.1.is_platform_default', false),
        );
    }

    public function test_creating_a_concept_succeeds_with_current_company_id()
    {
        $this->actingAs($this->owner)->post(route('social-security.concept-definitions.store'), [
            'code' => 'NEW-01',
            'name' => 'Concepto nuevo',
            'entity_type' => 'CATEGORY_A',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $concept = SocialSecurityConceptDefinition::query()->where('code', 'NEW-01')->firstOrFail();

        $this->assertSame($this->company->id, $concept->company_id);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'social_security_concept_definition.created',
            'entity_type' => 'social_security_concept_definitions',
            'entity_id' => $concept->id,
        ]);
    }

    public function test_updating_the_companys_own_concept_succeeds()
    {
        $concept = SocialSecurityConceptDefinition::factory()->create(['company_id' => $this->company->id, 'name' => 'Nombre viejo']);

        $this->actingAs($this->owner)->put(route('social-security.concept-definitions.update', $concept), [
            'code' => $concept->code,
            'name' => 'Nombre nuevo',
            'entity_type' => $concept->entity_type,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('Nombre nuevo', $concept->fresh()->name);

        $auditLog = AuditLog::query()
            ->where('entity_type', 'social_security_concept_definitions')
            ->where('entity_id', $concept->id)
            ->where('action', 'social_security_concept_definition.updated')
            ->firstOrFail();

        $this->assertSame('Nombre viejo', $auditLog->old_value['name']);
        $this->assertSame('Nombre nuevo', $auditLog->new_value['name']);
    }

    public function test_deleting_the_companys_own_concept_soft_deletes_it()
    {
        $concept = SocialSecurityConceptDefinition::factory()->create(['company_id' => $this->company->id]);

        $this->actingAs($this->owner)->delete(route('social-security.concept-definitions.destroy', $concept))
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSoftDeleted($concept);

        $auditLog = AuditLog::query()
            ->where('entity_type', 'social_security_concept_definitions')
            ->where('entity_id', $concept->id)
            ->where('action', 'social_security_concept_definition.deleted')
            ->firstOrFail();

        $this->assertNull($auditLog->new_value);
    }

    public function test_updating_a_platform_default_concept_is_rejected()
    {
        $concept = SocialSecurityConceptDefinition::factory()->create(['company_id' => null]);

        $client = $this->actingAs($this->owner);
        $client->get(route('social-security.concept-definitions.index'));

        $client->put(route('social-security.concept-definitions.update', $concept), [
            'code' => $concept->code,
            'name' => 'Intento de editar default',
            'entity_type' => $concept->entity_type,
        ])->assertNotFound();

        $this->assertNotSame('Intento de editar default', $concept->fresh()->name);
    }

    public function test_deleting_a_platform_default_concept_is_rejected()
    {
        $concept = SocialSecurityConceptDefinition::factory()->create(['company_id' => null]);

        $client = $this->actingAs($this->owner);
        $client->get(route('social-security.concept-definitions.index'));

        $client->delete(route('social-security.concept-definitions.destroy', $concept))->assertNotFound();

        $this->assertNull($concept->fresh()->deleted_at);
    }

    public function test_updating_another_companys_concept_is_rejected()
    {
        $otherCompany = Company::factory()->create();
        $foreignConcept = SocialSecurityConceptDefinition::factory()->create(['company_id' => $otherCompany->id]);

        $client = $this->actingAs($this->owner);
        $client->get(route('social-security.concept-definitions.index'));

        $client->put(route('social-security.concept-definitions.update', $foreignConcept), [
            'code' => $foreignConcept->code,
            'name' => 'Intento cruzado',
            'entity_type' => $foreignConcept->entity_type,
        ])->assertNotFound();
    }

    public function test_deleting_another_companys_concept_is_rejected()
    {
        $otherCompany = Company::factory()->create();
        $foreignConcept = SocialSecurityConceptDefinition::factory()->create(['company_id' => $otherCompany->id]);

        $client = $this->actingAs($this->owner);
        $client->get(route('social-security.concept-definitions.index'));

        $client->delete(route('social-security.concept-definitions.destroy', $foreignConcept))->assertNotFound();
    }

    public function test_creating_a_concept_with_a_duplicate_code_within_the_same_company_is_rejected()
    {
        SocialSecurityConceptDefinition::factory()->create(['company_id' => $this->company->id, 'code' => 'DUP-01']);

        $this->actingAs($this->owner)->post(route('social-security.concept-definitions.store'), [
            'code' => 'DUP-01',
            'name' => 'Otro concepto',
            'entity_type' => 'CATEGORY_A',
        ])->assertSessionHasErrors('code');
    }

    public function test_creating_a_concept_with_the_same_code_as_a_different_company_is_allowed()
    {
        $otherCompany = Company::factory()->create();
        SocialSecurityConceptDefinition::factory()->create(['company_id' => $otherCompany->id, 'code' => 'SHARED-01']);

        $this->actingAs($this->owner)->post(route('social-security.concept-definitions.store'), [
            'code' => 'SHARED-01',
            'name' => 'Concepto compartido',
            'entity_type' => 'CATEGORY_A',
        ])->assertRedirect()->assertSessionHasNoErrors();
    }

    public function test_a_user_without_the_social_security_manage_permission_is_denied_on_index()
    {
        $rankAndFile = $this->createEmployeeUser();

        $this->actingAs($rankAndFile)->get(route('social-security.concept-definitions.index'))->assertForbidden();
    }

    public function test_a_user_without_the_social_security_manage_permission_is_denied_on_store()
    {
        $rankAndFile = $this->createEmployeeUser();

        $this->actingAs($rankAndFile)->post(route('social-security.concept-definitions.store'), [
            'code' => 'PRO-01',
            'name' => 'Concepto prohibido',
            'entity_type' => 'CATEGORY_A',
        ])->assertForbidden();
    }

    public function test_a_user_without_the_social_security_manage_permission_is_denied_on_update()
    {
        $concept = SocialSecurityConceptDefinition::factory()->create(['company_id' => $this->company->id]);
        $rankAndFile = $this->createEmployeeUser();

        $this->actingAs($rankAndFile)->put(route('social-security.concept-definitions.update', $concept), [
            'code' => $concept->code,
            'name' => 'Nombre prohibido',
            'entity_type' => $concept->entity_type,
        ])->assertForbidden();
    }

    public function test_a_user_without_the_social_security_manage_permission_is_denied_on_destroy()
    {
        $concept = SocialSecurityConceptDefinition::factory()->create(['company_id' => $this->company->id]);
        $rankAndFile = $this->createEmployeeUser();

        $this->actingAs($rankAndFile)->delete(route('social-security.concept-definitions.destroy', $concept))->assertForbidden();
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
