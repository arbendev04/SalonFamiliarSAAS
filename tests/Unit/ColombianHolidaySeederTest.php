<?php

namespace Tests\Unit;

use App\Models\Holiday;
use Database\Seeders\ColombianHolidaySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ColombianHolidaySeederTest extends TestCase
{
    use RefreshDatabase;

    private const FIXED_HOLIDAYS = [
        ['month' => 1, 'day' => 1, 'name' => 'Año Nuevo'],
        ['month' => 5, 'day' => 1, 'name' => 'Día del Trabajo'],
        ['month' => 7, 'day' => 20, 'name' => 'Independencia de Colombia'],
        ['month' => 8, 'day' => 7, 'name' => 'Batalla de Boyacá'],
        ['month' => 12, 'day' => 8, 'name' => 'Inmaculada Concepción'],
        ['month' => 12, 'day' => 25, 'name' => 'Navidad'],
    ];

    public function test_seeds_the_6_fixed_date_holidays_as_platform_defaults_for_the_current_and_next_year()
    {
        $this->seed(ColombianHolidaySeeder::class);

        $currentYear = (int) Carbon::now()->format('Y');

        foreach ([$currentYear, $currentYear + 1] as $year) {
            foreach (self::FIXED_HOLIDAYS as $holiday) {
                $date = Carbon::create($year, $holiday['month'], $holiday['day'])->format('Y-m-d');

                $this->assertDatabaseHas('holidays', [
                    'company_id' => null,
                    'date' => $date,
                    'name' => $holiday['name'],
                ]);
            }
        }

        $this->assertSame(12, Holiday::query()->whereNull('company_id')->count());
    }

    public function test_running_the_seeder_twice_does_not_create_duplicates()
    {
        $this->seed(ColombianHolidaySeeder::class);
        $this->seed(ColombianHolidaySeeder::class);

        $this->assertSame(12, Holiday::query()->whereNull('company_id')->count());
    }

    public function test_moveable_and_ley_emiliani_shifted_holidays_are_deliberately_not_seeded()
    {
        $this->seed(ColombianHolidaySeeder::class);

        $excludedNames = [
            'Jueves Santo',
            'Viernes Santo',
            'Reyes Magos',
            'San José',
            'Ascensión del Señor',
            'Corpus Christi',
            'Sagrado Corazón',
            'San Pedro y San Pablo',
            'Asunción de la Virgen',
            'Día de la Raza',
            'Todos los Santos',
            'Independencia de Cartagena',
        ];

        foreach ($excludedNames as $name) {
            $this->assertDatabaseMissing('holidays', ['name' => $name]);
        }
    }
}
