<?php

namespace App\Support;

use App\Models\User;
use Spatie\Permission\Models\Role;

class CapabilityRegistry
{
    /** @return array<string, array<string, mixed>> */
    public function definitions(): array
    {
        return config('capabilities.permissions', []);
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_keys($this->definitions());
    }

    /** @return array<string, mixed>|null */
    public function definition(string $name): ?array
    {
        $definition = $this->definitions()[$name] ?? null;

        return is_array($definition) ? $definition : null;
    }

    /**
     * Whether the capability can only be exercised once the holder's department
     * owns a program. Declared per capability in config/capabilities.php.
     */
    public function requiresProgram(string $name): bool
    {
        return (bool) ($this->definition($name)['requires_program'] ?? false);
    }

    /**
     * The capabilities this one cannot be exercised without, declared as
     * `requires` in config/capabilities.php.
     *
     * @return list<string>
     */
    public function prerequisites(string $name): array
    {
        $requires = $this->definition($name)['requires'] ?? [];

        return is_array($requires) ? array_values($requires) : [];
    }

    /**
     * The given grant plus every capability it depends on, transitively.
     *
     * Saving a grant through this is what keeps `schedule.view` from being
     * dropped out from under the screens that read the timetable. Unknown
     * names pass through untouched so validating them stays the caller's job,
     * and the original order is kept with prerequisites appended.
     *
     * @param  iterable<string>  $names
     * @return list<string>
     */
    public function expand(iterable $names): array
    {
        $resolved = [];
        $queue = [];
        foreach ($names as $name) {
            $queue[] = (string) $name;
        }

        while ($queue !== []) {
            $name = array_shift($queue);
            if (isset($resolved[$name])) {
                continue;
            }
            $resolved[$name] = true;
            foreach ($this->prerequisites($name) as $prerequisite) {
                $queue[] = $prerequisite;
            }
        }

        return array_keys($resolved);
    }

    /**
     * The direct grants to store for a role: the requested names plus the
     * prerequisites the role does not already provide.
     *
     * A dean inherits `schedule.view`, so granting a dean `schedule.create`
     * used to store a second, direct copy of it. Names the VPAA asked for
     * explicitly are always kept; only prerequisites pulled in on their behalf
     * are dropped when the role covers them. Call it again whenever the role
     * changes, so a prerequisite the old role supplied is stored for the new one.
     *
     * @param  iterable<string>  $names
     * @return list<string>
     */
    public function expandForRole(iterable $names, string $role): array
    {
        $requested = [];
        foreach ($names as $name) {
            $requested[(string) $name] = true;
        }
        $fromRole = array_fill_keys($this->rolePermissions($role), true);

        return array_values(array_filter(
            $this->expand(array_keys($requested)),
            fn (string $name): bool => isset($requested[$name]) || ! isset($fromRole[$name]),
        ));
    }

    /**
     * What the stored role grants, read from the database rather than config so
     * a role that was re-synced by hand is still answered truthfully.
     *
     * @return list<string>
     */
    public function rolePermissions(string $role): array
    {
        $model = Role::query()->where('name', strtolower($role))->where('guard_name', 'api')->first();

        return $model?->permissions->pluck('name')->values()->all() ?? [];
    }

    public function isAssignableTo(User $user, string $name): bool
    {
        return $this->isAssignableToRole((string) $user->role, $name);
    }

    public function isAssignableToRole(string $role, string $name): bool
    {
        $definition = $this->definition($name);
        $allowedRoles = $definition['allowed_roles'] ?? null;

        return ! is_array($allowedRoles) || in_array(strtolower($role), $allowedRoles, true);
    }

    /** @return list<array<string, mixed>> */
    public function catalogFor(User $user): array
    {
        return $this->catalogForRole((string) $user->role);
    }

    /**
     * The capability catalog as it applies to a role rather than a saved user.
     *
     * Assignability is a property of the role, so this answers it without
     * needing a saved account.
     *
     * @return list<array<string, mixed>>
     */
    public function catalogForRole(string $role): array
    {
        return array_map(function (string $name, array $definition) use ($role): array {
            return [
                'id' => $name,
                'module' => $definition['module'],
                'title' => $definition['title'],
                'description' => $definition['description'],
                'assignable' => $this->isAssignableToRole($role, $name),
                'requires_program' => $this->requiresProgram($name),
                'requires' => $this->prerequisites($name),
            ];
        }, array_keys($this->definitions()), array_values($this->definitions()));
    }

    /** @return list<array<string, mixed>> */
    public function modulesFor(User $user): array
    {
        return $this->modulesForRole((string) $user->role, $user->capabilityNames());
    }

    /**
     * The module tree for a role, marking which capabilities are already held.
     *
     * @param  list<string>  $grantedCapabilities
     * @return list<array<string, mixed>>
     */
    public function modulesForRole(string $role, array $grantedCapabilities = []): array
    {
        $effective = array_fill_keys($grantedCapabilities, true);
        $modules = config('capabilities.modules', []);

        return array_map(function (string $id, array $module) use ($effective, $role): array {
            $capabilities = collect($this->definitions())
                ->filter(fn (array $definition): bool => ($definition['module'] ?? null) === $id)
                ->map(function (array $definition, string $permission) use ($effective, $role): array {
                    return [
                        'id' => $permission,
                        'title' => $definition['title'],
                        'description' => $definition['description'],
                        'granted' => isset($effective[$permission]),
                        'assignable' => $this->isAssignableToRole($role, $permission),
                        'requires' => $this->prerequisites($permission),
                    ];
                })
                ->values()
                ->all();

            return [
                'id' => $id,
                'title' => $module['title'],
                'description' => $module['description'],
                'capabilities' => $capabilities,
                'granted' => collect($capabilities)->contains('granted', true),
            ];
        }, array_keys($modules), array_values($modules));
    }

    /** @return array<string, list<string>> */
    public function roleDefaults(): array
    {
        return config('capabilities.role_defaults', []);
    }

    /** @return array<string, array{label: string, permissions: list<string>}> */
    public function presets(): array
    {
        return config('capabilities.presets', []);
    }
}
