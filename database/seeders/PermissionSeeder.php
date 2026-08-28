<?php

namespace Database\Seeders;

use App\Models\Permission;
use Illuminate\Database\Seeder;

/**
 * Seeds the atomic permission catalog defined in .ai/06-AUTHORIZATION.md.
 */
class PermissionSeeder extends Seeder
{
    /**
     * @var array<string, list<string>>
     */
    private const CATALOG = [
        'companies' => ['companies.manage'],
        'auth' => ['users.manage', 'roles.manage'],
        'employees' => ['employees.read', 'employees.create', 'employees.update'],
        'branches' => ['branches.read', 'branches.write'],
        'positions' => ['positions.read', 'positions.write'],
        'contracts' => ['contracts.read', 'contracts.write'],
        'schedules' => ['schedules.write'],
        'attendance' => ['attendance.read', 'attendance.adjust', 'attendance.approve_adjustment'],
        'overtime' => ['overtime.request', 'overtime.authorize'],
        'leave' => ['leave.approve'],
        'payroll' => [
            'payroll.read',
            'payroll.calculate',
            'payroll.approve',
            'payroll.close',
            'payroll.reopen',
            'payroll.adjust',
        ],
        'social_security' => ['social_security.manage'],
        'reports' => ['reports.read', 'reports.export'],
        'devices' => ['devices.manage'],
        'biometrics' => ['biometrics.enroll', 'biometrics.delete_data'],
        'audit' => ['audit.read'],
        'settings' => ['settings.manage'],
    ];

    public function run(): void
    {
        foreach (self::CATALOG as $module => $codes) {
            foreach ($codes as $code) {
                Permission::query()->updateOrCreate(
                    ['code' => $code],
                    ['module' => $module],
                );
            }
        }
    }
}
