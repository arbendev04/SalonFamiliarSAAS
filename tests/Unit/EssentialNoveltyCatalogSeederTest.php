<?php

namespace Tests\Unit;

use App\Models\LeaveType;
use App\Models\NoveltyType;
use Database\Seeders\EssentialNoveltyCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EssentialNoveltyCatalogSeederTest extends TestCase
{
    use RefreshDatabase;

    private const EXPECTED_CODES_TO_NAMES = [
        'AUSENCIA' => 'Ausencia',
        'PERMISO' => 'Permiso',
        'VACACIONES' => 'Vacaciones',
        'INCAPACIDAD' => 'Incapacidad',
    ];

    public function test_seeds_exactly_4_platform_default_leave_types_with_expected_codes_and_names()
    {
        $this->seed(EssentialNoveltyCatalogSeeder::class);

        $leaveTypes = LeaveType::query()->whereNull('company_id')->get();

        $this->assertCount(4, $leaveTypes);

        foreach (self::EXPECTED_CODES_TO_NAMES as $code => $name) {
            $this->assertTrue(
                $leaveTypes->contains(fn (LeaveType $leaveType) => $leaveType->code === $code && $leaveType->name === $name),
                "Expected a leave_types row with code={$code} and name={$name}.",
            );
        }
    }

    public function test_seeds_exactly_4_platform_default_novelty_types_that_affect_time_calc_but_not_payroll()
    {
        $this->seed(EssentialNoveltyCatalogSeeder::class);

        $noveltyTypes = NoveltyType::query()->whereNull('company_id')->get();

        $this->assertCount(4, $noveltyTypes);

        foreach (self::EXPECTED_CODES_TO_NAMES as $code => $name) {
            $this->assertTrue(
                $noveltyTypes->contains(fn (NoveltyType $noveltyType) => $noveltyType->code === $code
                    && $noveltyType->name === $name
                    && $noveltyType->affects_time_calc === true
                    && $noveltyType->affects_payroll === false),
                "Expected a novelty_types row with code={$code}, name={$name}, affects_time_calc=true, affects_payroll=false.",
            );
        }
    }

    public function test_running_the_seeder_twice_does_not_create_duplicates()
    {
        $this->seed(EssentialNoveltyCatalogSeeder::class);
        $this->seed(EssentialNoveltyCatalogSeeder::class);

        $this->assertSame(4, LeaveType::query()->whereNull('company_id')->count());
        $this->assertSame(4, NoveltyType::query()->whereNull('company_id')->count());
    }
}
