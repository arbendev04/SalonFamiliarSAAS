<?php

namespace Tests\Unit;

use App\Models\Company;
use App\Models\LeaveType;
use App\Models\NoveltyType;
use App\Services\Tenancy\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HasPlatformOrCompanyDefaultTest extends TestCase
{
    use RefreshDatabase;

    public function test_platform_default_row_is_invisible_through_the_default_query_when_a_company_is_active()
    {
        $company = Company::factory()->create();
        $default = LeaveType::factory()->create(['company_id' => null, 'code' => 'VACATION']);

        app(CurrentCompany::class)->set($company);

        $this->assertNull(LeaveType::query()->find($default->id));
    }

    public function test_platform_default_row_is_visible_through_effective_for_company()
    {
        $company = Company::factory()->create();
        $default = LeaveType::factory()->create(['company_id' => null, 'code' => 'VACATION']);

        app(CurrentCompany::class)->set($company);

        $visible = LeaveType::query()->effectiveForCompany($company->id)->get();

        $this->assertTrue($visible->contains('id', $default->id));
    }

    public function test_company_specific_override_wins_over_the_platform_default_for_the_same_code_on_leave_type()
    {
        $company = Company::factory()->create();

        $default = LeaveType::factory()->create(['company_id' => null, 'code' => 'VACATION', 'name' => 'Default Vacation']);
        $override = LeaveType::factory()->create(['company_id' => $company->id, 'code' => 'VACATION', 'name' => 'Custom Vacation']);

        app(CurrentCompany::class)->set($company);

        $catalog = LeaveType::effectiveCatalog($company->id);

        $vacationEntries = $catalog->where('code', 'VACATION');

        $this->assertCount(1, $vacationEntries);
        $this->assertSame($override->id, $vacationEntries->first()->id);
        $this->assertNotSame($default->id, $vacationEntries->first()->id);
    }

    public function test_company_specific_override_wins_over_the_platform_default_for_the_same_code_on_novelty_type()
    {
        $company = Company::factory()->create();

        $default = NoveltyType::factory()->create(['company_id' => null, 'code' => 'ABSENCE']);
        $override = NoveltyType::factory()->create(['company_id' => $company->id, 'code' => 'ABSENCE']);

        app(CurrentCompany::class)->set($company);

        $catalog = NoveltyType::effectiveCatalog($company->id);

        $absenceEntries = $catalog->where('code', 'ABSENCE');

        $this->assertCount(1, $absenceEntries);
        $this->assertSame($override->id, $absenceEntries->first()->id);
        $this->assertNotSame($default->id, $absenceEntries->first()->id);
    }

    public function test_platform_default_without_a_company_override_still_appears_in_the_effective_catalog()
    {
        $company = Company::factory()->create();
        $default = LeaveType::factory()->create(['company_id' => null, 'code' => 'UNPAID_LEAVE']);

        app(CurrentCompany::class)->set($company);

        $catalog = LeaveType::effectiveCatalog($company->id);

        $entries = $catalog->where('code', 'UNPAID_LEAVE');

        $this->assertCount(1, $entries);
        $this->assertSame($default->id, $entries->first()->id);
    }

    public function test_a_different_companys_override_never_appears_for_this_company()
    {
        $company = Company::factory()->create();
        $otherCompany = Company::factory()->create();

        $foreign = LeaveType::factory()->create(['company_id' => $otherCompany->id, 'code' => 'VACATION']);

        app(CurrentCompany::class)->set($company);

        $visible = LeaveType::query()->effectiveForCompany($company->id)->get();
        $catalog = LeaveType::effectiveCatalog($company->id);

        $this->assertFalse($visible->contains('id', $foreign->id));
        $this->assertFalse($catalog->contains('id', $foreign->id));
    }
}
