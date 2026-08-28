<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\LaborRule;
use App\Models\LaborRuleVersion;
use App\Models\Role;
use App\Models\User;
use App\Models\UserCompanyMembership;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LaborRuleVersionTest extends TestCase
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

    public function test_creating_a_version_with_valid_data_succeeds()
    {
        $laborRule = LaborRule::factory()->create(['company_id' => $this->company->id]);

        $this->actingAs($this->owner)->post(route('labor-rules.versions.store'), [
            'labor_rule_id' => $laborRule->id,
            'effective_from' => '2026-01-01',
            'parameters' => [
                'tolerance_minutes' => 15,
                'rounding_minutes' => 5,
            ],
        ])->assertRedirect();

        $version = LaborRuleVersion::query()->where('labor_rule_id', $laborRule->id)->firstOrFail();

        $this->assertSame($this->company->id, $version->company_id);
        $this->assertSame('2026-01-01', $version->effective_from->toDateString());
        $this->assertSame(15, $version->parameters['tolerance_minutes']);
        $this->assertSame(5, $version->parameters['rounding_minutes']);
        $this->assertSame($this->owner->id, $version->created_by);
    }

    public function test_creating_an_overlapping_version_is_rejected()
    {
        $laborRule = LaborRule::factory()->create(['company_id' => $this->company->id]);

        LaborRuleVersion::factory()->create([
            'company_id' => $this->company->id,
            'labor_rule_id' => $laborRule->id,
            'effective_from' => '2026-01-01',
            'effective_to' => null,
        ]);

        $response = $this->actingAs($this->owner)->post(route('labor-rules.versions.store'), [
            'labor_rule_id' => $laborRule->id,
            'effective_from' => '2026-03-01',
            'parameters' => [
                'tolerance_minutes' => 10,
                'rounding_minutes' => 5,
            ],
        ]);

        $response->assertSessionHasErrors('effective_from');
        $this->assertSame(1, LaborRuleVersion::query()->where('labor_rule_id', $laborRule->id)->count());
    }

    public function test_two_non_overlapping_versions_both_succeed()
    {
        $laborRule = LaborRule::factory()->create(['company_id' => $this->company->id]);

        LaborRuleVersion::factory()->create([
            'company_id' => $this->company->id,
            'labor_rule_id' => $laborRule->id,
            'effective_from' => '2026-01-01',
            'effective_to' => '2026-02-28',
        ]);

        $this->actingAs($this->owner)->post(route('labor-rules.versions.store'), [
            'labor_rule_id' => $laborRule->id,
            'effective_from' => '2026-03-01',
            'parameters' => [
                'tolerance_minutes' => 10,
                'rounding_minutes' => 5,
            ],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(2, LaborRuleVersion::query()->where('labor_rule_id', $laborRule->id)->count());
    }

    public function test_missing_tolerance_minutes_parameter_is_rejected()
    {
        $laborRule = LaborRule::factory()->create(['company_id' => $this->company->id]);

        $response = $this->actingAs($this->owner)->post(route('labor-rules.versions.store'), [
            'labor_rule_id' => $laborRule->id,
            'effective_from' => '2026-01-01',
            'parameters' => [
                'rounding_minutes' => 5,
            ],
        ]);

        $response->assertSessionHasErrors('parameters.tolerance_minutes');
    }

    public function test_missing_rounding_minutes_parameter_is_rejected()
    {
        $laborRule = LaborRule::factory()->create(['company_id' => $this->company->id]);

        $response = $this->actingAs($this->owner)->post(route('labor-rules.versions.store'), [
            'labor_rule_id' => $laborRule->id,
            'effective_from' => '2026-01-01',
            'parameters' => [
                'tolerance_minutes' => 15,
            ],
        ]);

        $response->assertSessionHasErrors('parameters.rounding_minutes');
    }

    public function test_a_labor_rule_from_another_company_cannot_be_used()
    {
        $foreignLaborRule = LaborRule::factory()->create(['company_id' => Company::factory()->create()->id]);

        $response = $this->actingAs($this->owner)->post(route('labor-rules.versions.store'), [
            'labor_rule_id' => $foreignLaborRule->id,
            'effective_from' => '2026-01-01',
            'parameters' => [
                'tolerance_minutes' => 15,
                'rounding_minutes' => 5,
            ],
        ]);

        $response->assertSessionHasErrors('labor_rule_id');
        $this->assertSame(0, LaborRuleVersion::query()->where('labor_rule_id', $foreignLaborRule->id)->count());
    }

    public function test_a_user_without_the_labor_rules_write_permission_is_denied()
    {
        $accountantRole = Role::query()->whereNull('company_id')->where('name', 'ACCOUNTANT')->firstOrFail();

        $accountant = User::factory()->create();

        UserCompanyMembership::create([
            'user_id' => $accountant->id,
            'company_id' => $this->company->id,
            'role_id' => $accountantRole->id,
            'status' => 'active',
        ]);

        $laborRule = LaborRule::factory()->create(['company_id' => $this->company->id]);

        $this->actingAs($accountant)->post(route('labor-rules.versions.store'), [
            'labor_rule_id' => $laborRule->id,
            'effective_from' => '2026-01-01',
            'parameters' => [
                'tolerance_minutes' => 15,
                'rounding_minutes' => 5,
            ],
        ])->assertForbidden();
    }

    public function test_a_user_without_the_labor_rules_read_permission_is_denied_for_index()
    {
        $employeeRole = Role::query()->whereNull('company_id')->where('name', 'EMPLOYEE')->firstOrFail();

        $rankAndFile = User::factory()->create();

        UserCompanyMembership::create([
            'user_id' => $rankAndFile->id,
            'company_id' => $this->company->id,
            'role_id' => $employeeRole->id,
            'status' => 'active',
        ]);

        $this->actingAs($rankAndFile)->get(route('labor-rules.index'))->assertForbidden();
    }

    public function test_index_creates_the_companys_labor_rule_idempotently()
    {
        $this->assertSame(0, LaborRule::query()->where('company_id', $this->company->id)->count());

        $this->actingAs($this->owner)->get(route('labor-rules.index'))->assertOk();
        $this->actingAs($this->owner)->get(route('labor-rules.index'))->assertOk();

        $this->assertSame(
            1,
            LaborRule::query()
                ->where('company_id', $this->company->id)
                ->where('rule_type', 'STANDARD_WORKWEEK')
                ->count(),
        );
    }
}
