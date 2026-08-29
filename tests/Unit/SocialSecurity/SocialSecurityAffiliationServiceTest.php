<?php

namespace Tests\Unit\SocialSecurity;

use App\Exceptions\NoActiveSocialSecurityAffiliationException;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Employee;
use App\Models\SocialSecurityAffiliation;
use App\Models\SocialSecurityEntity;
use App\Models\User;
use App\Services\SocialSecurity\SocialSecurityAffiliationService;
use App\Services\Tenancy\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * No SocialSecurityAffiliationController exists yet (deferred to a later
 * commit of composed-knitting-dusk.md), so — same convention already used by
 * tests/Unit/LeaveRecordServiceTest.php and
 * tests/Unit/SocialSecurity/StoreSocialSecurityAffiliationRequestTest.php —
 * this exercises the service directly. CurrentCompany is set manually (no
 * SetCurrentCompany middleware to run it for us).
 *
 * The overlap validation performed by StoreSocialSecurityAffiliationRequest
 * is not re-checked here: same division of responsibility as
 * LeaveRecordService/OvertimeRecordService, which trust their own
 * FormRequest completely and never re-validate what it already checked.
 */
class SocialSecurityAffiliationServiceTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Employee $employee;

    private User $createdBy;

    private SocialSecurityAffiliationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
        app(CurrentCompany::class)->set($this->company);

        $this->employee = Employee::factory()->create(['company_id' => $this->company->id]);
        $this->createdBy = User::factory()->create();
        $this->service = app(SocialSecurityAffiliationService::class);
    }

    private function entityOfType(string $type): SocialSecurityEntity
    {
        return SocialSecurityEntity::factory()->create([
            'company_id' => $this->company->id,
            'type' => $type,
        ]);
    }

    public function test_affiliate_creates_an_open_ended_affiliation_with_the_entity_type_derived_from_the_entity_and_logs_one_audit_entry()
    {
        $entity = $this->entityOfType('CATEGORY_A');

        $affiliation = $this->service->affiliate(
            $this->employee,
            $entity,
            Carbon::parse('2025-01-01'),
            'AFF-0001',
            $this->createdBy,
        );

        $this->assertInstanceOf(SocialSecurityAffiliation::class, $affiliation);
        $this->assertSame($this->employee->id, $affiliation->employee_id);
        $this->assertSame($entity->id, $affiliation->entity_id);
        $this->assertSame('CATEGORY_A', $affiliation->entity_type);
        $this->assertSame('AFF-0001', $affiliation->affiliation_number);
        $this->assertSame('2025-01-01', $affiliation->start_date->toDateString());
        $this->assertNull($affiliation->end_date);

        $this->assertSame(1, AuditLog::query()->where('entity_type', 'social_security_affiliations')->count());

        $log = AuditLog::query()->where('entity_type', 'social_security_affiliations')->firstOrFail();
        $this->assertSame($this->createdBy->id, $log->user_id);
        $this->assertSame('social_security_affiliation.created', $log->action);
        $this->assertSame($affiliation->id, $log->entity_id);
    }

    public function test_affiliating_a_second_entity_type_does_not_touch_the_first()
    {
        $healthLikeEntity = $this->entityOfType('CATEGORY_A');
        $pensionLikeEntity = $this->entityOfType('CATEGORY_B');

        $first = $this->service->affiliate(
            $this->employee,
            $healthLikeEntity,
            Carbon::parse('2025-01-01'),
            null,
            $this->createdBy,
        );

        $second = $this->service->affiliate(
            $this->employee,
            $pensionLikeEntity,
            Carbon::parse('2025-01-01'),
            null,
            $this->createdBy,
        );

        $first->refresh();

        $this->assertSame('CATEGORY_A', $first->entity_type);
        $this->assertNull($first->end_date);
        $this->assertSame('CATEGORY_B', $second->entity_type);
        $this->assertNull($second->end_date);
        $this->assertSame(2, SocialSecurityAffiliation::query()->count());
    }

    public function test_reassign_closes_the_previously_active_affiliation_the_day_before_the_new_start_date_and_opens_a_new_one()
    {
        $oldEntity = $this->entityOfType('CATEGORY_A');

        $original = $this->service->affiliate(
            $this->employee,
            $oldEntity,
            Carbon::parse('2025-01-01'),
            'AFF-OLD',
            $this->createdBy,
        );

        $newEntity = $this->entityOfType('CATEGORY_A');

        $reassigned = $this->service->reassign(
            $this->employee,
            $newEntity,
            Carbon::parse('2025-06-01'),
            'AFF-NEW',
            $this->createdBy,
        );

        $original->refresh();

        $this->assertSame('2025-05-31', $original->end_date->toDateString());
        $this->assertSame($newEntity->id, $reassigned->entity_id);
        $this->assertSame('CATEGORY_A', $reassigned->entity_type);
        $this->assertSame('AFF-NEW', $reassigned->affiliation_number);
        $this->assertSame('2025-06-01', $reassigned->start_date->toDateString());
        $this->assertNull($reassigned->end_date);
        $this->assertNotSame($original->id, $reassigned->id);

        $this->assertSame(2, AuditLog::query()->where('entity_type', 'social_security_affiliations')->count());

        $reassignLog = AuditLog::query()
            ->where('entity_type', 'social_security_affiliations')
            ->where('action', 'social_security_affiliation.reassigned')
            ->firstOrFail();

        $this->assertSame($reassigned->id, $reassignLog->entity_id);
    }

    public function test_reassign_throws_when_no_active_affiliation_exists_for_the_new_entitys_type()
    {
        $newEntity = $this->entityOfType('CATEGORY_A');

        $this->expectException(NoActiveSocialSecurityAffiliationException::class);

        $this->service->reassign(
            $this->employee,
            $newEntity,
            Carbon::parse('2025-06-01'),
            null,
            $this->createdBy,
        );
    }

    public function test_reassign_throws_when_the_effective_date_is_not_after_the_current_affiliations_start_date()
    {
        $oldEntity = $this->entityOfType('CATEGORY_A');

        $this->service->affiliate(
            $this->employee,
            $oldEntity,
            Carbon::parse('2025-06-01'),
            null,
            $this->createdBy,
        );

        $newEntity = $this->entityOfType('CATEGORY_A');

        $this->expectException(InvalidArgumentException::class);

        $this->service->reassign(
            $this->employee,
            $newEntity,
            Carbon::parse('2025-06-01'),
            null,
            $this->createdBy,
        );
    }

    public function test_active_for_returns_the_matching_affiliation_when_exactly_one_is_in_force()
    {
        $entity = $this->entityOfType('CATEGORY_A');

        $affiliation = SocialSecurityAffiliation::factory()->create([
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'entity_id' => $entity->id,
            'entity_type' => 'CATEGORY_A',
            'start_date' => '2025-01-01',
            'end_date' => null,
        ]);

        $found = SocialSecurityAffiliation::activeFor($this->employee->id, 'CATEGORY_A', Carbon::parse('2025-03-01'));

        $this->assertNotNull($found);
        $this->assertSame($affiliation->id, $found->id);
    }

    public function test_active_for_returns_null_when_none_are_in_force()
    {
        $found = SocialSecurityAffiliation::activeFor($this->employee->id, 'CATEGORY_A', Carbon::parse('2025-03-01'));

        $this->assertNull($found);
    }

    public function test_active_for_throws_when_more_than_one_overlaps_for_the_same_employee_and_entity_type()
    {
        $entityOne = $this->entityOfType('CATEGORY_A');
        $entityTwo = $this->entityOfType('CATEGORY_A');

        SocialSecurityAffiliation::factory()->create([
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'entity_id' => $entityOne->id,
            'entity_type' => 'CATEGORY_A',
            'start_date' => '2025-01-01',
            'end_date' => null,
        ]);

        // A gap in the overlap guard (only enforced by Postgres/FormRequest,
        // not by this model) could allow a second overlapping row — this
        // simulates that data-integrity failure directly.
        SocialSecurityAffiliation::factory()->create([
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'entity_id' => $entityTwo->id,
            'entity_type' => 'CATEGORY_A',
            'start_date' => '2025-01-01',
            'end_date' => null,
        ]);

        $this->expectException(NoActiveSocialSecurityAffiliationException::class);

        SocialSecurityAffiliation::activeFor($this->employee->id, 'CATEGORY_A', Carbon::parse('2025-03-01'));
    }
}
