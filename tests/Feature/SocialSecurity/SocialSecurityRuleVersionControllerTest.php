<?php

namespace Tests\Feature\SocialSecurity;

use App\Models\Company;
use App\Models\LaborRule;
use App\Models\LaborRuleVersion;
use App\Models\PayrollConceptDefinition;
use App\Models\Role;
use App\Models\SocialSecurityConceptDefinition;
use App\Models\User;
use App\Models\UserCompanyMembership;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SocialSecurityRuleVersionControllerTest extends TestCase
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

    public function test_creating_a_version_with_valid_rate_data_succeeds()
    {
        $concept = SocialSecurityConceptDefinition::factory()->create(['company_id' => $this->company->id, 'code' => 'CATEGORY-A']);
        PayrollConceptDefinition::factory()->create(['company_id' => $this->company->id, 'code' => 'BASE-TEST']);

        $this->actingAs($this->owner)->post(route('social-security.rule-versions.store', $concept), [
            'effective_from' => '2026-01-01',
            'parameters' => [
                'employee_pct' => 0.10,
                'employer_pct' => 0.20,
                'base_concept_codes' => ['BASE-TEST'],
            ],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $laborRule = LaborRule::query()
            ->where('company_id', $this->company->id)
            ->where('rule_type', 'SOCIAL_SECURITY_CATEGORY-A')
            ->firstOrFail();

        $version = LaborRuleVersion::query()->where('labor_rule_id', $laborRule->id)->firstOrFail();

        $this->assertSame($this->company->id, $version->company_id);
        $this->assertSame('2026-01-01', $version->effective_from->toDateString());
        $this->assertSame(0.10, $version->parameters['employee_pct']);
        $this->assertSame(0.20, $version->parameters['employer_pct']);
        $this->assertSame(['BASE-TEST'], $version->parameters['base_concept_codes']);
        $this->assertSame($this->owner->id, $version->created_by);
    }

    public function test_store_reuses_the_same_labor_rule_for_the_same_concept_across_calls()
    {
        $concept = SocialSecurityConceptDefinition::factory()->create(['company_id' => $this->company->id, 'code' => 'CATEGORY-B']);
        PayrollConceptDefinition::factory()->create(['company_id' => $this->company->id, 'code' => 'BASE-TEST']);

        $this->actingAs($this->owner)->get(route('social-security.concept-definitions.rule-versions.index', $concept))->assertOk();

        $this->actingAs($this->owner)->post(route('social-security.rule-versions.store', $concept), [
            'effective_from' => '2026-01-01',
            'parameters' => [
                'employee_pct' => 0.10,
                'employer_pct' => 0.20,
                'base_concept_codes' => ['BASE-TEST'],
            ],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(
            1,
            LaborRule::query()
                ->where('company_id', $this->company->id)
                ->where('rule_type', 'SOCIAL_SECURITY_CATEGORY-B')
                ->count(),
        );
    }

    public function test_store_for_two_different_concepts_creates_two_distinct_labor_rules()
    {
        $conceptOne = SocialSecurityConceptDefinition::factory()->create(['company_id' => $this->company->id, 'code' => 'CATEGORY-C']);
        $conceptTwo = SocialSecurityConceptDefinition::factory()->create(['company_id' => $this->company->id, 'code' => 'CATEGORY-D']);
        PayrollConceptDefinition::factory()->create(['company_id' => $this->company->id, 'code' => 'BASE-TEST']);

        $payload = [
            'effective_from' => '2026-01-01',
            'parameters' => [
                'employee_pct' => 0.10,
                'employer_pct' => 0.20,
                'base_concept_codes' => ['BASE-TEST'],
            ],
        ];

        $this->actingAs($this->owner)->post(route('social-security.rule-versions.store', $conceptOne), $payload)->assertRedirect();
        $this->actingAs($this->owner)->post(route('social-security.rule-versions.store', $conceptTwo), $payload)->assertRedirect();

        $ruleOne = LaborRule::query()->where('company_id', $this->company->id)->where('rule_type', 'SOCIAL_SECURITY_CATEGORY-C')->firstOrFail();
        $ruleTwo = LaborRule::query()->where('company_id', $this->company->id)->where('rule_type', 'SOCIAL_SECURITY_CATEGORY-D')->firstOrFail();

        $this->assertNotSame($ruleOne->id, $ruleTwo->id);
    }

    public function test_creating_an_overlapping_version_is_rejected()
    {
        $concept = SocialSecurityConceptDefinition::factory()->create(['company_id' => $this->company->id, 'code' => 'CATEGORY-E']);
        PayrollConceptDefinition::factory()->create(['company_id' => $this->company->id, 'code' => 'BASE-TEST']);

        $laborRule = LaborRule::factory()->create([
            'company_id' => $this->company->id,
            'rule_type' => 'SOCIAL_SECURITY_CATEGORY-E',
        ]);

        LaborRuleVersion::factory()->create([
            'company_id' => $this->company->id,
            'labor_rule_id' => $laborRule->id,
            'effective_from' => '2026-01-01',
            'effective_to' => null,
        ]);

        $response = $this->actingAs($this->owner)->post(route('social-security.rule-versions.store', $concept), [
            'effective_from' => '2026-03-01',
            'parameters' => [
                'employee_pct' => 0.10,
                'employer_pct' => 0.20,
                'base_concept_codes' => ['BASE-TEST'],
            ],
        ]);

        $response->assertSessionHasErrors('effective_from');
        $this->assertSame(1, LaborRuleVersion::query()->where('labor_rule_id', $laborRule->id)->count());
    }

    public function test_missing_effective_from_is_rejected()
    {
        $concept = SocialSecurityConceptDefinition::factory()->create(['company_id' => $this->company->id]);
        PayrollConceptDefinition::factory()->create(['company_id' => $this->company->id, 'code' => 'BASE-TEST']);

        $this->actingAs($this->owner)->post(route('social-security.rule-versions.store', $concept), [
            'parameters' => [
                'employee_pct' => 0.10,
                'employer_pct' => 0.20,
                'base_concept_codes' => ['BASE-TEST'],
            ],
        ])->assertSessionHasErrors('effective_from');
    }

    public function test_missing_employee_pct_is_rejected()
    {
        $concept = SocialSecurityConceptDefinition::factory()->create(['company_id' => $this->company->id]);
        PayrollConceptDefinition::factory()->create(['company_id' => $this->company->id, 'code' => 'BASE-TEST']);

        $this->actingAs($this->owner)->post(route('social-security.rule-versions.store', $concept), [
            'effective_from' => '2026-01-01',
            'parameters' => [
                'employer_pct' => 0.20,
                'base_concept_codes' => ['BASE-TEST'],
            ],
        ])->assertSessionHasErrors('parameters.employee_pct');
    }

    public function test_missing_employer_pct_is_rejected()
    {
        $concept = SocialSecurityConceptDefinition::factory()->create(['company_id' => $this->company->id]);
        PayrollConceptDefinition::factory()->create(['company_id' => $this->company->id, 'code' => 'BASE-TEST']);

        $this->actingAs($this->owner)->post(route('social-security.rule-versions.store', $concept), [
            'effective_from' => '2026-01-01',
            'parameters' => [
                'employee_pct' => 0.10,
                'base_concept_codes' => ['BASE-TEST'],
            ],
        ])->assertSessionHasErrors('parameters.employer_pct');
    }

    public function test_missing_base_concept_codes_is_rejected()
    {
        $concept = SocialSecurityConceptDefinition::factory()->create(['company_id' => $this->company->id]);

        $this->actingAs($this->owner)->post(route('social-security.rule-versions.store', $concept), [
            'effective_from' => '2026-01-01',
            'parameters' => [
                'employee_pct' => 0.10,
                'employer_pct' => 0.20,
            ],
        ])->assertSessionHasErrors('parameters.base_concept_codes');
    }

    public function test_employee_pct_above_one_is_rejected()
    {
        $concept = SocialSecurityConceptDefinition::factory()->create(['company_id' => $this->company->id]);
        PayrollConceptDefinition::factory()->create(['company_id' => $this->company->id, 'code' => 'BASE-TEST']);

        $this->actingAs($this->owner)->post(route('social-security.rule-versions.store', $concept), [
            'effective_from' => '2026-01-01',
            'parameters' => [
                'employee_pct' => 1.5,
                'employer_pct' => 0.20,
                'base_concept_codes' => ['BASE-TEST'],
            ],
        ])->assertSessionHasErrors('parameters.employee_pct');
    }

    public function test_employer_pct_below_zero_is_rejected()
    {
        $concept = SocialSecurityConceptDefinition::factory()->create(['company_id' => $this->company->id]);
        PayrollConceptDefinition::factory()->create(['company_id' => $this->company->id, 'code' => 'BASE-TEST']);

        $this->actingAs($this->owner)->post(route('social-security.rule-versions.store', $concept), [
            'effective_from' => '2026-01-01',
            'parameters' => [
                'employee_pct' => 0.10,
                'employer_pct' => -0.1,
                'base_concept_codes' => ['BASE-TEST'],
            ],
        ])->assertSessionHasErrors('parameters.employer_pct');
    }

    public function test_base_concept_codes_with_an_unknown_code_is_rejected()
    {
        $concept = SocialSecurityConceptDefinition::factory()->create(['company_id' => $this->company->id]);

        $this->actingAs($this->owner)->post(route('social-security.rule-versions.store', $concept), [
            'effective_from' => '2026-01-01',
            'parameters' => [
                'employee_pct' => 0.10,
                'employer_pct' => 0.20,
                'base_concept_codes' => ['DOES-NOT-EXIST'],
            ],
        ])->assertSessionHasErrors('parameters.base_concept_codes.0');
    }

    public function test_base_concept_codes_accepts_a_platform_default_payroll_concept_code()
    {
        $concept = SocialSecurityConceptDefinition::factory()->create(['company_id' => $this->company->id]);
        PayrollConceptDefinition::factory()->create(['company_id' => null, 'code' => 'PLATFORM-BASE']);

        $this->actingAs($this->owner)->post(route('social-security.rule-versions.store', $concept), [
            'effective_from' => '2026-01-01',
            'parameters' => [
                'employee_pct' => 0.10,
                'employer_pct' => 0.20,
                'base_concept_codes' => ['PLATFORM-BASE'],
            ],
        ])->assertRedirect()->assertSessionHasNoErrors();
    }

    public function test_a_concept_from_another_company_cannot_be_targeted_on_index()
    {
        $otherCompany = Company::factory()->create();
        $foreignConcept = SocialSecurityConceptDefinition::factory()->create(['company_id' => $otherCompany->id]);

        $this->actingAs($this->owner)->get(route('social-security.concept-definitions.rule-versions.index', $foreignConcept))
            ->assertNotFound();
    }

    public function test_a_concept_from_another_company_cannot_be_targeted_on_store()
    {
        $otherCompany = Company::factory()->create();
        $foreignConcept = SocialSecurityConceptDefinition::factory()->create(['company_id' => $otherCompany->id]);
        PayrollConceptDefinition::factory()->create(['company_id' => $this->company->id, 'code' => 'BASE-TEST']);

        $response = $this->actingAs($this->owner)->post(route('social-security.rule-versions.store', $foreignConcept), [
            'effective_from' => '2026-01-01',
            'parameters' => [
                'employee_pct' => 0.10,
                'employer_pct' => 0.20,
                'base_concept_codes' => ['BASE-TEST'],
            ],
        ]);

        $response->assertNotFound();
        $this->assertSame(
            0,
            LaborRule::query()->where('company_id', $otherCompany->id)->count(),
        );
    }

    public function test_a_user_without_the_social_security_manage_permission_is_denied_on_index()
    {
        $concept = SocialSecurityConceptDefinition::factory()->create(['company_id' => $this->company->id]);
        $rankAndFile = $this->createEmployeeUser();

        $this->actingAs($rankAndFile)->get(route('social-security.concept-definitions.rule-versions.index', $concept))
            ->assertForbidden();
    }

    public function test_a_user_without_the_social_security_manage_permission_is_denied_on_store()
    {
        $concept = SocialSecurityConceptDefinition::factory()->create(['company_id' => $this->company->id]);
        PayrollConceptDefinition::factory()->create(['company_id' => $this->company->id, 'code' => 'BASE-TEST']);
        $rankAndFile = $this->createEmployeeUser();

        $this->actingAs($rankAndFile)->post(route('social-security.rule-versions.store', $concept), [
            'effective_from' => '2026-01-01',
            'parameters' => [
                'employee_pct' => 0.10,
                'employer_pct' => 0.20,
                'base_concept_codes' => ['BASE-TEST'],
            ],
        ])->assertForbidden();
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
