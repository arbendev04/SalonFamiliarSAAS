<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Employee;
use App\Models\PayrollInformation;
use App\Models\Role;
use App\Models\User;
use App\Models\UserCompanyMembership;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PayrollInformationTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private Employee $employee;

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

        $this->employee = Employee::factory()->create(['company_id' => $this->company->id]);
    }

    public function test_it_creates_payroll_information_and_encrypts_the_bank_account_at_rest()
    {
        $this->actingAs($this->owner)->post(route('employees.payroll-information.store', $this->employee), [
            'bank_account_enc' => '1234567890',
            'tax_regime' => 'simplificado',
        ])->assertRedirect();

        $record = PayrollInformation::query()->where('employee_id', $this->employee->id)->firstOrFail();

        $this->assertSame('1234567890', $record->bank_account_enc);
        $this->assertStringNotContainsString(
            '1234567890',
            $record->getRawOriginal('bank_account_enc') ?? '',
        );
    }

    public function test_posting_again_updates_the_existing_record_instead_of_creating_a_second_one()
    {
        $this->actingAs($this->owner)->post(route('employees.payroll-information.store', $this->employee), [
            'bank_account_enc' => '1111111111',
        ]);

        $this->actingAs($this->owner)->post(route('employees.payroll-information.store', $this->employee), [
            'bank_account_enc' => '2222222222',
        ]);

        $this->assertSame(1, PayrollInformation::query()->where('employee_id', $this->employee->id)->count());

        $record = PayrollInformation::query()->where('employee_id', $this->employee->id)->firstOrFail();
        $this->assertSame('2222222222', $record->bank_account_enc);
    }
}
