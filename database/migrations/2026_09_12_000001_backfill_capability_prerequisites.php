<?php

use App\Models\User;
use App\Support\CapabilityRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

/**
 * Grants the prerequisites that existing accounts are missing.
 *
 * `requires` in config/capabilities.php is enforced when a grant is saved, but
 * accounts saved before it existed can hold a capability without the reads it
 * depends on. A secretary granted instructor assignment alone could open the
 * Cross-Department page and the dashboard -- and then watch both fail, because
 * every endpoint behind them is guarded by `schedule.view`.
 *
 * Only prerequisites are added; nothing is taken away, so an account that was
 * deliberately narrowed keeps its shape.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('permissions') || ! Schema::hasTable('model_has_permissions')) {
            return;
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $registry = app(CapabilityRegistry::class);

        User::query()->each(function (User $user) use ($registry): void {
            $direct = $user->getDirectPermissions()->pluck('name')->values()->all();
            if ($direct === []) {
                return;
            }

            $expanded = $registry->expand($direct);
            if (array_diff($expanded, $direct) === []) {
                return;
            }

            $user->syncPermissions($expanded);
        });
    }

    /**
     * Prerequisites are indistinguishable from grants the VPAA made on purpose,
     * so there is nothing safe to take back.
     */
    public function down(): void {}
};
