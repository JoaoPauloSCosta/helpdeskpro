<?php

use App\Models\Role;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;

test('the migration grants audit.view to an existing technician role once', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $role = Role::where('slug', 'technician')->first();
    $permissionId = DB::table('permissions')->where('slug', 'audit.view')->value('id');
    $role->permissions()->detach($permissionId);

    $migration = require database_path('migrations/2026_10_24_000001_grant_audit_view_to_technician_role.php');
    $migration->up();
    $migration->up();

    expect(DB::table('role_permissions')->where(['role_id' => $role->id, 'permission_id' => $permissionId])->count())->toBe(1);
});

test('the migration does nothing without roles or permissions', function () {
    $migration = require database_path('migrations/2026_10_24_000001_grant_audit_view_to_technician_role.php');
    $migration->up();

    expect(DB::table('role_permissions')->count())->toBe(0);
});
