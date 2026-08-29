<?php

namespace Tests\Unit;

use App\Exceptions\AmbiguousSalaryHistoryException;
use App\Models\Company;
use App\Models\EmploymentContract;
use App\Models\SalaryHistory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class SalaryHistoryActiveAtTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_null_when_no_revision_covers_the_date()
    {
        $company = Company::factory()->create();
        $contract = EmploymentContract::factory()->create(['company_id' => $company->id]);

        SalaryHistory::factory()->create([
            'company_id' => $company->id,
            'contract_id' => $contract->id,
            'effective_from' => '2024-01-01',
            'effective_to' => '2024-06-30',
        ]);

        $this->assertNull(
            SalaryHistory::activeAt($contract->id, Carbon::parse('2023-12-31')),
        );
    }

    public function test_it_returns_the_single_revision_covering_the_date()
    {
        $company = Company::factory()->create();
        $contract = EmploymentContract::factory()->create(['company_id' => $company->id]);

        $closedRevision = SalaryHistory::factory()->create([
            'company_id' => $company->id,
            'contract_id' => $contract->id,
            'effective_from' => '2024-01-01',
            'effective_to' => '2024-06-30',
        ]);

        $currentRevision = SalaryHistory::factory()->create([
            'company_id' => $company->id,
            'contract_id' => $contract->id,
            'effective_from' => '2024-07-01',
            'effective_to' => null,
        ]);

        $this->assertSame(
            $closedRevision->id,
            SalaryHistory::activeAt($contract->id, Carbon::parse('2024-03-15'))->id,
        );

        $this->assertSame(
            $currentRevision->id,
            SalaryHistory::activeAt($contract->id, Carbon::parse('2025-01-01'))->id,
        );
    }

    public function test_it_matches_an_open_ended_revision_for_a_date_after_effective_from()
    {
        $company = Company::factory()->create();
        $contract = EmploymentContract::factory()->create(['company_id' => $company->id]);

        $openEndedRevision = SalaryHistory::factory()->create([
            'company_id' => $company->id,
            'contract_id' => $contract->id,
            'effective_from' => '2024-01-01',
            'effective_to' => null,
        ]);

        $this->assertSame(
            $openEndedRevision->id,
            SalaryHistory::activeAt($contract->id, Carbon::parse('2030-01-01'))->id,
        );
    }

    public function test_it_ignores_revisions_belonging_to_a_different_contract()
    {
        $company = Company::factory()->create();
        $contract = EmploymentContract::factory()->create(['company_id' => $company->id]);
        $otherContract = EmploymentContract::factory()->create(['company_id' => $company->id]);

        SalaryHistory::factory()->create([
            'company_id' => $company->id,
            'contract_id' => $otherContract->id,
            'effective_from' => '2024-01-01',
            'effective_to' => null,
        ]);

        $this->assertNull(
            SalaryHistory::activeAt($contract->id, Carbon::parse('2024-06-01')),
        );
    }

    public function test_it_rejects_an_ambiguous_lookup_when_two_revisions_overlap_without_a_proper_close()
    {
        $company = Company::factory()->create();
        $contract = EmploymentContract::factory()->create(['company_id' => $company->id]);

        // Simulates data that reached an inconsistent state (e.g. inserted
        // outside the validated HTTP flow) — the lookup must refuse to
        // guess which revision applies rather than silently pick one.
        // sqlite does not enforce a Postgres-only EXCLUDE constraint, so
        // this is directly reachable via factory-created rows.
        SalaryHistory::factory()->create([
            'company_id' => $company->id,
            'contract_id' => $contract->id,
            'effective_from' => '2024-01-01',
            'effective_to' => null,
        ]);

        SalaryHistory::factory()->create([
            'company_id' => $company->id,
            'contract_id' => $contract->id,
            'effective_from' => '2024-06-01',
            'effective_to' => null,
        ]);

        $this->expectException(AmbiguousSalaryHistoryException::class);

        SalaryHistory::activeAt($contract->id, Carbon::parse('2024-07-01'));
    }
}
