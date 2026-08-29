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
        'attendance.read', 'attendance.record', 'attendance.adjust', 'attendance.approve_adjustment',
        'overtime.request', 'overtime.authorize', 'overtime.mark_paid',
        'leave.approve', 'leave.create',
        'payroll.read', 'payroll.calculate', 'payroll.approve', 'payroll.close', 'payroll.reopen', 'payroll.adjust',
        'social_security.manage',
        'reports.read', 'reports.export',
        'devices.manage',
        'biometrics.enroll', 'biometrics.delete_data',
        'audit.read',
        'settings.manage',
        'labor_rules.read', 'labor_rules.write',
        'time_calculation.read', 'time_calculation.calculate',
        'holidays.read', 'holidays.write',
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
            'attendance.read', 'attendance.record', 'attendance.adjust', 'attendance.approve_adjustment',
            'overtime.request', 'overtime.authorize', 'overtime.mark_paid',
            'leave.approve', 'leave.create',
            'payroll.read', 'payroll.approve', 'payroll.close', 'payroll.reopen', 'payroll.adjust',
            'social_security.manage',
            'reports.read', 'reports.export',
            'devices.manage',
            'biometrics.enroll', 'biometrics.delete_data',
            'audit.read',
            'settings.manage',
            'labor_rules.read', 'labor_rules.write',
            'time_calculation.read', 'time_calculation.calculate',
            'holidays.read', 'holidays.write',
        ],
        'ADMIN' => [
            'users.manage', 'roles.manage',
            'employees.read', 'employees.create', 'employees.update',
            'branches.read', 'branches.write',
            'positions.read', 'positions.write',
            'contracts.read', 'contracts.write',
            'schedules.write',
            'attendance.read', 'attendance.record', 'attendance.adjust', 'attendance.approve_adjustment',
            'overtime.request', 'overtime.authorize', 'overtime.mark_paid',
            'leave.approve', 'leave.create',
            'payroll.read', 'payroll.approve', 'payroll.close', 'payroll.reopen', 'payroll.adjust',
            'social_security.manage',
            'reports.read', 'reports.export',
            'devices.manage',
            'biometrics.enroll', 'biometrics.delete_data',
            'audit.read',
            'settings.manage',
            'labor_rules.read', 'labor_rules.write',
            'time_calculation.read', 'time_calculation.calculate',
            'holidays.read', 'holidays.write',
        ],
        'HR_MANAGER' => [
            'employees.read', 'employees.create', 'employees.update',
            'branches.read', 'branches.write',
            'positions.read', 'positions.write',
            'contracts.read', 'contracts.write',
            'schedules.write',
            'attendance.read', 'attendance.record', 'attendance.adjust', 'attendance.approve_adjustment',
            'overtime.request', 'overtime.authorize', 'overtime.mark_paid',
            'leave.approve', 'leave.create',
            'biometrics.enroll',
            'reports.read', 'reports.export',
            'time_calculation.read', 'time_calculation.calculate',
            'holidays.read', 'holidays.write',
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
            'labor_rules.read',
            'time_calculation.read', 'time_calculation.calculate',
            'holidays.read',
        ],
        'SUPERVISOR' => [
            'employees.read',
            'branches.read',
            'positions.read',
            'schedules.write',
            'attendance.read', 'attendance.record', 'attendance.adjust',
            'overtime.request', 'overtime.authorize', 'overtime.mark_paid',
            'leave.approve', 'leave.create',
            'reports.read', 'reports.export',
            'time_calculation.read',
            'holidays.read',
        ],
        'ACCOUNTANT' => [
            'employees.read',
            'branches.read',
            'positions.read',
            'contracts.read',
            'payroll.read',
            'social_security.manage',
            'reports.read', 'reports.export',
            'time_calculation.read',
            'holidays.read',
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
