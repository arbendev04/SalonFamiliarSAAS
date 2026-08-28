<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

/**
 * Seeds the 8 system roles and their permission grants from the RBAC
 * matrix in .ai/06-AUTHORIZATION.md. "Solo propio" / "equipo" nuances in
 * that matrix are ownership-scoping rules enforced by each module's own
 * policies, not atomic permission grants, so they are not listed here.
 */
class RoleSeeder extends Seeder
{
    private const ALL_PERMISSIONS = [
        'companies.manage', 'users.manage', 'roles.manage',
        'employees.read', 'employees.create', 'employees.update',
        'branches.read', 'branches.write',
        'positions.read', 'positions.write',
        'contracts.read', 'contracts.write',
        'schedules.write',
        'attendance.read', 'attendance.adjust', 'attendance.approve_adjustment',
        'overtime.request', 'overtime.authorize',
        'leave.approve',
        'payroll.read', 'payroll.calculate', 'payroll.approve', 'payroll.close', 'payroll.reopen', 'payroll.adjust',
        'social_security.manage',
        'reports.read', 'reports.export',
        'devices.manage',
        'biometrics.enroll', 'biometrics.delete_data',
        'audit.read',
        'settings.manage',
    ];

    /**
     * @var array<string, list<string>>
     */
    private const GRANTS = [
        'SUPER_ADMIN' => self::ALL_PERMISSIONS,
        'COMPANY_OWNER' => [
            'companies.manage', 'users.manage', 'roles.manage',
            'employees.read', 'employees.create', 'employees.update',
            'branches.read', 'branches.write',
            'positions.read', 'positions.write',
            'contracts.read', 'contracts.write',
            'schedules.write',
            'attendance.read', 'attendance.adjust', 'attendance.approve_adjustment',
            'overtime.request', 'overtime.authorize',
            'leave.approve',
            'payroll.read', 'payroll.approve', 'payroll.close', 'payroll.reopen', 'payroll.adjust',
            'social_security.manage',
            'reports.read', 'reports.export',
            'devices.manage',
            'biometrics.enroll', 'biometrics.delete_data',
            'audit.read',
            'settings.manage',
        ],
        'ADMIN' => [
            'users.manage', 'roles.manage',
            'employees.read', 'employees.create', 'employees.update',
            'branches.read', 'branches.write',
            'positions.read', 'positions.write',
            'contracts.read', 'contracts.write',
            'schedules.write',
            'attendance.read', 'attendance.adjust', 'attendance.approve_adjustment',
            'overtime.request', 'overtime.authorize',
            'leave.approve',
            'payroll.read', 'payroll.approve', 'payroll.close', 'payroll.reopen', 'payroll.adjust',
            'social_security.manage',
            'reports.read', 'reports.export',
            'devices.manage',
            'biometrics.enroll', 'biometrics.delete_data',
            'audit.read',
            'settings.manage',
        ],
        'HR_MANAGER' => [
            'employees.read', 'employees.create', 'employees.update',
            'branches.read', 'branches.write',
            'positions.read', 'positions.write',
            'contracts.read', 'contracts.write',
            'schedules.write',
            'attendance.read', 'attendance.adjust', 'attendance.approve_adjustment',
            'overtime.request', 'overtime.authorize',
            'leave.approve',
            'biometrics.enroll',
            'reports.read', 'reports.export',
        ],
        'PAYROLL_MANAGER' => [
            'employees.read',
            'branches.read',
            'positions.read',
            'contracts.read',
            'attendance.read',
            'payroll.read', 'payroll.calculate', 'payroll.approve', 'payroll.close',
            'social_security.manage',
            'reports.read', 'reports.export',
        ],
        'SUPERVISOR' => [
            'employees.read',
            'branches.read',
            'positions.read',
            'schedules.write',
            'attendance.read', 'attendance.adjust',
            'overtime.request', 'overtime.authorize',
            'leave.approve',
            'reports.read', 'reports.export',
        ],
        'ACCOUNTANT' => [
            'employees.read',
            'branches.read',
            'positions.read',
            'contracts.read',
            'payroll.read',
            'social_security.manage',
            'reports.read', 'reports.export',
        ],
        'EMPLOYEE' => [
            'overtime.request',
        ],
    ];

    public function run(): void
    {
        $permissionIds = Permission::query()->pluck('id', 'code');

        foreach (self::GRANTS as $name => $codes) {
            $role = Role::query()->updateOrCreate(
                ['company_id' => null, 'name' => $name],
                ['is_system' => true],
            );

            $role->permissions()->sync(
                collect($codes)->map(fn (string $code) => $permissionIds[$code])->all(),
            );
        }
    }
}
