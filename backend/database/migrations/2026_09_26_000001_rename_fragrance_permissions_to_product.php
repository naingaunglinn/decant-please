<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Step 36 renames the Fragrance model to Product, so its policy now checks
 * `{Ability}:Product`. The shipped Shield seed (2026_08_22) stored
 * `{Ability}:Fragrance` rows; without this rename every shop owner and staff
 * member would lose the catalog the moment the code deploys.
 *
 * A rename, not new rows: role grants point at the permission's id, so every
 * role keeps exactly the access it had. Idempotent both ways.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->rename(':Fragrance', ':Product');
    }

    public function down(): void
    {
        $this->rename(':Product', ':Fragrance');
    }

    private function rename(string $from, string $to): void
    {
        $table = config('permission.table_names.permissions', 'permissions');

        DB::table($table)->where('name', 'like', "%{$from}")->get(['id', 'name', 'guard_name'])
            ->each(function (object $permission) use ($table, $from, $to) {
                $name = substr($permission->name, 0, -strlen($from)).$to;

                // A target row that already exists (a dev `shield:generate`) is
                // left alone rather than tripping the unique (name, guard) index.
                if (DB::table($table)->where('name', $name)->where('guard_name', $permission->guard_name)->exists()) {
                    return;
                }

                DB::table($table)->where('id', $permission->id)->update(['name' => $name]);
            });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
