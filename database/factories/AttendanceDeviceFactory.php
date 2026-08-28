<?php

namespace Database\Factories;

use App\Models\AttendanceDevice;
use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AttendanceDevice>
 */
class AttendanceDeviceFactory extends Factory
{
    protected $model = AttendanceDevice::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'branch_id' => null,
            'provider' => fake()->randomElement(['zkteco', 'suprema', 'generic']),
            'external_device_id' => fake()->uuid(),
            'status' => 'inactive',
            'last_heartbeat_at' => null,
        ];
    }
}
