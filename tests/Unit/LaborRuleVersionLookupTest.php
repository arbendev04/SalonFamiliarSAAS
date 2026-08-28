<?php

namespace Tests\Unit;

use App\Exceptions\AmbiguousLaborRuleVersionException;
use App\Models\Company;
use App\Models\LaborRule;
use App\Models\LaborRuleVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class LaborRuleVersionLookupTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_null_when_no_version_covers_the_date()
    {
        $company = Company::factory()->create();
        $laborRule = LaborRule::factory()->create(['company_id' => $company->id]);

        LaborRuleVersion::factory()->create([
            'company_id' => $company->id,
            'labor_rule_id' => $laborRule->id,
            'effective_from' => '2024-01-01',
            'effective_to' => '2024-06-30',
        ]);

        $this->assertNull(
            LaborRuleVersion::activeFor($laborRule->id, Carbon::parse('2023-12-31')),
        );
    }

    public function test_it_returns_the_single_version_covering_the_date()
    {
        $company = Company::factory()->create();
        $laborRule = LaborRule::factory()->create(['company_id' => $company->id]);

        $closedVersion = LaborRuleVersion::factory()->create([
            'company_id' => $company->id,
            'labor_rule_id' => $laborRule->id,
            'effective_from' => '2024-01-01',
            'effective_to' => '2024-06-30',
        ]);

        $currentVersion = LaborRuleVersion::factory()->create([
            'company_id' => $company->id,
            'labor_rule_id' => $laborRule->id,
            'effective_from' => '2024-07-01',
            'effective_to' => null,
        ]);

        $this->assertSame(
            $closedVersion->id,
            LaborRuleVersion::activeFor($laborRule->id, Carbon::parse('2024-03-15'))->id,
        );

        $this->assertSame(
            $currentVersion->id,
            LaborRuleVersion::activeFor($laborRule->id, Carbon::parse('2025-01-01'))->id,
        );
    }

    public function test_it_matches_an_open_ended_version_for_a_date_after_effective_from()
    {
        $company = Company::factory()->create();
        $laborRule = LaborRule::factory()->create(['company_id' => $company->id]);

        $openEndedVersion = LaborRuleVersion::factory()->create([
            'company_id' => $company->id,
            'labor_rule_id' => $laborRule->id,
            'effective_from' => '2024-01-01',
            'effective_to' => null,
        ]);

        $this->assertSame(
            $openEndedVersion->id,
            LaborRuleVersion::activeFor($laborRule->id, Carbon::parse('2030-01-01'))->id,
        );
    }

    public function test_it_ignores_versions_belonging_to_a_different_labor_rule()
    {
        $company = Company::factory()->create();
        $laborRule = LaborRule::factory()->create(['company_id' => $company->id, 'rule_type' => 'STANDARD_WORKWEEK']);
        $otherLaborRule = LaborRule::factory()->create(['company_id' => $company->id, 'rule_type' => 'OTHER_RULE']);

        LaborRuleVersion::factory()->create([
            'company_id' => $company->id,
            'labor_rule_id' => $otherLaborRule->id,
            'effective_from' => '2024-01-01',
            'effective_to' => null,
        ]);

        $this->assertNull(
            LaborRuleVersion::activeFor($laborRule->id, Carbon::parse('2024-06-01')),
        );
    }

    public function test_it_rejects_an_ambiguous_lookup_when_two_versions_overlap_without_a_proper_close()
    {
        $company = Company::factory()->create();
        $laborRule = LaborRule::factory()->create(['company_id' => $company->id]);

        // Simulates data that reached an inconsistent state (e.g. inserted
        // outside the validated HTTP flow) — the lookup must refuse to
        // guess which version applies rather than silently pick one.
        // sqlite does not enforce the Postgres-only EXCLUDE constraint, so
        // this is directly reachable via factory-created rows.
        LaborRuleVersion::factory()->create([
            'company_id' => $company->id,
            'labor_rule_id' => $laborRule->id,
            'effective_from' => '2024-01-01',
            'effective_to' => null,
        ]);

        LaborRuleVersion::factory()->create([
            'company_id' => $company->id,
            'labor_rule_id' => $laborRule->id,
            'effective_from' => '2024-06-01',
            'effective_to' => null,
        ]);

        $this->expectException(AmbiguousLaborRuleVersionException::class);

        LaborRuleVersion::activeFor($laborRule->id, Carbon::parse('2024-07-01'));
    }
}
