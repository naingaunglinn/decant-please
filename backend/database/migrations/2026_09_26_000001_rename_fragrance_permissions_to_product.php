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
 * role keeps exactly the access it had. Where the new name already exists, the
 * grants are moved onto it instead. Idempotent both ways.
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
        $tables = config('permission.table_names');
        $pivot = config('permission.column_names.permission_pivot_key') ?? 'permission_id';

        DB::table($tables['permissions'])->where('name', 'like', "%{$from}")->get(['id', 'name', 'guard_name'])
            ->each(function (object $permission) use ($tables, $pivot, $from, $to) {
                $name = substr($permission->name, 0, -strlen($from)).$to;
                $target = DB::table($tables['permissions'])
                    ->where('name', $name)->where('guard_name', $permission->guard_name)->value('id');

                if ($target === null) {
                    DB::table($tables['permissions'])->where('id', $permission->id)->update(['name' => $name]);

                    return;
                }

                // The target already exists (a dev `shield:generate` made it): move
                // every grant onto it, then drop the old row, so no role loses access.
                foreach (DB::table($tables['role_has_permissions'])->where($pivot, $permission->id)->get() as $grant) {
                    DB::table($tables['role_has_permissions'])->insertOrIgnore([$pivot => $target] + array_diff_key((array) $grant, [$pivot => true]));
                }
                foreach (DB::table($tables['model_has_permissions'])->where($pivot, $permission->id)->get() as $grant) {
                    DB::table($tables['model_has_permissions'])->insertOrIgnore([$pivot => $target] + array_diff_key((array) $grant, [$pivot => true]));
                }
                DB::table($tables['permissions'])->where('id', $permission->id)->delete();
            });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
