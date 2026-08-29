<?php

namespace Tests\Unit\SocialSecurity;

use App\Http\Requests\StoreSocialSecurityAffiliationRequest;
use App\Models\Company;
use App\Models\Employee;
use App\Models\SocialSecurityAffiliation;
use App\Models\SocialSecurityEntity;
use App\Services\Tenancy\CurrentCompany;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * No controller/route exists yet for creating a social security affiliation
 * (deferred to commit 9 of composed-knitting-dusk.md — "Servicio de
 * afiliación"), so unlike EmploymentContractTest this exercises the
 * FormRequest directly rather than through HTTP routes — the same
 * convention already used by tests/Unit/LeaveRecordServiceTest.php for a
 * service with no controller yet. CurrentCompany is set manually (no
 * SetCurrentCompany middleware to run it for us), matching
 * tests/Unit/HasPlatformOrCompanyDefaultTest.php.
 *
 * rules() and withValidator() are both public methods on FormRequest, so
 * they can be driven directly against a plain Illuminate\Validation\Validator
 * built the same way FormRequest::getValidatorInstance() builds one
 * internally — no live HTTP request/route/controller required, and no
 * throwaway controller invented just to exercise this validation.
 */
class StoreSocialSecurityAffiliationRequestTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
        app(CurrentCompany::class)->set($this->company);

        $this->employee = Employee::factory()->create(['company_id' => $this->company->id]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function validate(array $data): ValidatorContract
    {
        $request = StoreSocialSecurityAffiliationRequest::create('/social-security/affiliations', 'POST', $data);

        $validator = Validator::make($data, $request->rules());
        $request->withValidator($validator);

        return $validator;
    }

    public function test_it_rejects_a_new_affiliation_that_overlaps_an_existing_one_of_the_same_resolved_entity_type()
    {
        $entity = SocialSecurityEntity::factory()->create([
            'company_id' => $this->company->id,
            'type' => 'CATEGORY_A',
        ]);

        SocialSecurityAffiliation::factory()->create([
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'entity_id' => $entity->id,
            'entity_type' => 'CATEGORY_A',
            'start_date' => '2025-01-01',
            'end_date' => null,
        ]);

        // A different entity of the *same type* — decision 2 of
        // composed-knitting-dusk.md keys the overlap on entity_type, not on
        // the exact entity_id.
        $anotherEntitySameType = SocialSecurityEntity::factory()->create([
            'company_id' => $this->company->id,
            'type' => 'CATEGORY_A',
        ]);

        $validator = $this->validate([
            'employee_id' => $this->employee->id,
            'entity_id' => $anotherEntitySameType->id,
            'start_date' => '2025-06-01',
        ]);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('start_date', $validator->errors()->toArray());
    }

    public function test_it_derives_entity_type_from_the_given_entity_id_and_never_trusts_a_client_supplied_value()
    {
        $entity = SocialSecurityEntity::factory()->create([
            'company_id' => $this->company->id,
            'type' => 'CATEGORY_A',
        ]);

        SocialSecurityAffiliation::factory()->create([
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'entity_id' => $entity->id,
            'entity_type' => 'CATEGORY_A',
            'start_date' => '2025-01-01',
            'end_date' => null,
        ]);

        // entity_type is not even a rule in rules(), so a client-supplied
        // value must have zero effect: the real type is resolved server-side
        // from entity_id (which is CATEGORY_A here), and the overlap must
        // still be caught despite the bogus client value.
        $validator = $this->validate([
            'employee_id' => $this->employee->id,
            'entity_id' => $entity->id,
            'entity_type' => 'NOT_A_REAL_TYPE',
            'start_date' => '2025-06-01',
        ]);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('start_date', $validator->errors()->toArray());
    }

    public function test_it_allows_a_new_affiliation_of_a_different_entity_type_even_when_dates_overlap()
    {
        $entityA = SocialSecurityEntity::factory()->create([
            'company_id' => $this->company->id,
            'type' => 'CATEGORY_A',
        ]);

        SocialSecurityAffiliation::factory()->create([
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'entity_id' => $entityA->id,
            'entity_type' => 'CATEGORY_A',
            'start_date' => '2025-01-01',
            'end_date' => null,
        ]);

        $entityB = SocialSecurityEntity::factory()->create([
            'company_id' => $this->company->id,
            'type' => 'CATEGORY_B',
        ]);

        $validator = $this->validate([
            'employee_id' => $this->employee->id,
            'entity_id' => $entityB->id,
            'start_date' => '2025-06-01',
        ]);

        $this->assertFalse($validator->fails());
    }

    public function test_it_allows_a_non_overlapping_affiliation_after_the_previous_one_of_the_same_type_is_closed()
    {
        $entity = SocialSecurityEntity::factory()->create([
            'company_id' => $this->company->id,
            'type' => 'CATEGORY_A',
        ]);

        SocialSecurityAffiliation::factory()->create([
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'entity_id' => $entity->id,
            'entity_type' => 'CATEGORY_A',
            'start_date' => '2025-01-01',
            'end_date' => '2025-05-31',
        ]);

        $successorEntity = SocialSecurityEntity::factory()->create([
            'company_id' => $this->company->id,
            'type' => 'CATEGORY_A',
        ]);

        $validator = $this->validate([
            'employee_id' => $this->employee->id,
            'entity_id' => $successorEntity->id,
            'start_date' => '2025-06-01',
        ]);

        $this->assertFalse($validator->fails());
    }

    public function test_employee_id_must_belong_to_the_current_company()
    {
        $foreignEmployee = Employee::factory()->create(['company_id' => Company::factory()->create()->id]);

        $entity = SocialSecurityEntity::factory()->create(['company_id' => $this->company->id]);

        $validator = $this->validate([
            'employee_id' => $foreignEmployee->id,
            'entity_id' => $entity->id,
            'start_date' => '2025-06-01',
        ]);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('employee_id', $validator->errors()->toArray());
    }

    public function test_entity_id_must_exist_in_the_tenants_effective_catalog()
    {
        $foreignEntity = SocialSecurityEntity::factory()->create(['company_id' => Company::factory()->create()->id]);

        $validator = $this->validate([
            'employee_id' => $this->employee->id,
            'entity_id' => $foreignEntity->id,
            'start_date' => '2025-06-01',
        ]);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('entity_id', $validator->errors()->toArray());
    }

    public function test_a_platform_default_entity_is_a_valid_entity_id_and_still_participates_in_the_overlap_check()
    {
        $platformEntity = SocialSecurityEntity::factory()->create([
            'company_id' => null,
            'type' => 'CATEGORY_A',
        ]);

        SocialSecurityAffiliation::factory()->create([
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'entity_id' => $platformEntity->id,
            'entity_type' => 'CATEGORY_A',
            'start_date' => '2025-01-01',
            'end_date' => null,
        ]);

        $validator = $this->validate([
            'employee_id' => $this->employee->id,
            'entity_id' => $platformEntity->id,
            'start_date' => '2025-06-01',
        ]);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('start_date', $validator->errors()->toArray());
    }
}
