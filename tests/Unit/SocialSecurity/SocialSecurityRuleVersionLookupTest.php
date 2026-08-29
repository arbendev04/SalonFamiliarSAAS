<?php

namespace Tests\Unit\SocialSecurity;

use App\Exceptions\AmbiguousLaborRuleVersionException;
use App\Models\Company;
use App\Models\LaborRule;
use App\Models\LaborRuleVersion;
use App\Models\SocialSecurityConceptDefinition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * LaborRuleVersion::activeFor() is already covered generically by
 * tests/Unit/LaborRuleVersionLookupTest.php (Fase 7). This mirrors that
 * coverage specifically for the `SOCIAL_SECURITY_<code>` rule_type naming
 * convention introduced by SocialSecurityRuleVersionController (commit 6),
 * confirming a service resolving "the active contribution rate for concept
 * X on date D" gets the right version, excludes versions outside their
 * date range, and holds two concepts' rates fully independent.
 */
class SocialSecurityRuleVersionLookupTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
    }

    private function laborRuleFor(SocialSecurityConceptDefinition $concept): LaborRule
    {
        return LaborRule::factory()->create([
            'company_id' => $this->company->id,
            'rule_type' => 'SOCIAL_SECURITY_'.$concept->code,
        ]);
    }

    public function test_it_returns_the_version_covering_the_query_date()
    {
        $concept = SocialSecurityConceptDefinition::factory()->create([
            'company_id' => $this->company->id,
            'code' => 'CATEGORY_A',
        ]);
        $laborRule = $this->laborRuleFor($concept);

        $version = LaborRuleVersion::factory()->create([
            'company_id' => $this->company->id,
            'labor_rule_id' => $laborRule->id,
            'effective_from' => '2024-01-01',
            'effective_to' => '2024-06-30',
            'parameters' => ['employee_pct' => 0.10, 'employer_pct' => 0.20, 'base_concept_codes' => ['BASE_SALARY']],
        ]);

        $this->assertSame(
            $version->id,
            LaborRuleVersion::activeFor($laborRule->id, Carbon::parse('2024-03-15'))->id,
        );
    }

    public function test_it_matches_an_open_ended_version_for_any_date_on_or_after_effective_from()
    {
        $concept = SocialSecurityConceptDefinition::factory()->create([
            'company_id' => $this->company->id,
            'code' => 'CATEGORY_A',
        ]);
        $laborRule = $this->laborRuleFor($concept);

        $openEndedVersion = LaborRuleVersion::factory()->create([
            'company_id' => $this->company->id,
            'labor_rule_id' => $laborRule->id,
            'effective_from' => '2024-07-01',
            'effective_to' => null,
        ]);

        $this->assertSame(
            $openEndedVersion->id,
            LaborRuleVersion::activeFor($laborRule->id, Carbon::parse('2024-07-01'))->id,
        );

        $this->assertSame(
            $openEndedVersion->id,
            LaborRuleVersion::activeFor($laborRule->id, Carbon::parse('2030-01-01'))->id,
        );
    }

    public function test_it_returns_null_for_a_date_before_any_version_starts()
    {
        $concept = SocialSecurityConceptDefinition::factory()->create([
            'company_id' => $this->company->id,
            'code' => 'CATEGORY_A',
        ]);
        $laborRule = $this->laborRuleFor($concept);

        LaborRuleVersion::factory()->create([
            'company_id' => $this->company->id,
            'labor_rule_id' => $laborRule->id,
            'effective_from' => '2024-01-01',
            'effective_to' => null,
        ]);

        $this->assertNull(
            LaborRuleVersion::activeFor($laborRule->id, Carbon::parse('2023-12-31')),
        );
    }

    public function test_it_returns_null_for_a_gap_after_one_version_closes_and_before_the_next_starts()
    {
        $concept = SocialSecurityConceptDefinition::factory()->create([
            'company_id' => $this->company->id,
            'code' => 'CATEGORY_A',
        ]);
        $laborRule = $this->laborRuleFor($concept);

        LaborRuleVersion::factory()->create([
            'company_id' => $this->company->id,
            'labor_rule_id' => $laborRule->id,
            'effective_from' => '2024-01-01',
            'effective_to' => '2024-03-31',
        ]);

        LaborRuleVersion::factory()->create([
            'company_id' => $this->company->id,
            'labor_rule_id' => $laborRule->id,
            'effective_from' => '2024-07-01',
            'effective_to' => null,
        ]);

        $this->assertNull(
            LaborRuleVersion::activeFor($laborRule->id, Carbon::parse('2024-05-01')),
        );
    }

    public function test_it_rejects_an_ambiguous_lookup_when_two_versions_of_the_same_concept_overlap()
    {
        $concept = SocialSecurityConceptDefinition::factory()->create([
            'company_id' => $this->company->id,
            'code' => 'CATEGORY_A',
        ]);
        $laborRule = $this->laborRuleFor($concept);

        // Simulates data that reached an inconsistent state (e.g. inserted
        // outside the validated HTTP flow) — the lookup must refuse to
        // guess which version applies rather than silently pick one.
        // sqlite does not enforce the Postgres-only EXCLUDE constraint, so
        // this is directly reachable via factory-created rows.
        LaborRuleVersion::factory()->create([
            'company_id' => $this->company->id,
            'labor_rule_id' => $laborRule->id,
            'effective_from' => '2024-01-01',
            'effective_to' => null,
        ]);

        LaborRuleVersion::factory()->create([
            'company_id' => $this->company->id,
            'labor_rule_id' => $laborRule->id,
            'effective_from' => '2024-06-01',
            'effective_to' => null,
        ]);

        $this->expectException(AmbiguousLaborRuleVersionException::class);

        LaborRuleVersion::activeFor($laborRule->id, Carbon::parse('2024-07-01'));
    }

    public function test_two_different_social_security_concepts_resolve_their_own_version_independently()
    {
        $conceptA = SocialSecurityConceptDefinition::factory()->create([
            'company_id' => $this->company->id,
            'code' => 'CATEGORY_A',
        ]);
        $conceptB = SocialSecurityConceptDefinition::factory()->create([
            'company_id' => $this->company->id,
            'code' => 'CATEGORY_B',
        ]);

        $laborRuleA = $this->laborRuleFor($conceptA);
        $laborRuleB = $this->laborRuleFor($conceptB);

        // Same overlapping date range on purpose — proves the lookup is
        // scoped by labor_rule_id (and therefore by rule_type/concept), not
        // just by date, even when both concepts' ranges coincide exactly.
        $versionA = LaborRuleVersion::factory()->create([
            'company_id' => $this->company->id,
            'labor_rule_id' => $laborRuleA->id,
            'effective_from' => '2024-01-01',
            'effective_to' => null,
            'parameters' => ['employee_pct' => 0.10, 'employer_pct' => 0.20, 'base_concept_codes' => ['BASE_SALARY']],
        ]);

        $versionB = LaborRuleVersion::factory()->create([
            'company_id' => $this->company->id,
            'labor_rule_id' => $laborRuleB->id,
            'effective_from' => '2024-01-01',
            'effective_to' => null,
            'parameters' => ['employee_pct' => 0.20, 'employer_pct' => 0.10, 'base_concept_codes' => ['BASE_SALARY']],
        ]);

        $resolvedA = LaborRuleVersion::activeFor($laborRuleA->id, Carbon::parse('2024-08-01'));
        $resolvedB = LaborRuleVersion::activeFor($laborRuleB->id, Carbon::parse('2024-08-01'));

        $this->assertSame($versionA->id, $resolvedA->id);
        $this->assertSame($versionB->id, $resolvedB->id);
        $this->assertNotSame($resolvedA->id, $resolvedB->id);
    }
}
