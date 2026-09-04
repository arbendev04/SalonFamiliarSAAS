<?php

namespace Tests\Feature;

use App\Exceptions\AuditLogImmutableException;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditLogImmutabilityTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
        $this->user = User::factory()->create();
    }

    private function createLog(): AuditLog
    {
        return AuditLog::factory()->create([
            'company_id' => $this->company->id,
            'user_id' => $this->user->id,
        ]);
    }

    public function test_updating_an_audit_log_instance_throws()
    {
        $log = $this->createLog();

        $this->expectException(AuditLogImmutableException::class);

        $log->update(['action' => 'tampered.action']);
    }

    public function test_deleting_an_audit_log_instance_throws()
    {
        $log = $this->createLog();

        $this->expectException(AuditLogImmutableException::class);

        $log->delete();
    }

    public function test_updating_via_query_builder_throws()
    {
        $log = $this->createLog();

        $this->expectException(AuditLogImmutableException::class);

        AuditLog::query()->where('id', $log->id)->update(['action' => 'tampered.action']);
    }

    public function test_deleting_via_query_builder_throws()
    {
        $log = $this->createLog();

        $this->expectException(AuditLogImmutableException::class);

        AuditLog::query()->where('id', $log->id)->delete();
    }

    public function test_creating_an_audit_log_still_succeeds_and_is_retrievable()
    {
        $log = $this->createLog();

        $this->assertDatabaseHas('audit_logs', [
            'id' => $log->id,
            'company_id' => $this->company->id,
            'user_id' => $this->user->id,
        ]);

        $this->assertNotNull(AuditLog::find($log->id));
    }
}
