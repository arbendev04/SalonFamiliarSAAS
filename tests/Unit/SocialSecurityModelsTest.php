<?php

namespace Tests\Unit;

use App\Models\Company;
use App\Models\Employee;
use App\Models\PayrollEntry;
use App\Models\SocialSecurityAffiliation;
use App\Models\SocialSecurityConceptDefinition;
use App\Models\SocialSecurityContribution;
use App\Models\SocialSecurityEntity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Minimal factory/relationship sanity coverage for the social security
 * models introduced this commit — no behavioral logic exists yet (the
 * effective-dated affiliation lookup, write-time guard, and contribution
 * calculation are all later commits per composed-knitting-dusk.md's commit
 * sequence). Mirrors tests/Feature/PayrollModelsTest.php's posture for the
 * equivalent Fase 9 commit.
 */
class SocialSecurityModelsTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
        $this->employee = Employee::factory()->create(['company_id' => $this->company->id]);
    }

    public function test_a_social_security_entity_can_be_created_and_belongs_to_its_company()
    {
        $entity = SocialSecurityEntity::factory()->create(['company_id' => $this->company->id]);

        $this->assertTrue($entity->company->is($this->company));
        $this->assertSame('CATEGORY_A', $entity->type);
        $this->assertSame('Entidad de prueba', $entity->name);
    }

    public function test_a_platform_default_social_security_entity_has_a_null_company_id()
    {
        $entity = SocialSecurityEntity::factory()->create(['company_id' => null]);

        $this->assertNull($entity->company_id);
    }

    public function test_a_social_security_concept_definition_can_be_created_and_belongs_to_its_company()
    {
        $concept = SocialSecurityConceptDefinition::factory()->create(['company_id' => $this->company->id]);

        $this->assertTrue($concept->company->is($this->company));
        $this->assertSame('CATEGORY_A', $concept->entity_type);
    }

    public function test_a_social_security_affiliation_belongs_to_its_employee_and_entity_and_round_trips_its_date_casts()
    {
        $entity = SocialSecurityEntity::factory()->create(['company_id' => $this->company->id]);
        $affiliation = SocialSecurityAffiliation::factory()->create([
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'entity_id' => $entity->id,
            'start_date' => '2024-01-01',
            'end_date' => '2024-12-31',
        ]);

        $this->assertTrue($affiliation->employee->is($this->employee));
        $this->assertTrue(
            $affiliation->entity()->withoutGlobalScope('company')->first()->is($entity)
        );
        $this->assertTrue($this->employee->socialSecurityAffiliations->contains($affiliation));

        $affiliation->refresh();
        $this->assertSame('2024-01-01', $affiliation->start_date->toDateString());
        $this->assertSame('2024-12-31', $affiliation->end_date->toDateString());
    }

    public function test_a_social_security_contribution_belongs_to_its_payroll_entry_entity_and_concept()
    {
        $entry = PayrollEntry::factory()->create(['company_id' => $this->company->id, 'employee_id' => $this->employee->id]);
        $entity = SocialSecurityEntity::factory()->create(['company_id' => $this->company->id]);
        $concept = SocialSecurityConceptDefinition::factory()->create(['company_id' => $this->company->id]);

        $contribution = SocialSecurityContribution::factory()->create([
            'company_id' => $this->company->id,
            'payroll_entry_id' => $entry->id,
            'entity_id' => $entity->id,
            'concept_id' => $concept->id,
        ]);

        $this->assertTrue($contribution->payrollEntry->is($entry));
        $this->assertTrue(
            $contribution->entity()->withoutGlobalScope('company')->first()->is($entity)
        );
        $this->assertTrue(
            $contribution->concept()->withoutGlobalScope('company')->first()->is($concept)
        );
        $this->assertTrue($entry->socialSecurityContributions->contains($contribution));
        $this->assertTrue($entity->contributions->contains($contribution));
        $this->assertTrue($concept->contributions->contains($contribution));
    }
}
