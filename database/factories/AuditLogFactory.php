<?php

namespace Database\Factories;

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<AuditLog>
 */
class AuditLogFactory extends Factory
{
    protected $model = AuditLog::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'user_id' => User::factory(),
            'action' => 'test.action',
            'entity_type' => 'test_entities',
            'entity_id' => (string) Str::uuid(),
            'old_value' => null,
            'new_value' => null,
            'reason' => null,
            'ip_address' => '127.0.0.1',
        ];
    }
}
