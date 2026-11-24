<?php

namespace App\Http\Controllers;

use App\Models\Team;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\SubscriptionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Laravel\Jetstream\Jetstream;

class TeamMemberController extends Controller
{
    /**
     * Display the team members and roles management page.
     */
    public function index(Request $request, ?Team $team = null)
    {
        $user = $request->user();

        // Resolve active team
        $activeTeam = null;
        if ($team && $team->exists) {
            $activeTeam = $team;
        } elseif ($user->currentTeam) {
            $activeTeam = $user->currentTeam;
        } else {
            $activeTeam = Team::first();
        }

        if (!$activeTeam) {
            abort(404, 'No active tenant workspace found.');
        }

        // Authorize viewing the team
        if (!$user->isSuperAdmin() && !$user->belongsToTeam($activeTeam)) {
            abort(403, 'Unauthorized access to this workspace.');
        }

        $activeTeam->load(['owner', 'users']);

        // Load active team branches / warehouses
        $warehouses = Warehouse::where('team_id', $activeTeam->id)
            ->where('is_active', true)
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get(['id', 'name', 'code', 'type', 'is_default']);

        // Format members list
        $members = collect();

        // 1. Add Owner first
        if ($activeTeam->owner) {
            $ownerWh = $activeTeam->owner->warehouse_id ? $warehouses->firstWhere('id', $activeTeam->owner->warehouse_id) : null;

            $members->push([
                'id' => $activeTeam->owner->id,
                'name' => $activeTeam->owner->name,
                'email' => $activeTeam->owner->email,
                'role' => 'Owner',
                'role_key' => 'owner',
                'is_owner' => true,
                'warehouse_id' => $activeTeam->owner->warehouse_id,
                'warehouse_name' => $ownerWh?->name ?? 'All Branches (Company-wide)',
                'warehouse_type' => $ownerWh?->type ?? null,
                'joined_at' => $activeTeam->created_at ? $activeTeam->created_at->format('M d, Y') : 'Original Owner',
            ]);
        }

        // 2. Add other team members
        foreach ($activeTeam->users as $member) {
            $roleKey = $member->membership->role ?? 'staff';
            $roleTitle = match ($roleKey) {
                'admin' => 'Administrator',
                'staff', 'editor' => 'Staff / Operations',
                'viewer' => 'Viewer',
                default => ucfirst($roleKey),
            };
            $assignedWhId = $member->membership->warehouse_id;
            $assignedWh = $assignedWhId ? $warehouses->firstWhere('id', $assignedWhId) : null;

            $members->push([
                'id' => $member->id,
                'name' => $member->name,
                'email' => $member->email,
                'role' => $roleTitle,
                'role_key' => $roleKey,
                'is_owner' => false,
                'warehouse_id' => $assignedWhId,
                'warehouse_name' => $assignedWh?->name ?? 'All Branches (Default)',
                'warehouse_type' => $assignedWh?->type ?? null,
                'joined_at' => $member->membership->created_at ? $member->membership->created_at->format('M d, Y') : 'N/A',
            ]);
        }

        $canManage = $user->isSuperAdmin() || $user->ownsTeam($activeTeam);

        // Defined system roles & permission descriptions
        $availableRoles = [
            [
                'key' => 'admin',
                'name' => 'Administrator',
                'badge' => 'Management Access',
                'description' => 'Can manage inventory, products, warehouses, orders, accounting, HR, and reports (excluding billing & team ownership).',
                'permissions' => [
                    'Manage Products & Inventory',
                    'Manage Warehouses & Outlets',
                    'Process Sales & Invoicing',
                    'Manage Purchase Orders & Vendors',
                    'Double-Entry Accounting & Ledger',
                    'HR, Payroll & Expenses',
                    'View Business & Financial Reports',
                ],
            ],
            [
                'key' => 'staff',
                'name' => 'Staff / Operations',
                'badge' => 'Operational',
                'description' => 'Day-to-day operations: creating orders, issuing challans, receiving stock, and tickets.',
                'permissions' => [
                    'Process Sales Orders & POS',
                    'Receive Stock & Handle Warranties',
                    'Handle Workshop Service Tickets',
                    'Issue Delivery Challans',
                    'Restricted from Products/Warehouses CRUD',
                    'Restricted from Accounting & Payroll',
                ],
            ],
            [
                'key' => 'viewer',
                'name' => 'Viewer',
                'badge' => 'Read Only',
                'description' => 'Read-only access for auditors and reviewers to inspect reports and dashboards.',
                'permissions' => [
                    'View Sales & Stock Reports',
                    'View Accounting & Trial Balance',
                    'Restricted from All Mutations',
                ],
            ],
        ];

        return Inertia::render('Settings/Team', [
            'team' => [
                'id' => $activeTeam->id,
                'name' => $activeTeam->name,
                'owner_name' => $activeTeam->owner?->name ?? 'System',
                'owner_email' => $activeTeam->owner?->email ?? '',
                'total_members' => $members->count(),
                'created_at' => $activeTeam->created_at ? $activeTeam->created_at->format('M d, Y') : '',
            ],
            'members' => $members,
            'warehouses' => $warehouses,
            'availableRoles' => $availableRoles,
            'canManage' => $canManage,
            'currentUserId' => $user->id,
        ]);
    }

    /**
     * Add a new member to the current team.
     */
    public function addMember(Request $request, SubscriptionService $subscriptionService)
    {
        $user = $request->user();
        $team = $user->currentTeam ?? Team::firstOrFail();

        if (!$user->isSuperAdmin() && !$user->ownsTeam($team)) {
            abort(403, 'Only the organization owner or super admin can add team members.');
        }

        if (!$subscriptionService->canAddUser($team)) {
            return back()->with('error', 'You have reached the maximum user limit for your subscription plan. Please upgrade your plan.');
        }

        $validated = $request->validate([
            'email' => 'required|email|max:255',
            'role' => 'required|string|in:admin,editor,staff,viewer',
            'name' => 'nullable|string|max:255',
            'warehouse_id' => ['nullable', Rule::exists('warehouses', 'id')->where('team_id', $team->id)],
        ]);

        $email = strtolower(trim($validated['email']));
        $rawRole = $validated['role'];
        $spatieRole = $rawRole === 'editor' ? 'staff' : $rawRole;

        // Check if user is already on the team
        if ($team->owner && strtolower($team->owner->email) === $email) {
            return back()->with('error', 'This user is already the owner of this organization.');
        }

        if ($team->users()->where('email', $email)->exists()) {
            return back()->with('error', 'This user is already a member of this team.');
        }

        // Find or create user
        $memberUser = User::where('email', $email)->first();

        $tempPassword = null;

        if (!$memberUser) {
            $name = $validated['name'] ?: explode('@', $email)[0];
            $tempPassword = Str::random(10);
            $memberUser = User::create([
                'name' => ucwords(str_replace(['.', '_', '-'], ' ', $name)),
                'email' => $email,
                'password' => Hash::make($tempPassword),
                'current_team_id' => $team->id,
                'warehouse_id' => $validated['warehouse_id'] ?? null,
            ]);
        }

        // Attach to team with role & branch assignment
        $team->users()->attach($memberUser->id, [
            'role' => $rawRole,
            'warehouse_id' => $validated['warehouse_id'] ?? null,
        ]);

        if ($memberUser->current_team_id === null) {
            $memberUser->forceFill(['current_team_id' => $team->id])->save();
        }

        // Sync Spatie role & invalidate permission caches
        \Database\Seeders\RolesAndPermissionsSeeder::seedRolesForTeam($team->id);
        setPermissionsTeamId($team->id);
        $memberUser->syncRoles([$spatieRole]);
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
        \Illuminate\Support\Facades\Cache::forget("user_auth_rp_{$memberUser->id}_{$team->id}");

        $successMsg = $tempPassword
            ? "New user {$memberUser->name} invited to {$team->name}. Temporary login password: {$tempPassword}"
            : "Member {$memberUser->name} successfully added to {$team->name}.";

        return back()->with('success', $successMsg);
    }

    /**
     * Update a team member's role and branch assignment.
     */
    public function updateRole(Request $request, User $user)
    {
        $currentUser = $request->user();
        $team = $currentUser->currentTeam ?? Team::firstOrFail();

        if (!$currentUser->isSuperAdmin() && !$currentUser->ownsTeam($team)) {
            abort(403, 'Only the organization owner or super admin can update roles.');
        }

        $validated = $request->validate([
            'role' => 'required|string|in:admin,editor,staff,viewer',
            'warehouse_id' => ['nullable', Rule::exists('warehouses', 'id')->where('team_id', $team->id)],
        ]);

        // If updating the organization owner's assigned branch
        if ($team->owner && $team->owner->id === $user->id) {
            $user->update(['warehouse_id' => $validated['warehouse_id'] ?? null]);
            return back()->with('success', "Updated branch assignment for {$user->name}.");
        }

        if (!$team->users()->where('users.id', $user->id)->exists()) {
            return back()->with('error', 'This user is not a member of this team.');
        }

        $rawRole = $validated['role'];
        $spatieRole = $rawRole === 'editor' ? 'staff' : $rawRole;

        $team->users()->updateExistingPivot($user->id, [
            'role' => $rawRole,
            'warehouse_id' => $validated['warehouse_id'] ?? null,
        ]);

        // Sync Spatie role & invalidate permission caches
        \Database\Seeders\RolesAndPermissionsSeeder::seedRolesForTeam($team->id);
        setPermissionsTeamId($team->id);
        $user->syncRoles([$spatieRole]);
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
        \Illuminate\Support\Facades\Cache::forget("user_auth_rp_{$user->id}_{$team->id}");

        return back()->with('success', "Updated role and branch assignment for {$user->name}.");
    }

    /**
     * Remove a member from the team.
     */
    public function removeMember(Request $request, User $user)
    {
        $currentUser = $request->user();
        $team = $currentUser->currentTeam ?? Team::firstOrFail();

        $canRemove = $currentUser->isSuperAdmin() || $currentUser->ownsTeam($team) || $currentUser->id === $user->id;

        if (!$canRemove) {
            abort(403, 'Unauthorized to remove this member.');
        }

        if ($team->owner && $team->owner->id === $user->id) {
            return back()->with('error', 'The team owner cannot be removed.');
        }

        if (!$team->users()->where('users.id', $user->id)->exists()) {
            return back()->with('error', 'This user is not a member of this team.');
        }

        $team->users()->detach($user->id);

        // Remove Spatie roles & invalidate permission caches
        setPermissionsTeamId($team->id);
        $user->syncRoles([]);
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
        \Illuminate\Support\Facades\Cache::forget("user_auth_rp_{$user->id}_{$team->id}");

        if ($user->current_team_id === $team->id) {
            $user->forceFill(['current_team_id' => $user->personalTeam()?->id])->save();
        }

        return back()->with('success', "{$user->name} was removed from the team.");
    }
}
