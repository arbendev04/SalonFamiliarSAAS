<?php

namespace Tests\Unit\Payroll;

use App\Exceptions\AmbiguousContractException;
use App\Exceptions\InvalidPayrollPeriodStatusException;
use App\Exceptions\MissingLaborRuleParameterException;
use App\Exceptions\NoActiveLaborRuleVersionException;
use App\Exceptions\NoActiveSocialSecurityAffiliationException;
use App\Exceptions\NoAttendanceOrNoveltyDataException;
use App\Models\AttendanceRecord;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmploymentContract;
use App\Models\LaborRule;
use App\Models\LaborRuleVersion;
use App\Models\NoveltyRecord;
use App\Models\OvertimeRecord;
use App\Models\PayrollConceptDefinition;
use App\Models\PayrollDeductionPlan;
use App\Models\PayrollEntry;
use App\Models\PayrollEntryLine;
use App\Models\PayrollPeriod;
use App\Models\SalaryHistory;
use App\Models\Shift;
use App\Models\SocialSecurityAffiliation;
use App\Models\SocialSecurityConceptDefinition;
use App\Models\SocialSecurityEntity;
use App\Services\Payroll\PayrollCalculationService;
use App\Services\Tenancy\CurrentCompany;
use Carbon\CarbonInterface;
use Database\Seeders\PayrollConceptCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Tests\TestCase;

class PayrollCalculationServiceTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Employee $employee;

    private PayrollCalculationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
        $this->employee = Employee::factory()->create(['company_id' => $this->company->id]);

        // resolveContractSubRanges()/resolveDailyRate()/proratedBaseSalaryLines()
        // stay protected on the real service: this commit builds them purely
        // as internal building blocks for calculateForEmployee()/
        // calculateForPeriod() (commit 10), which are the class's actual
        // public surface — mirroring how TimeCalculationEngine keeps every
        // computational helper private behind calculateForDate()/
        // calculateForRange(). Since those public entry points don't exist
        // yet in this commit, a throwaway anonymous subclass exposes the
        // protected methods as public wrappers for direct testing, without
        // ever widening the real class's visibility.
        $this->service = new class extends PayrollCalculationService
        {
            /**
             * @return Collection<int, array{contract: EmploymentContract, from: CarbonInterface, to: CarbonInterface}>
             */
            public function callResolveContractSubRanges(Employee $employee, PayrollPeriod $period): Collection
            {
                return $this->resolveContractSubRanges($employee, $period);
            }

            /**
             * @return Collection<int, array{affiliation: SocialSecurityAffiliation, from: CarbonInterface, to: CarbonInterface}>
             */
            public function callResolveAffiliationSubRanges(Employee $employee, string $entityType, PayrollPeriod $period): Collection
            {
                return $this->resolveAffiliationSubRanges($employee, $entityType, $period);
            }

            /**
             * @param  Collection<int, array{affiliation: SocialSecurityAffiliation, from: CarbonInterface, to: CarbonInterface}>  $subRanges
             */
            public function callAssertAffiliationSubRangesTilePeriodExactly(Collection $subRanges, PayrollPeriod $period): void
            {
                $this->assertAffiliationSubRangesTilePeriodExactly($subRanges, $period);
            }

            public function callResolveDailyRate(EmploymentContract $contract, CarbonInterface $from): float
            {
                return $this->resolveDailyRate($contract, $from);
            }

            /**
             * @return array{lines: Collection<int, array{contract_id: string, quantity: float, rate: float, amount: float}>, last_contract: EmploymentContract}
             */
            public function callProratedBaseSalaryLines(Employee $employee, PayrollPeriod $period): array
            {
                return $this->proratedBaseSalaryLines($employee, $period);
            }

            public function callAssertHasAttendanceOrNoveltyCoverage(Employee $employee, PayrollPeriod $period): void
            {
                $this->assertHasAttendanceOrNoveltyCoverage($employee, $period);
            }

            /**
             * @return Collection<int, array{concept_code: string, quantity: float, rate: float, amount: float}>
             */
            public function callAuthorizedOvertimeLines(Employee $employee, PayrollPeriod $period): Collection
            {
                return $this->authorizedOvertimeLines($employee, $period);
            }

            /**
             * @return Collection<int, array{concept_id: string, plan_id: string, quantity: null, rate: null, amount: float}>
             */
            public function callFixedDeductionLines(Employee $employee): Collection
            {
                return $this->fixedDeductionLines($employee);
            }

            public function callResolveActiveSocialSecurityRuleVersion(Employee $employee, string $conceptCode, CarbonInterface $date): ?LaborRuleVersion
            {
                return $this->resolveActiveSocialSecurityRuleVersion($employee, $conceptCode, $date);
            }

            /**
             * @return array{0: float, 1: float, 2: array<int, string>}
             */
            public function callRequireSocialSecurityRateParameters(LaborRuleVersion $version): array
            {
                return $this->requireSocialSecurityRateParameters($version);
            }

            /**
             * @param  Collection<int, array{concept_id: string, amount: float}>  $earningLines
             * @return Collection<int, array{concept: SocialSecurityConceptDefinition, entity: SocialSecurityEntity, period_from: CarbonInterface, period_to: CarbonInterface, base_amount: float, employee_amount: float, employer_amount: float}>
             */
            public function callSocialSecurityContributionLines(Employee $employee, PayrollPeriod $period, Collection $earningLines): Collection
            {
                return $this->socialSecurityContributionLines($employee, $period, $earningLines);
            }
        };
    }

    private function period(string $start, string $end): PayrollPeriod
    {
        return PayrollPeriod::factory()->create([
            'company_id' => $this->company->id,
            'start_date' => $start,
            'end_date' => $end,
        ]);
    }

    private function contract(string $start, ?string $end, float $baseSalary): EmploymentContract
    {
        return EmploymentContract::factory()->create([
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'start_date' => $start,
            'end_date' => $end,
            'base_salary' => $baseSalary,
        ]);
    }

    private function affiliation(string $entityType, string $start, ?string $end): SocialSecurityAffiliation
    {
        return SocialSecurityAffiliation::factory()->create([
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'entity_type' => $entityType,
            'start_date' => $start,
            'end_date' => $end,
        ]);
    }

    private function socialSecurityConcept(string $entityType): SocialSecurityConceptDefinition
    {
        return SocialSecurityConceptDefinition::factory()->create([
            'company_id' => $this->company->id,
            'entity_type' => $entityType,
        ]);
    }

    /**
     * Same reuse-or-create shape as laborRuleVersion(), but scoped to a
     * given social-security concept's own `rule_type =
     * 'SOCIAL_SECURITY_' . $concept->code`, per
     * SocialSecurityRuleVersionController::resolveLaborRule()'s convention.
     */
    private function socialSecurityRuleVersion(SocialSecurityConceptDefinition $concept, array $parameters, ?string $effectiveFrom = null): LaborRuleVersion
    {
        $laborRule = $this->socialSecurityLaborRule($concept);

        return LaborRuleVersion::factory()->create([
            'company_id' => $this->company->id,
            'labor_rule_id' => $laborRule->id,
            'effective_from' => $effectiveFrom ?? '2025-01-01',
            'effective_to' => null,
            'parameters' => $parameters,
        ]);
    }

    /**
     * Creates the underlying LaborRule for a concept's rate WITHOUT any
     * LaborRuleVersion — the "rate rule started but never a version was
     * added" scenario, distinct from "no LaborRule row at all".
     */
    private function socialSecurityLaborRule(SocialSecurityConceptDefinition $concept): LaborRule
    {
        return LaborRule::query()
            ->where('company_id', $this->company->id)
            ->where('rule_type', 'SOCIAL_SECURITY_'.$concept->code)
            ->first() ?? LaborRule::factory()->create([
                'company_id' => $this->company->id,
                'rule_type' => 'SOCIAL_SECURITY_'.$concept->code,
            ]);
    }

    public function test_a_single_contract_spanning_the_whole_period_produces_one_sub_range_bounded_by_the_period()
    {
        $period = $this->period('2025-01-01', '2025-01-15');
        $contract = $this->contract('2024-06-01', null, 3000000);

        $subRanges = $this->service->callResolveContractSubRanges($this->employee, $period);

        $this->assertCount(1, $subRanges);
        $this->assertSame($contract->id, $subRanges->first()['contract']->id);
        $this->assertTrue($subRanges->first()['from']->equalTo(Carbon::parse('2025-01-01')));
        $this->assertTrue($subRanges->first()['to']->equalTo(Carbon::parse('2025-01-15')));
    }

    public function test_a_contract_wider_than_the_period_produces_a_sub_range_clipped_to_the_periods_own_bounds()
    {
        $period = $this->period('2025-03-01', '2025-03-15');
        // Deliberately wider on both sides than the period.
        $this->contract('2024-01-01', '2025-12-31', 3000000);

        $subRanges = $this->service->callResolveContractSubRanges($this->employee, $period);

        $this->assertCount(1, $subRanges);
        $this->assertTrue($subRanges->first()['from']->equalTo(Carbon::parse('2025-03-01')));
        $this->assertTrue($subRanges->first()['to']->equalTo(Carbon::parse('2025-03-15')));
    }

    public function test_a_contract_split_mid_period_produces_two_contiguous_sub_ranges_covering_the_whole_period()
    {
        $period = $this->period('2025-03-01', '2025-03-15');
        $before = $this->contract('2025-01-01', '2025-03-08', 3100000);
        $after = $this->contract('2025-03-09', null, 4650000);

        $subRanges = $this->service->callResolveContractSubRanges($this->employee, $period);

        $this->assertCount(2, $subRanges);

        $this->assertSame($before->id, $subRanges->first()['contract']->id);
        $this->assertTrue($subRanges->first()['from']->equalTo(Carbon::parse('2025-03-01')));
        $this->assertTrue($subRanges->first()['to']->equalTo(Carbon::parse('2025-03-08')));

        $this->assertSame($after->id, $subRanges->last()['contract']->id);
        $this->assertTrue($subRanges->last()['from']->equalTo(Carbon::parse('2025-03-09')));
        $this->assertTrue($subRanges->last()['to']->equalTo(Carbon::parse('2025-03-15')));
    }

    public function test_a_gap_between_two_contracts_mid_period_throws_ambiguous_contract_exception()
    {
        $period = $this->period('2025-02-01', '2025-02-15');
        // No contract at all covers 2025-02-06 through 2025-02-09.
        $this->contract('2025-01-01', '2025-02-05', 3000000);
        $this->contract('2025-02-10', null, 3000000);

        $this->expectException(AmbiguousContractException::class);

        $this->service->callResolveContractSubRanges($this->employee, $period);
    }

    public function test_an_overlap_between_two_contracts_mid_period_throws_ambiguous_contract_exception()
    {
        $period = $this->period('2025-02-01', '2025-02-15');
        // Both contracts cover 2025-02-05 through 2025-02-10 — data
        // corruption from a contract that was never closed correctly.
        $this->contract('2025-02-01', '2025-02-10', 3000000);
        $this->contract('2025-02-05', '2025-02-15', 3000000);

        $this->expectException(AmbiguousContractException::class);

        $this->service->callResolveContractSubRanges($this->employee, $period);
    }

    public function test_zero_contracts_covering_the_period_throws_ambiguous_contract_exception()
    {
        $period = $this->period('2025-02-01', '2025-02-15');

        $this->expectException(AmbiguousContractException::class);

        $this->service->callResolveContractSubRanges($this->employee, $period);
    }

    public function test_zero_affiliations_for_the_entity_type_over_the_whole_period_returns_an_empty_collection_without_throwing()
    {
        $period = $this->period('2025-01-01', '2025-01-15');
        // Deliberately no SocialSecurityAffiliation at all for 'CATEGORY_A'
        // — a company that has not configured/affiliated this employee for
        // this entity_type yet still calculates payroll fine, just without
        // this concept's line (unlike the contract case, this is never an
        // error).

        $subRanges = $this->service->callResolveAffiliationSubRanges($this->employee, 'CATEGORY_A', $period);

        $this->assertCount(0, $subRanges);
    }

    public function test_one_affiliation_covering_the_whole_period_exactly_produces_a_single_sub_range_bounded_by_the_period()
    {
        $period = $this->period('2025-01-01', '2025-01-15');
        $affiliation = $this->affiliation('CATEGORY_A', '2024-06-01', null);

        $subRanges = $this->service->callResolveAffiliationSubRanges($this->employee, 'CATEGORY_A', $period);

        $this->assertCount(1, $subRanges);
        $this->assertSame($affiliation->id, $subRanges->first()['affiliation']->id);
        $this->assertTrue($subRanges->first()['from']->equalTo(Carbon::parse('2025-01-01')));
        $this->assertTrue($subRanges->first()['to']->equalTo(Carbon::parse('2025-01-15')));
    }

    public function test_two_affiliations_tiling_the_period_with_a_boundary_mid_period_produce_two_correctly_clipped_sub_ranges()
    {
        $period = $this->period('2025-03-01', '2025-03-15');
        $before = $this->affiliation('CATEGORY_A', '2025-01-01', '2025-03-08');
        $after = $this->affiliation('CATEGORY_A', '2025-03-09', null);

        $subRanges = $this->service->callResolveAffiliationSubRanges($this->employee, 'CATEGORY_A', $period);

        $this->assertCount(2, $subRanges);

        $this->assertSame($before->id, $subRanges->first()['affiliation']->id);
        $this->assertTrue($subRanges->first()['from']->equalTo(Carbon::parse('2025-03-01')));
        $this->assertTrue($subRanges->first()['to']->equalTo(Carbon::parse('2025-03-08')));

        $this->assertSame($after->id, $subRanges->last()['affiliation']->id);
        $this->assertTrue($subRanges->last()['from']->equalTo(Carbon::parse('2025-03-09')));
        $this->assertTrue($subRanges->last()['to']->equalTo(Carbon::parse('2025-03-15')));
    }

    public function test_a_gap_between_two_affiliations_mid_period_throws_no_active_social_security_affiliation_exception()
    {
        $period = $this->period('2025-02-01', '2025-02-15');
        // No affiliation at all covers 2025-02-06 through 2025-02-09, but at
        // least one affiliation of this entity_type exists somewhere in the
        // period — this makes the gap an error, unlike the zero-affiliations
        // case above.
        $this->affiliation('CATEGORY_A', '2025-01-01', '2025-02-05');
        $this->affiliation('CATEGORY_A', '2025-02-10', null);

        $this->expectException(NoActiveSocialSecurityAffiliationException::class);

        $this->service->callResolveAffiliationSubRanges($this->employee, 'CATEGORY_A', $period);
    }

    public function test_an_affiliation_starting_mid_period_with_nothing_before_it_is_treated_as_a_gap_and_throws()
    {
        $period = $this->period('2025-02-01', '2025-02-15');
        // Nothing covers 2025-02-01 through 2025-02-09 — the plan does not
        // carve out a special "partial period is fine" case, so this is
        // treated identically to a gap strictly between two affiliations.
        $this->affiliation('CATEGORY_A', '2025-02-10', null);

        $this->expectException(NoActiveSocialSecurityAffiliationException::class);

        $this->service->callResolveAffiliationSubRanges($this->employee, 'CATEGORY_A', $period);
    }

    public function test_an_affiliation_ending_mid_period_with_nothing_after_it_is_treated_as_a_gap_and_throws()
    {
        $period = $this->period('2025-02-01', '2025-02-15');
        // Nothing covers 2025-02-06 through 2025-02-15 — a dangling end,
        // same treatment as any other gap.
        $this->affiliation('CATEGORY_A', '2025-01-01', '2025-02-05');

        $this->expectException(NoActiveSocialSecurityAffiliationException::class);

        $this->service->callResolveAffiliationSubRanges($this->employee, 'CATEGORY_A', $period);
    }

    public function test_resolve_daily_rate_uses_the_salary_history_revision_covering_the_sub_range_start_when_one_exists()
    {
        $contract = $this->contract('2024-01-01', null, 3000000);

        SalaryHistory::factory()->create([
            'company_id' => $this->company->id,
            'contract_id' => $contract->id,
            'effective_from' => '2025-03-01',
            'effective_to' => null,
            'base_salary' => 3100000,
        ]);

        $dailyRate = $this->service->callResolveDailyRate($contract, Carbon::parse('2025-03-15'));

        // 3,100,000 / 31 days in March = 100,000 exactly, using the
        // revision's base_salary rather than the contract's own 3,000,000.
        $this->assertEqualsWithDelta(100000.0, $dailyRate, 0.0001);
    }

    public function test_resolve_daily_rate_falls_back_to_the_contracts_own_base_salary_when_no_revision_covers_the_date()
    {
        $contract = $this->contract('2024-01-01', null, 3100000);

        $dailyRate = $this->service->callResolveDailyRate($contract, Carbon::parse('2025-03-15'));

        // 3,100,000 / 31 days in March = 100,000 exactly.
        $this->assertEqualsWithDelta(100000.0, $dailyRate, 0.0001);
    }

    public function test_resolve_daily_rate_uses_the_sub_ranges_own_start_month_not_a_hardcoded_thirty_or_thirty_one_days()
    {
        $februaryContract = $this->contract('2024-01-01', null, 3000000);
        $aprilContract = $this->contract('2024-01-01', null, 3000000);

        $februaryRate = $this->service->callResolveDailyRate($februaryContract, Carbon::parse('2025-02-01'));
        $aprilRate = $this->service->callResolveDailyRate($aprilContract, Carbon::parse('2025-04-01'));

        // Same monthly salary, different daysInMonth() for each sub-range's
        // own start date: February 2025 has 28 days, April has 30.
        $this->assertEqualsWithDelta(3000000 / 28, $februaryRate, 0.0001);
        $this->assertEqualsWithDelta(100000.0, $aprilRate, 0.0001);
        $this->assertNotEquals($februaryRate, $aprilRate);
    }

    public function test_prorated_base_salary_lines_for_a_split_contract_produces_two_lines_with_the_correct_money_math()
    {
        $period = $this->period('2025-03-01', '2025-03-15');
        // 3,100,000 / 31 days = 100,000/day, 8 days (Mar 1-8) = 800,000.
        $before = $this->contract('2025-01-01', '2025-03-08', 3100000);
        // 4,650,000 / 31 days = 150,000/day, 7 days (Mar 9-15) = 1,050,000.
        $after = $this->contract('2025-03-09', null, 4650000);

        $result = $this->service->callProratedBaseSalaryLines($this->employee, $period);

        $this->assertSame($after->id, $result['last_contract']->id);
        $this->assertCount(2, $result['lines']);

        $firstLine = $result['lines']->first();
        $this->assertSame($before->id, $firstLine['contract_id']);
        $this->assertEqualsWithDelta(8.0, $firstLine['quantity'], 0.0001);
        $this->assertEqualsWithDelta(100000.0, $firstLine['rate'], 0.0001);
        $this->assertEqualsWithDelta(800000.0, $firstLine['amount'], 0.0001);

        $secondLine = $result['lines']->last();
        $this->assertSame($after->id, $secondLine['contract_id']);
        $this->assertEqualsWithDelta(7.0, $secondLine['quantity'], 0.0001);
        $this->assertEqualsWithDelta(150000.0, $secondLine['rate'], 0.0001);
        $this->assertEqualsWithDelta(1050000.0, $secondLine['amount'], 0.0001);

        $totalAmount = $result['lines']->sum('amount');
        $this->assertEqualsWithDelta(1850000.0, $totalAmount, 0.0001);
    }

    public function test_an_attendance_record_within_the_period_satisfies_the_coverage_guard()
    {
        $period = $this->period('2026-02-01', '2026-02-15');

        AttendanceRecord::factory()->create([
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'date' => '2026-02-05',
        ]);

        $this->service->callAssertHasAttendanceOrNoveltyCoverage($this->employee, $period);

        $this->assertTrue(true);
    }

    public function test_an_approved_novelty_record_overlapping_the_period_satisfies_the_coverage_guard_with_zero_attendance_records()
    {
        $period = $this->period('2026-02-01', '2026-02-15');

        NoveltyRecord::factory()->create([
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'status' => 'approved',
            'date_from' => '2026-02-10',
            'date_to' => '2026-02-20',
        ]);

        $this->service->callAssertHasAttendanceOrNoveltyCoverage($this->employee, $period);

        $this->assertTrue(true);
    }

    public function test_a_pending_or_rejected_novelty_record_does_not_satisfy_the_coverage_guard()
    {
        $period = $this->period('2026-02-01', '2026-02-15');

        NoveltyRecord::factory()->create([
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'status' => 'pending',
            'date_from' => '2026-02-10',
            'date_to' => '2026-02-20',
        ]);

        $this->expectException(NoAttendanceOrNoveltyDataException::class);

        $this->service->callAssertHasAttendanceOrNoveltyCoverage($this->employee, $period);
    }

    public function test_neither_attendance_nor_approved_novelty_records_throws_no_attendance_or_novelty_data_exception()
    {
        $period = $this->period('2026-02-01', '2026-02-15');

        try {
            $this->service->callAssertHasAttendanceOrNoveltyCoverage($this->employee, $period);
            $this->fail('Expected NoAttendanceOrNoveltyDataException to be thrown.');
        } catch (NoAttendanceOrNoveltyDataException $exception) {
            $this->assertStringContainsString($this->employee->id, $exception->getMessage());
            $this->assertStringContainsString($period->id, $exception->getMessage());
        }
    }

    public function test_records_outside_the_periods_date_range_do_not_satisfy_the_coverage_guard()
    {
        $period = $this->period('2026-02-01', '2026-02-15');

        // Both records exist but fall entirely before the period starts.
        AttendanceRecord::factory()->create([
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'date' => '2026-01-20',
        ]);
        NoveltyRecord::factory()->create([
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'status' => 'approved',
            'date_from' => '2026-01-10',
            'date_to' => '2026-01-25',
        ]);

        $this->expectException(NoAttendanceOrNoveltyDataException::class);

        $this->service->callAssertHasAttendanceOrNoveltyCoverage($this->employee, $period);
    }

    /**
     * Same shape as TimeCalculationEngineTest's own ruleVersion() helper:
     * reuses (or creates) the company's single STANDARD_WORKWEEK LaborRule
     * and attaches a version with the given parameters.
     */
    private function laborRuleVersion(array $parameters, ?string $effectiveFrom = null): LaborRuleVersion
    {
        $laborRule = LaborRule::query()
            ->where('company_id', $this->company->id)
            ->where('rule_type', 'STANDARD_WORKWEEK')
            ->first() ?? LaborRule::factory()->create([
                'company_id' => $this->company->id,
                'rule_type' => 'STANDARD_WORKWEEK',
            ]);

        return LaborRuleVersion::factory()->create([
            'company_id' => $this->company->id,
            'labor_rule_id' => $laborRule->id,
            'effective_from' => $effectiveFrom ?? '2026-01-01',
            'effective_to' => null,
            'parameters' => $parameters,
        ]);
    }

    private function shift(string $date): Shift
    {
        return Shift::factory()->create([
            'company_id' => $this->company->id,
            'date' => $date,
        ]);
    }

    private function authorizedOvertimeRecord(Shift $shift, int $authorizedMinutes): OvertimeRecord
    {
        return OvertimeRecord::factory()->create([
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'shift_id' => $shift->id,
            'detected_minutes' => $authorizedMinutes,
            'authorized_minutes' => $authorizedMinutes,
            'status' => 'authorized',
        ]);
    }

    public function test_zero_authorized_overtime_records_returns_an_empty_collection_without_throwing()
    {
        $period = $this->period('2026-03-01', '2026-03-15');

        $lines = $this->service->callAuthorizedOvertimeLines($this->employee, $period);

        $this->assertCount(0, $lines);
    }

    public function test_one_authorized_overtime_record_produces_the_correct_hourly_rate_math()
    {
        $period = $this->period('2026-03-01', '2026-03-15');
        $this->contract('2025-01-01', null, 2400000);
        $this->laborRuleVersion(['monthly_hours_divisor' => 240, 'overtime_surcharge_pct' => 0.25]);
        $shift = $this->shift('2026-03-10');
        $this->authorizedOvertimeRecord($shift, 120);

        $lines = $this->service->callAuthorizedOvertimeLines($this->employee, $period);

        $this->assertCount(1, $lines);

        $line = $lines->first();
        $this->assertSame('OVERTIME', $line['concept_code']);
        // hourly_rate = 2,400,000 / 240 = 10,000.
        // overtime_rate = 10,000 * (1 + 0.25) = 12,500.
        // quantity = 120 authorized minutes / 60 = 2.0 hours.
        // amount = 12,500 * 2.0 = 25,000.
        $this->assertEqualsWithDelta(2.0, $line['quantity'], 0.0001);
        $this->assertEqualsWithDelta(12500.0, $line['rate'], 0.0001);
        $this->assertEqualsWithDelta(25000.0, $line['amount'], 0.0001);
    }

    public function test_missing_monthly_hours_divisor_parameter_throws()
    {
        $period = $this->period('2026-03-01', '2026-03-15');
        $this->contract('2025-01-01', null, 2400000);
        $this->laborRuleVersion(['overtime_surcharge_pct' => 0.25]);
        $shift = $this->shift('2026-03-10');
        $this->authorizedOvertimeRecord($shift, 120);

        $this->expectException(MissingLaborRuleParameterException::class);

        $this->service->callAuthorizedOvertimeLines($this->employee, $period);
    }

    public function test_missing_overtime_surcharge_pct_parameter_throws()
    {
        $period = $this->period('2026-03-01', '2026-03-15');
        $this->contract('2025-01-01', null, 2400000);
        $this->laborRuleVersion(['monthly_hours_divisor' => 240]);
        $shift = $this->shift('2026-03-10');
        $this->authorizedOvertimeRecord($shift, 120);

        $this->expectException(MissingLaborRuleParameterException::class);

        $this->service->callAuthorizedOvertimeLines($this->employee, $period);
    }

    public function test_no_active_labor_rule_version_for_the_shift_date_throws()
    {
        $period = $this->period('2026-03-01', '2026-03-15');
        $this->contract('2025-01-01', null, 2400000);
        // Deliberately no LaborRule/LaborRuleVersion created for the company.
        $shift = $this->shift('2026-03-10');
        $this->authorizedOvertimeRecord($shift, 120);

        $this->expectException(NoActiveLaborRuleVersionException::class);

        $this->service->callAuthorizedOvertimeLines($this->employee, $period);
    }

    public function test_overtime_records_not_in_authorized_status_are_excluded()
    {
        $period = $this->period('2026-03-01', '2026-03-15');
        $detectedShift = $this->shift('2026-03-05');
        $requestedShift = $this->shift('2026-03-06');
        $rejectedShift = $this->shift('2026-03-07');

        OvertimeRecord::factory()->create([
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'shift_id' => $detectedShift->id,
            'status' => 'detected',
        ]);
        OvertimeRecord::factory()->create([
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'shift_id' => $requestedShift->id,
            'requested_minutes' => 60,
            'status' => 'requested',
        ]);
        OvertimeRecord::factory()->create([
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'shift_id' => $rejectedShift->id,
            'status' => 'rejected',
        ]);

        $lines = $this->service->callAuthorizedOvertimeLines($this->employee, $period);

        $this->assertCount(0, $lines);
    }

    public function test_an_authorized_overtime_record_whose_shift_date_falls_outside_the_period_is_excluded()
    {
        $period = $this->period('2026-03-01', '2026-03-15');
        $shiftBeforePeriod = $this->shift('2026-02-20');

        $this->authorizedOvertimeRecord($shiftBeforePeriod, 120);

        $lines = $this->service->callAuthorizedOvertimeLines($this->employee, $period);

        $this->assertCount(0, $lines);
    }

    public function test_two_authorized_overtime_records_in_the_same_period_produce_two_separate_lines()
    {
        $period = $this->period('2026-03-01', '2026-03-15');
        $this->contract('2025-01-01', null, 2400000);
        $this->laborRuleVersion(['monthly_hours_divisor' => 240, 'overtime_surcharge_pct' => 0.25]);
        $firstShift = $this->shift('2026-03-10');
        $secondShift = $this->shift('2026-03-12');

        $this->authorizedOvertimeRecord($firstShift, 60);
        $this->authorizedOvertimeRecord($secondShift, 180);

        $lines = $this->service->callAuthorizedOvertimeLines($this->employee, $period);

        $this->assertCount(2, $lines);

        // hourly_rate = 2,400,000 / 240 = 10,000; overtime_rate = 12,500 for both.
        $firstLine = $lines->first();
        $this->assertEqualsWithDelta(1.0, $firstLine['quantity'], 0.0001);
        $this->assertEqualsWithDelta(12500.0, $firstLine['rate'], 0.0001);
        $this->assertEqualsWithDelta(12500.0, $firstLine['amount'], 0.0001);

        $secondLine = $lines->last();
        $this->assertEqualsWithDelta(3.0, $secondLine['quantity'], 0.0001);
        $this->assertEqualsWithDelta(12500.0, $secondLine['rate'], 0.0001);
        $this->assertEqualsWithDelta(37500.0, $secondLine['amount'], 0.0001);
    }

    public function test_an_employee_with_zero_deduction_plans_produces_an_empty_collection()
    {
        $lines = $this->service->callFixedDeductionLines($this->employee);

        $this->assertCount(0, $lines);
    }

    public function test_an_employee_with_one_active_plan_produces_one_line_with_the_full_installment_amount()
    {
        $plan = PayrollDeductionPlan::factory()->create([
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'total_amount' => 600000,
            'installments' => 6,
            'installment_amount' => 100000,
            'remaining' => 400000,
        ]);

        $lines = $this->service->callFixedDeductionLines($this->employee);

        $this->assertCount(1, $lines);

        $line = $lines->first();
        $this->assertSame($plan->concept_id, $line['concept_id']);
        $this->assertSame($plan->id, $line['plan_id']);
        $this->assertEqualsWithDelta(100000.0, $line['amount'], 0.0001);
    }

    public function test_a_plan_with_remaining_less_than_the_installment_amount_is_capped_at_remaining()
    {
        $plan = PayrollDeductionPlan::factory()->create([
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'total_amount' => 600000,
            'installments' => 6,
            'installment_amount' => 100000,
            // Nearly paid off: less left than a full installment.
            'remaining' => 35000,
        ]);

        $lines = $this->service->callFixedDeductionLines($this->employee);

        $this->assertCount(1, $lines);

        $line = $lines->first();
        $this->assertSame($plan->id, $line['plan_id']);
        $this->assertEqualsWithDelta(35000.0, $line['amount'], 0.0001);
    }

    public function test_a_fully_paid_off_plan_is_excluded_entirely()
    {
        PayrollDeductionPlan::factory()->create([
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'total_amount' => 600000,
            'installments' => 6,
            'installment_amount' => 100000,
            'remaining' => 0,
        ]);

        $lines = $this->service->callFixedDeductionLines($this->employee);

        $this->assertCount(0, $lines);
    }

    public function test_two_active_plans_produce_two_separate_lines_with_the_correct_data()
    {
        $firstPlan = PayrollDeductionPlan::factory()->create([
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'total_amount' => 600000,
            'installments' => 6,
            'installment_amount' => 100000,
            'remaining' => 400000,
        ]);

        $secondPlan = PayrollDeductionPlan::factory()->create([
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'total_amount' => 300000,
            'installments' => 3,
            'installment_amount' => 100000,
            'remaining' => 50000,
        ]);

        $lines = $this->service->callFixedDeductionLines($this->employee);

        $this->assertCount(2, $lines);

        $firstLine = $lines->firstWhere('plan_id', $firstPlan->id);
        $this->assertNotNull($firstLine);
        $this->assertSame($firstPlan->concept_id, $firstLine['concept_id']);
        $this->assertEqualsWithDelta(100000.0, $firstLine['amount'], 0.0001);

        $secondLine = $lines->firstWhere('plan_id', $secondPlan->id);
        $this->assertNotNull($secondLine);
        $this->assertSame($secondPlan->concept_id, $secondLine['concept_id']);
        $this->assertEqualsWithDelta(50000.0, $secondLine['amount'], 0.0001);
    }

    public function test_calling_fixed_deduction_lines_does_not_mutate_the_plans_remaining_balance()
    {
        $plan = PayrollDeductionPlan::factory()->create([
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'total_amount' => 600000,
            'installments' => 6,
            'installment_amount' => 100000,
            'remaining' => 400000,
        ]);

        $this->service->callFixedDeductionLines($this->employee);

        $this->assertEqualsWithDelta(400000.0, $plan->fresh()->remaining, 0.0001);
    }

    private function baseSalaryConceptId(): string
    {
        return PayrollConceptDefinition::query()->withoutGlobalScope('company')->whereNull('company_id')->where('code', 'BASE_SALARY')->firstOrFail()->id;
    }

    private function overtimeConceptId(): string
    {
        return PayrollConceptDefinition::query()->withoutGlobalScope('company')->whereNull('company_id')->where('code', 'OVERTIME')->firstOrFail()->id;
    }

    public function test_calculate_for_employee_produces_a_calculated_entry_with_a_single_base_salary_line_for_the_happy_path()
    {
        $this->seed(PayrollConceptCatalogSeeder::class);

        // Full calendar month: rate = 3,100,000 / 30 days, amount over 30
        // days of coverage collapses back to exactly the monthly salary.
        $period = $this->period('2026-04-01', '2026-04-30');
        $contract = $this->contract('2025-01-01', null, 3100000);

        AttendanceRecord::factory()->create([
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'date' => '2026-04-05',
        ]);

        $entry = $this->service->calculateForEmployee($period, $this->employee);

        $this->assertSame('calculated', $entry->status);
        $this->assertSame($contract->id, $entry->contract_id);
        $this->assertEqualsWithDelta(3100000.0, (float) $entry->gross_total, 0.01);
        $this->assertEqualsWithDelta(0.0, (float) $entry->deductions_total, 0.0001);
        $this->assertEqualsWithDelta(3100000.0, (float) $entry->net_total, 0.01);

        $lines = $entry->lines()->get();
        $this->assertCount(1, $lines);
        $this->assertSame('earning', $lines->first()->type);
        $this->assertSame($this->baseSalaryConceptId(), $lines->first()->concept_id);
        $this->assertSame($contract->id, $lines->first()->contract_id);
    }

    public function test_calculate_for_employee_with_authorized_overtime_includes_both_earning_lines_in_gross_total()
    {
        $this->seed(PayrollConceptCatalogSeeder::class);

        $period = $this->period('2026-04-01', '2026-04-30');
        $this->contract('2025-01-01', null, 2400000);
        $this->laborRuleVersion(['monthly_hours_divisor' => 240, 'overtime_surcharge_pct' => 0.25]);

        AttendanceRecord::factory()->create([
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'date' => '2026-04-05',
        ]);

        $shift = $this->shift('2026-04-10');
        $this->authorizedOvertimeRecord($shift, 120);

        $entry = $this->service->calculateForEmployee($period, $this->employee);

        // Base salary: 2,400,000 over the full month = 2,400,000 exactly.
        // Overtime: hourly_rate 10,000 * 1.25 * 2h = 25,000.
        $this->assertEqualsWithDelta(2425000.0, (float) $entry->gross_total, 0.01);
        $this->assertEqualsWithDelta(2425000.0, (float) $entry->net_total, 0.01);

        $lines = $entry->lines()->get();
        $this->assertCount(2, $lines);
        $this->assertTrue($lines->every(fn (PayrollEntryLine $line): bool => $line->type === 'earning'));

        $overtimeLine = $lines->firstWhere('concept_id', $this->overtimeConceptId());
        $this->assertNotNull($overtimeLine);
        $this->assertEqualsWithDelta(25000.0, (float) $overtimeLine->amount, 0.01);
    }

    public function test_calculate_for_employee_with_a_fixed_deduction_plan_computes_a_correct_net_total()
    {
        $this->seed(PayrollConceptCatalogSeeder::class);

        $period = $this->period('2026-04-01', '2026-04-30');
        $this->contract('2025-01-01', null, 3100000);

        AttendanceRecord::factory()->create([
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'date' => '2026-04-05',
        ]);

        PayrollDeductionPlan::factory()->create([
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'total_amount' => 600000,
            'installments' => 6,
            'installment_amount' => 100000,
            'remaining' => 400000,
        ]);

        $entry = $this->service->calculateForEmployee($period, $this->employee);

        // gross = 3,100,000 (full-month base salary); deduction = min(100000, 400000) = 100,000.
        $this->assertEqualsWithDelta(3100000.0, (float) $entry->gross_total, 0.01);
        $this->assertEqualsWithDelta(100000.0, (float) $entry->deductions_total, 0.01);
        $this->assertEqualsWithDelta(3000000.0, (float) $entry->net_total, 0.01);

        $deductionLines = $entry->lines()->where('type', 'deduction')->get();
        $this->assertCount(1, $deductionLines);
        $this->assertEqualsWithDelta(100000.0, (float) $deductionLines->first()->amount, 0.01);
    }

    public function test_calculate_for_employee_persists_a_blocked_entry_and_rethrows_when_there_is_no_attendance_or_novelty_coverage()
    {
        $this->seed(PayrollConceptCatalogSeeder::class);

        $period = $this->period('2026-04-01', '2026-04-30');
        // Deliberately no AttendanceRecord/NoveltyRecord and no contract —
        // the coverage guard fires first and blocks before either would
        // even be needed.

        try {
            $this->service->calculateForEmployee($period, $this->employee);
            $this->fail('Expected NoAttendanceOrNoveltyDataException to be thrown.');
        } catch (NoAttendanceOrNoveltyDataException $exception) {
            // expected
        }

        $entry = PayrollEntry::query()
            ->where('payroll_period_id', $period->id)
            ->where('employee_id', $this->employee->id)
            ->first();

        $this->assertNotNull($entry);
        $this->assertSame('blocked', $entry->status);
        $this->assertNull($entry->contract_id);
        $this->assertEqualsWithDelta(0.0, (float) $entry->gross_total, 0.0001);
        $this->assertEqualsWithDelta(0.0, (float) $entry->deductions_total, 0.0001);
        $this->assertEqualsWithDelta(0.0, (float) $entry->net_total, 0.0001);
        $this->assertCount(0, $entry->lines()->get());
    }

    public function test_calculate_for_period_processes_every_employee_and_never_aborts_the_batch_on_a_blocked_one()
    {
        $this->seed(PayrollConceptCatalogSeeder::class);

        $period = $this->period('2026-04-01', '2026-04-30');

        // Employee A (setUp's $this->employee): fully coverable, ends up ok.
        $this->contract('2025-01-01', null, 3100000);
        AttendanceRecord::factory()->create([
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'date' => '2026-04-05',
        ]);

        // Employee B: same company, zero attendance/novelty data, blocked.
        $blockedEmployee = Employee::factory()->create(['company_id' => $this->company->id]);

        $results = $this->service->calculateForPeriod($period);

        $this->assertCount(2, $results);

        $okResult = $results->firstWhere('employee_id', $this->employee->id);
        $this->assertNotNull($okResult);
        $this->assertSame('ok', $okResult['status']);
        $this->assertNotNull($okResult['entry']);
        $this->assertNull($okResult['error']);

        $blockedResult = $results->firstWhere('employee_id', $blockedEmployee->id);
        $this->assertNotNull($blockedResult);
        $this->assertSame('blocked', $blockedResult['status']);
        $this->assertNotNull($blockedResult['entry']);
        $this->assertNotNull($blockedResult['error']);

        $this->assertSame('calculated', PayrollEntry::query()
            ->where('payroll_period_id', $period->id)
            ->where('employee_id', $this->employee->id)
            ->firstOrFail()->status);

        $this->assertSame('blocked', PayrollEntry::query()
            ->where('payroll_period_id', $period->id)
            ->where('employee_id', $blockedEmployee->id)
            ->firstOrFail()->status);
    }

    public function test_recalculating_the_same_employee_and_period_updates_the_single_entry_and_replaces_its_lines_without_duplicating_them()
    {
        $this->seed(PayrollConceptCatalogSeeder::class);

        $period = $this->period('2026-04-01', '2026-04-30');
        $this->contract('2025-01-01', null, 2400000);
        AttendanceRecord::factory()->create([
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'date' => '2026-04-05',
        ]);

        $firstEntry = $this->service->calculateForEmployee($period, $this->employee);
        $this->assertEqualsWithDelta(2400000.0, (float) $firstEntry->gross_total, 0.01);
        $this->assertCount(1, $firstEntry->lines()->get());

        // Underlying data changes before the second recalculation: overtime
        // gets authorized.
        $this->laborRuleVersion(['monthly_hours_divisor' => 240, 'overtime_surcharge_pct' => 0.25]);
        $shift = $this->shift('2026-04-10');
        $this->authorizedOvertimeRecord($shift, 120);

        $secondEntry = $this->service->calculateForEmployee($period, $this->employee);

        $this->assertSame($firstEntry->id, $secondEntry->id);
        $this->assertEqualsWithDelta(2425000.0, (float) $secondEntry->gross_total, 0.01);

        $this->assertSame(1, PayrollEntry::query()
            ->where('payroll_period_id', $period->id)
            ->where('employee_id', $this->employee->id)
            ->count());

        // Exactly 2 lines (base salary + overtime), never 3 or 4 — the old
        // set was fully replaced, not appended to.
        $this->assertCount(2, $secondEntry->lines()->get());
    }

    public function test_recalculating_a_previously_blocked_employee_after_coverage_is_added_transitions_it_to_calculated()
    {
        $this->seed(PayrollConceptCatalogSeeder::class);

        $period = $this->period('2026-04-01', '2026-04-30');
        $contract = $this->contract('2025-01-01', null, 3100000);

        try {
            $this->service->calculateForEmployee($period, $this->employee);
            $this->fail('Expected NoAttendanceOrNoveltyDataException to be thrown.');
        } catch (NoAttendanceOrNoveltyDataException $exception) {
            // expected
        }

        $blockedEntry = PayrollEntry::query()
            ->where('payroll_period_id', $period->id)
            ->where('employee_id', $this->employee->id)
            ->firstOrFail();
        $this->assertSame('blocked', $blockedEntry->status);
        $this->assertNull($blockedEntry->contract_id);

        AttendanceRecord::factory()->create([
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'date' => '2026-04-05',
        ]);

        $recalculatedEntry = $this->service->calculateForEmployee($period, $this->employee);

        $this->assertSame($blockedEntry->id, $recalculatedEntry->id);
        $this->assertSame('calculated', $recalculatedEntry->status);
        $this->assertSame($contract->id, $recalculatedEntry->contract_id);
        $this->assertEqualsWithDelta(3100000.0, (float) $recalculatedEntry->gross_total, 0.01);

        $this->assertSame(1, PayrollEntry::query()
            ->where('payroll_period_id', $period->id)
            ->where('employee_id', $this->employee->id)
            ->count());
    }

    public function test_platform_default_base_salary_and_overtime_concepts_resolve_correctly_even_with_an_active_tenant_scope()
    {
        $this->seed(PayrollConceptCatalogSeeder::class);

        // Fase 8's LeaveRecordService regression: BelongsToCompany's global
        // scope excludes company_id IS NULL rows once a tenant is active,
        // silently making platform-default catalog rows unreachable through
        // a bare ->where('code', ...) query. Activating CurrentCompany here
        // — never done implicitly by RefreshDatabase alone — is what would
        // expose that exact regression if PayrollCalculationService ever
        // stopped using PayrollConceptDefinition::effectiveForCompany().
        app(CurrentCompany::class)->set($this->company);

        $period = $this->period('2026-04-01', '2026-04-30');
        $this->contract('2025-01-01', null, 2400000);
        $this->laborRuleVersion(['monthly_hours_divisor' => 240, 'overtime_surcharge_pct' => 0.25]);

        AttendanceRecord::factory()->create([
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'date' => '2026-04-05',
        ]);
        $shift = $this->shift('2026-04-10');
        $this->authorizedOvertimeRecord($shift, 120);

        $entry = $this->service->calculateForEmployee($period, $this->employee);

        $lines = $entry->lines()->get();
        $this->assertCount(2, $lines);
        $this->assertTrue($lines->contains('concept_id', $this->baseSalaryConceptId()));
        $this->assertTrue($lines->contains('concept_id', $this->overtimeConceptId()));
    }

    public function test_calculate_for_employee_against_a_closed_period_throws_immediately_without_modifying_the_existing_entry_or_its_lines()
    {
        $this->seed(PayrollConceptCatalogSeeder::class);

        $period = $this->period('2026-04-01', '2026-04-30');
        $this->contract('2025-01-01', null, 3100000);
        AttendanceRecord::factory()->create([
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'date' => '2026-04-05',
        ]);

        $entry = $this->service->calculateForEmployee($period, $this->employee);
        $entryBefore = $entry->fresh()->toArray();
        $linesBefore = $entry->lines()->orderBy('id')->get()->toArray();

        $period->update(['status' => 'closed']);

        try {
            $this->service->calculateForEmployee($period->fresh(), $this->employee);
            $this->fail('Expected InvalidPayrollPeriodStatusException to be thrown.');
        } catch (InvalidPayrollPeriodStatusException $exception) {
            $this->assertStringContainsString($period->id, $exception->getMessage());
        }

        // Byte-for-byte unchanged: the guard fired before the destructive
        // updateOrCreate()/lines()->delete() step ever ran.
        $this->assertSame($entryBefore, $entry->fresh()->toArray());
        $this->assertSame($linesBefore, $entry->lines()->orderBy('id')->get()->toArray());
    }

    public function test_calculate_for_employee_still_works_normally_for_every_non_closed_period_status()
    {
        $this->seed(PayrollConceptCatalogSeeder::class);

        foreach (['open', 'calculated', 'approved', 'reopened'] as $status) {
            $employee = Employee::factory()->create(['company_id' => $this->company->id]);
            $period = $this->period('2026-05-01', '2026-05-31');
            $period->update(['status' => $status]);

            EmploymentContract::factory()->create([
                'company_id' => $this->company->id,
                'employee_id' => $employee->id,
                'start_date' => '2025-01-01',
                'end_date' => null,
                'base_salary' => 3000000,
            ]);
            AttendanceRecord::factory()->create([
                'company_id' => $this->company->id,
                'employee_id' => $employee->id,
                'date' => '2026-05-05',
            ]);

            $entry = $this->service->calculateForEmployee($period->fresh(), $employee);

            $this->assertSame('calculated', $entry->status, "Failed for period status '{$status}'.");
        }
    }

    public function test_calculate_for_period_against_a_closed_period_propagates_immediately_without_persisting_any_entries()
    {
        $this->seed(PayrollConceptCatalogSeeder::class);

        $period = $this->period('2026-04-01', '2026-04-30');
        $period->update(['status' => 'closed']);

        $this->contract('2025-01-01', null, 3100000);
        AttendanceRecord::factory()->create([
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'date' => '2026-04-05',
        ]);

        $secondEmployee = Employee::factory()->create(['company_id' => $this->company->id]);
        EmploymentContract::factory()->create([
            'company_id' => $this->company->id,
            'employee_id' => $secondEmployee->id,
            'start_date' => '2025-01-01',
            'end_date' => null,
            'base_salary' => 3000000,
        ]);
        AttendanceRecord::factory()->create([
            'company_id' => $this->company->id,
            'employee_id' => $secondEmployee->id,
            'date' => '2026-04-05',
        ]);

        // Confirms this propagates out of calculateForPeriod() on the very
        // first employee it hits, rather than being swallowed into the
        // per-employee 'blocked' catch: a closed period is a single global
        // precondition violation affecting the whole batch identically, not
        // per-employee bad data, so NEITHER employee gets a persisted
        // PayrollEntry — not even a 'blocked' one — which is what would
        // exist if this were caught-and-collected instead.
        try {
            $this->service->calculateForPeriod($period->fresh());
            $this->fail('Expected InvalidPayrollPeriodStatusException to be thrown.');
        } catch (InvalidPayrollPeriodStatusException $exception) {
            // expected
        }

        $this->assertSame(0, PayrollEntry::query()->where('payroll_period_id', $period->id)->count());
    }

    public function test_zero_social_security_concepts_configured_produces_an_empty_collection_without_throwing()
    {
        $this->seed(PayrollConceptCatalogSeeder::class);

        $period = $this->period('2025-01-01', '2025-01-15');
        // Deliberately zero SocialSecurityConceptDefinition rows — a company
        // that has not configured social security at all yet.
        $earningLines = collect([
            ['concept_id' => $this->baseSalaryConceptId(), 'amount' => 1000000.0],
        ]);

        $lines = $this->service->callSocialSecurityContributionLines($this->employee, $period, $earningLines);

        $this->assertCount(0, $lines);
    }

    public function test_one_concept_with_one_affiliation_spanning_the_whole_period_produces_a_correctly_computed_line()
    {
        $this->seed(PayrollConceptCatalogSeeder::class);

        $period = $this->period('2025-01-01', '2025-01-15');
        $concept = $this->socialSecurityConcept('CATEGORY_A');
        $affiliation = $this->affiliation('CATEGORY_A', '2024-06-01', null);
        $this->socialSecurityRuleVersion($concept, [
            'employee_pct' => 0.10,
            'employer_pct' => 0.20,
            'base_concept_codes' => ['BASE_SALARY'],
        ]);

        $earningLines = collect([
            ['concept_id' => $this->baseSalaryConceptId(), 'amount' => 1000000.0],
        ]);

        $lines = $this->service->callSocialSecurityContributionLines($this->employee, $period, $earningLines);

        $this->assertCount(1, $lines);

        $line = $lines->first();
        $this->assertSame($concept->id, $line['concept']->id);
        $this->assertSame($affiliation->entity_id, $line['entity']->id);
        $this->assertTrue($line['period_from']->equalTo(Carbon::parse('2025-01-01')));
        $this->assertTrue($line['period_to']->equalTo(Carbon::parse('2025-01-15')));
        $this->assertEqualsWithDelta(1000000.0, $line['base_amount'], 0.01);
        $this->assertEqualsWithDelta(100000.0, $line['employee_amount'], 0.01);
        $this->assertEqualsWithDelta(200000.0, $line['employer_amount'], 0.01);
    }

    public function test_two_affiliation_sub_ranges_mid_period_produce_two_lines_correctly_prorated_and_attributed_to_each_entity()
    {
        $this->seed(PayrollConceptCatalogSeeder::class);

        $period = $this->period('2025-01-01', '2025-01-15');
        $concept = $this->socialSecurityConcept('CATEGORY_A');
        // 7 days (Jan 1-7) then 8 days (Jan 8-15) — mid-period entity change.
        $before = $this->affiliation('CATEGORY_A', '2024-06-01', '2025-01-07');
        $after = $this->affiliation('CATEGORY_A', '2025-01-08', null);
        $this->socialSecurityRuleVersion($concept, [
            'employee_pct' => 0.10,
            'employer_pct' => 0.20,
            'base_concept_codes' => ['BASE_SALARY'],
        ]);

        $earningLines = collect([
            ['concept_id' => $this->baseSalaryConceptId(), 'amount' => 1000000.0],
        ]);

        $lines = $this->service->callSocialSecurityContributionLines($this->employee, $period, $earningLines);

        $this->assertCount(2, $lines);

        $firstLine = $lines->first();
        $this->assertSame($before->entity_id, $firstLine['entity']->id);
        // 1,000,000 * 7/15 = 466,666.67.
        $this->assertEqualsWithDelta(466666.67, $firstLine['base_amount'], 0.01);
        $this->assertEqualsWithDelta(46666.67, $firstLine['employee_amount'], 0.01);
        $this->assertEqualsWithDelta(93333.33, $firstLine['employer_amount'], 0.01);

        $secondLine = $lines->last();
        $this->assertSame($after->entity_id, $secondLine['entity']->id);
        // 1,000,000 * 8/15 = 533,333.33.
        $this->assertEqualsWithDelta(533333.33, $secondLine['base_amount'], 0.01);
        $this->assertEqualsWithDelta(53333.33, $secondLine['employee_amount'], 0.01);
        $this->assertEqualsWithDelta(106666.67, $secondLine['employer_amount'], 0.01);

        // The two sub-ranges' base_amount reconstructs the full period base.
        $this->assertEqualsWithDelta(1000000.0, $lines->sum('base_amount'), 0.01);
    }

    public function test_a_concept_with_no_affiliation_at_all_is_skipped_while_other_properly_affiliated_concepts_still_produce_lines()
    {
        $this->seed(PayrollConceptCatalogSeeder::class);

        $period = $this->period('2025-01-01', '2025-01-15');

        // Configured and affiliated — should produce a line.
        $affiliatedConcept = $this->socialSecurityConcept('CATEGORY_A');
        $this->affiliation('CATEGORY_A', '2024-06-01', null);
        $this->socialSecurityRuleVersion($affiliatedConcept, [
            'employee_pct' => 0.10,
            'employer_pct' => 0.20,
            'base_concept_codes' => ['BASE_SALARY'],
        ]);

        // Configured but never affiliated — no SocialSecurityAffiliation of
        // 'CATEGORY_B' exists at all for this employee.
        $unaffiliatedConcept = $this->socialSecurityConcept('CATEGORY_B');
        $this->socialSecurityRuleVersion($unaffiliatedConcept, [
            'employee_pct' => 0.10,
            'employer_pct' => 0.20,
            'base_concept_codes' => ['BASE_SALARY'],
        ]);

        $earningLines = collect([
            ['concept_id' => $this->baseSalaryConceptId(), 'amount' => 1000000.0],
        ]);

        $lines = $this->service->callSocialSecurityContributionLines($this->employee, $period, $earningLines);

        $this->assertCount(1, $lines);
        $this->assertSame($affiliatedConcept->id, $lines->first()['concept']->id);
    }

    public function test_a_concept_with_an_affiliation_but_no_labor_rule_version_covering_the_date_throws()
    {
        $this->seed(PayrollConceptCatalogSeeder::class);

        $period = $this->period('2025-01-01', '2025-01-15');
        $concept = $this->socialSecurityConcept('CATEGORY_A');
        $this->affiliation('CATEGORY_A', '2024-06-01', null);
        // The LaborRule row exists (rate configuration was started) but no
        // LaborRuleVersion was ever added — a genuine blocking configuration
        // gap, distinct from the "no LaborRule row at all" skip case.
        $this->socialSecurityLaborRule($concept);

        $earningLines = collect([
            ['concept_id' => $this->baseSalaryConceptId(), 'amount' => 1000000.0],
        ]);

        $this->expectException(NoActiveLaborRuleVersionException::class);

        $this->service->callSocialSecurityContributionLines($this->employee, $period, $earningLines);
    }

    public function test_a_rule_version_missing_base_concept_codes_throws_missing_labor_rule_parameter_exception()
    {
        $this->seed(PayrollConceptCatalogSeeder::class);

        $period = $this->period('2025-01-01', '2025-01-15');
        $concept = $this->socialSecurityConcept('CATEGORY_A');
        $this->affiliation('CATEGORY_A', '2024-06-01', null);
        $this->socialSecurityRuleVersion($concept, [
            'employee_pct' => 0.10,
            'employer_pct' => 0.20,
            // base_concept_codes deliberately missing.
        ]);

        $earningLines = collect([
            ['concept_id' => $this->baseSalaryConceptId(), 'amount' => 1000000.0],
        ]);

        $this->expectException(MissingLaborRuleParameterException::class);

        $this->service->callSocialSecurityContributionLines($this->employee, $period, $earningLines);
    }

    public function test_a_base_concept_code_with_zero_matching_earning_lines_degrades_to_a_zero_amount_line_without_throwing()
    {
        $this->seed(PayrollConceptCatalogSeeder::class);

        $period = $this->period('2025-01-01', '2025-01-15');
        $concept = $this->socialSecurityConcept('CATEGORY_A');
        $this->affiliation('CATEGORY_A', '2024-06-01', null);
        // Base concept is OVERTIME, but the employee earned zero overtime
        // this period — $earningLines below carries no OVERTIME entry.
        $this->socialSecurityRuleVersion($concept, [
            'employee_pct' => 0.10,
            'employer_pct' => 0.20,
            'base_concept_codes' => ['OVERTIME'],
        ]);

        $earningLines = collect([
            ['concept_id' => $this->baseSalaryConceptId(), 'amount' => 1000000.0],
        ]);

        $lines = $this->service->callSocialSecurityContributionLines($this->employee, $period, $earningLines);

        $this->assertCount(1, $lines);

        $line = $lines->first();
        $this->assertEqualsWithDelta(0.0, $line['base_amount'], 0.0001);
        $this->assertEqualsWithDelta(0.0, $line['employee_amount'], 0.0001);
        $this->assertEqualsWithDelta(0.0, $line['employer_amount'], 0.0001);
    }
}
