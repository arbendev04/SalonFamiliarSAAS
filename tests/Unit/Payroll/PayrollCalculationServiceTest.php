<?php

namespace Tests\Unit\Payroll;

use App\Exceptions\AmbiguousContractException;
use App\Exceptions\MissingLaborRuleParameterException;
use App\Exceptions\NoActiveLaborRuleVersionException;
use App\Exceptions\NoAttendanceOrNoveltyDataException;
use App\Models\AttendanceRecord;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmploymentContract;
use App\Models\LaborRule;
use App\Models\LaborRuleVersion;
use App\Models\NoveltyRecord;
use App\Models\OvertimeRecord;
use App\Models\PayrollPeriod;
use App\Models\SalaryHistory;
use App\Models\Shift;
use App\Services\Payroll\PayrollCalculationService;
use Carbon\CarbonInterface;
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
}
