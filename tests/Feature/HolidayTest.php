<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Holiday;
use App\Models\Role;
use App\Models\User;
use App\Models\UserCompanyMembership;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HolidayTest extends TestCase
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

    public function test_index_shows_platform_defaults_and_company_holidays_ordered_by_date()
    {
        // Platform default, no company_id (seeder-only in production, but a
        // factory-created row proves the same mechanism from a test).
        Holiday::factory()->create(['company_id' => null, 'date' => '2026-01-01', 'name' => 'Año Nuevo']);
        Holiday::factory()->create(['company_id' => $this->company->id, 'date' => '2026-03-15', 'name' => 'Aniversario Empresa']);

        $response = $this->actingAs($this->owner)->get(route('holidays.index'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('holidays/Index')
            ->has('holidays', 2)
            ->where('holidays.0.name', 'Año Nuevo')
            ->where('holidays.0.is_platform_default', true)
            ->where('holidays.1.name', 'Aniversario Empresa')
            ->where('holidays.1.is_platform_default', false),
        );
    }

    public function test_creating_a_company_holiday_succeeds_with_current_company_id()
    {
        $this->actingAs($this->owner)->post(route('holidays.store'), [
            'date' => '2026-06-10',
            'name' => 'Festivo Local',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $holiday = Holiday::query()->where('name', 'Festivo Local')->firstOrFail();

        $this->assertSame($this->company->id, $holiday->company_id);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'holiday.created',
            'entity_type' => 'holidays',
            'entity_id' => $holiday->id,
        ]);
    }

    public function test_updating_a_companys_own_holiday_succeeds()
    {
        $holiday = Holiday::factory()->create(['company_id' => $this->company->id, 'name' => 'Nombre Viejo']);

        $this->actingAs($this->owner)->put(route('holidays.update', $holiday), [
            'date' => $holiday->date->format('Y-m-d'),
            'name' => 'Nombre Nuevo',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('Nombre Nuevo', $holiday->fresh()->name);

        $auditLog = AuditLog::query()
            ->where('entity_type', 'holidays')
            ->where('entity_id', $holiday->id)
            ->where('action', 'holiday.updated')
            ->firstOrFail();

        $this->assertSame('Nombre Viejo', $auditLog->old_value['name']);
        $this->assertSame('Nombre Nuevo', $auditLog->new_value['name']);
    }

    public function test_deleting_a_companys_own_holiday_soft_deletes_it()
    {
        $holiday = Holiday::factory()->create(['company_id' => $this->company->id]);

        $this->actingAs($this->owner)->delete(route('holidays.destroy', $holiday))
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSoftDeleted($holiday);

        $auditLog = AuditLog::query()
            ->where('entity_type', 'holidays')
            ->where('entity_id', $holiday->id)
            ->where('action', 'holiday.deleted')
            ->firstOrFail();

        $this->assertNull($auditLog->new_value);
    }

    public function test_updating_a_platform_default_holiday_is_rejected()
    {
        $holiday = Holiday::factory()->create(['company_id' => null, 'name' => 'Navidad']);

        $client = $this->actingAs($this->owner);
        $client->get(route('holidays.index'));

        $client->put(route('holidays.update', $holiday), [
            'date' => $holiday->date->format('Y-m-d'),
            'name' => 'Intento De Editar Default',
        ])->assertNotFound();

        $this->assertSame('Navidad', $holiday->fresh()->name);
    }

    public function test_deleting_a_platform_default_holiday_is_rejected()
    {
        $holiday = Holiday::factory()->create(['company_id' => null, 'name' => 'Navidad']);

        $client = $this->actingAs($this->owner);
        $client->get(route('holidays.index'));

        $client->delete(route('holidays.destroy', $holiday))->assertNotFound();

        $this->assertNull($holiday->fresh()->deleted_at);
    }

    public function test_updating_another_companys_holiday_is_rejected()
    {
        $otherCompany = Company::factory()->create();
        $foreignHoliday = Holiday::factory()->create(['company_id' => $otherCompany->id]);

        $client = $this->actingAs($this->owner);
        $client->get(route('holidays.index'));

        $client->put(route('holidays.update', $foreignHoliday), [
            'date' => $foreignHoliday->date->format('Y-m-d'),
            'name' => 'Intento Cruzado',
        ])->assertNotFound();
    }

    public function test_deleting_another_companys_holiday_is_rejected()
    {
        $otherCompany = Company::factory()->create();
        $foreignHoliday = Holiday::factory()->create(['company_id' => $otherCompany->id]);

        $client = $this->actingAs($this->owner);
        $client->get(route('holidays.index'));

        $client->delete(route('holidays.destroy', $foreignHoliday))->assertNotFound();
    }

    public function test_a_user_without_the_holidays_write_permission_is_denied_on_store()
    {
        $rankAndFile = $this->createEmployeeUser();

        $this->actingAs($rankAndFile)->post(route('holidays.store'), [
            'date' => '2026-06-10',
            'name' => 'Festivo Prohibido',
        ])->assertForbidden();
    }

    public function test_a_user_without_the_holidays_write_permission_is_denied_on_update()
    {
        $holiday = Holiday::factory()->create(['company_id' => $this->company->id]);
        $rankAndFile = $this->createEmployeeUser();

        $this->actingAs($rankAndFile)->put(route('holidays.update', $holiday), [
            'date' => $holiday->date->format('Y-m-d'),
            'name' => 'Nombre Prohibido',
        ])->assertForbidden();
    }

    public function test_a_user_without_the_holidays_write_permission_is_denied_on_destroy()
    {
        $holiday = Holiday::factory()->create(['company_id' => $this->company->id]);
        $rankAndFile = $this->createEmployeeUser();

        $this->actingAs($rankAndFile)->delete(route('holidays.destroy', $holiday))->assertForbidden();
    }

    public function test_a_user_without_the_holidays_read_permission_is_denied()
    {
        $rankAndFile = $this->createEmployeeUser();

        $this->actingAs($rankAndFile)->get(route('holidays.index'))->assertForbidden();
    }

    private function createEmployeeUser(): User
    {
        $employeeRole = Role::query()->whereNull('company_id')->where('name', 'EMPLOYEE')->firstOrFail();

        $user = User::factory()->create();

        UserCompanyMembership::create([
            'user_id' => $user->id,
            'company_id' => $this->company->id,
            'role_id' => $employeeRole->id,
            'status' => 'active',
        ]);

        return $user;
    }
}
