<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SaveRoleRequest;
use App\Http\Resources\RoleResource;
use App\Models\Role;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

/**
 * Admin-only (routes are behind can:settings.manage).
 */
class RoleController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return RoleResource::collection(
            Role::with('grants')->withCount('users')->orderByDesc('is_admin')->orderBy('name')->get()
        );
    }

    /**
     * The catalogue of permissions an admin can assign, grouped for the UI.
     */
    public function permissions(): JsonResponse
    {
        $groups = collect(config('permissions'))
            ->map(fn (array $meta, string $key) => ['key' => $key, 'label' => $meta['label'], 'group' => $meta['group']])
            ->groupBy('group')
            ->map(fn ($items, $group) => ['group' => $group, 'permissions' => $items->values()])
            ->values();

        return response()->json(['data' => $groups]);
    }

    public function store(SaveRoleRequest $request): JsonResponse
    {
        $role = DB::transaction(function () use ($request) {
            $role = Role::create($request->safe()->only(['name', 'description']));
            $role->syncPermissions($request->validated('permissions'));

            return $role;
        });

        return RoleResource::make($role->loadCount('users'))->response()->setStatusCode(201);
    }

    public function update(SaveRoleRequest $request, Role $role): RoleResource
    {
        abort_if($role->is_admin, 422, 'The Admin role always has full access and cannot be changed.');

        DB::transaction(function () use ($request, $role) {
            $role->update($request->safe()->only(['name', 'description']));
            $role->syncPermissions($request->validated('permissions'));
        });

        return RoleResource::make($role->loadCount('users'));
    }

    public function destroy(Role $role): JsonResponse
    {
        abort_if($role->is_admin, 422, 'The Admin role cannot be deleted.');
        abort_if($role->users()->exists(), 422, 'Move this role\'s users to another role before deleting it.');

        $role->delete();

        return response()->json(['message' => 'Role deleted.']);
    }
}
