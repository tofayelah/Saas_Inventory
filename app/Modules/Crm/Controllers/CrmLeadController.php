<?php

namespace App\Modules\Crm\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Membership;
use App\Models\Warehouse;
use App\Modules\Crm\Models\CrmActivity;
use App\Modules\Crm\Models\CrmLead;
use App\Modules\Crm\Requests\Lead\ConvertLeadRequest;
use App\Modules\Crm\Requests\Lead\StoreLeadActivityRequest;
use App\Modules\Crm\Requests\Lead\StoreLeadRequest;
use App\Modules\Crm\Requests\Lead\UpdateLeadRequest;
use App\Modules\Crm\Services\LeadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class CrmLeadController extends Controller
{
    public function __construct(
        protected LeadService $leadService
    ) {}

    /**
     * Display a paginated listing of leads with filters, search, and KPI metrics.
     */
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', CrmLead::class);

        $user = Auth::user();
        $teamId = $user->current_team_id;

        // Resolve branch membership restriction
        $membership = Membership::where('team_id', $teamId)
            ->where('user_id', $user->id)
            ->first();

        $isOwnerOrTenantWide = $user->ownsTeam($user->currentTeam) ||
            $user->isSuperAdmin() ||
            $user->can('crm.leads.view_all');

        $query = CrmLead::where('team_id', $teamId)
            ->with([
                'assignedUser:id,name,email',
                'warehouse:id,name',
                'convertedCustomer:id,name,phone,email',
            ]);

        // Branch-scoping invariant: if restricted to a branch and not tenant-wide
        if (!$isOwnerOrTenantWide && $membership && $membership->warehouse_id) {
            $query->where('warehouse_id', $membership->warehouse_id);
        }

        // Assigned-user invariant: if user only has permission to view assigned leads
        if (!$isOwnerOrTenantWide && !$user->can('crm.leads.view_branch') && $user->can('crm.leads.view_assigned')) {
            $query->where('assigned_user_id', $user->id);
        }

        // Filters
        $search = $request->input('search');
        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('lead_number', 'like', "%{$search}%")
                    ->orWhere('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")
                    ->orWhere('company_name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        $status = $request->input('status');
        if ($status && $status !== 'all') {
            $query->where('status', $status);
        }

        $source = $request->input('source');
        if ($source && $source !== 'all') {
            $query->where('source', $source);
        }

        $warehouseId = $request->input('warehouse_id');
        if ($warehouseId && $warehouseId !== 'all') {
            // Respect branch restriction if not owner/tenant-wide
            if ($isOwnerOrTenantWide || ($membership && (int) $membership->warehouse_id === (int) $warehouseId)) {
                $query->where('warehouse_id', (int) $warehouseId);
            }
        }

        $assignedUserId = $request->input('assigned_user_id');
        if ($assignedUserId && $assignedUserId !== 'all') {
            $query->where('assigned_user_id', (int) $assignedUserId);
        }

        $leads = $query->latest('id')
            ->paginate(15)
            ->withQueryString();

        // KPI metrics
        $kpiWarehouseId = (!$isOwnerOrTenantWide && $membership && $membership->warehouse_id)
            ? (int) $membership->warehouse_id
            : ($warehouseId && $warehouseId !== 'all' ? (int) $warehouseId : null);

        $kpiAssignedUserId = (!$isOwnerOrTenantWide && !$user->can('crm.leads.view_branch') && $user->can('crm.leads.view_assigned'))
            ? (int) $user->id
            : ($assignedUserId && $assignedUserId !== 'all' ? (int) $assignedUserId : null);

        $kpis = $this->leadService->getLeadKpis($teamId, $kpiWarehouseId, $kpiAssignedUserId);

        // Dropdown data
        $warehouses = Warehouse::where('team_id', $teamId)
            ->where('is_active', true)
            ->get(['id', 'name', 'is_default']);

        $teamUsers = $user->currentTeam ? $user->currentTeam->allUsers() : collect([$user]);
        $salespersons = $teamUsers->map(fn ($u) => [
            'id' => $u->id,
            'name' => $u->name,
            'email' => $u->email,
        ]);

        return Inertia::render('Crm/Leads/Index', [
            'leads' => $leads,
            'filters' => [
                'search' => $search ?? '',
                'status' => $status ?? 'all',
                'source' => $source ?? 'all',
                'warehouse_id' => $warehouseId ?? 'all',
                'assigned_user_id' => $assignedUserId ?? 'all',
            ],
            'kpis' => $kpis,
            'warehouses' => $warehouses,
            'salespersons' => $salespersons,
        ]);
    }

    /**
     * Show the form for creating a new lead.
     */
    public function create(): Response
    {
        $this->authorize('create', CrmLead::class);

        $user = Auth::user();
        $teamId = $user->current_team_id;

        $membership = Membership::where('team_id', $teamId)
            ->where('user_id', $user->id)
            ->first();

        $warehouses = Warehouse::where('team_id', $teamId)
            ->where('is_active', true)
            ->get(['id', 'name', 'is_default']);

        $teamUsers = $user->currentTeam ? $user->currentTeam->allUsers() : collect([$user]);
        $salespersons = $teamUsers->map(fn ($u) => [
            'id' => $u->id,
            'name' => $u->name,
            'email' => $u->email,
        ]);

        $defaultWarehouseId = $membership?->warehouse_id
            ?? $warehouses->firstWhere('is_default', true)?->id
            ?? $warehouses->first()?->id;

        return Inertia::render('Crm/Leads/Create', [
            'warehouses' => $warehouses,
            'salespersons' => $salespersons,
            'defaultWarehouseId' => $defaultWarehouseId,
        ]);
    }

    /**
     * Store a newly created lead in storage.
     */
    public function store(StoreLeadRequest $request): RedirectResponse
    {
        $user = Auth::user();
        $teamId = $user->current_team_id;

        $lead = $this->leadService->createLead($teamId, $request->validated());

        return redirect()->route('crm.leads.show', $lead->id)
            ->with('success', "Lead {$lead->lead_number} created successfully.");
    }

    /**
     * Display the specified lead details and activity timeline.
     */
    public function show(CrmLead $lead): Response
    {
        $this->authorize('view', $lead);

        $user = Auth::user();
        $teamId = $user->current_team_id;

        $lead->load([
            'assignedUser:id,name,email',
            'warehouse:id,name',
            'convertedCustomer:id,name,phone,email,company_name',
            'convertedDeal:id,title,stage_id,deal_value',
            'activities' => fn ($q) => $q->with('assignedUser:id,name,email')->latest('id'),
        ]);

        // Duplicate detection
        $duplicates = $this->leadService->checkDuplicates($teamId, $lead->phone, $lead->email, $lead->id);

        $warehouses = Warehouse::where('team_id', $teamId)
            ->where('is_active', true)
            ->get(['id', 'name', 'is_default']);

        $teamUsers = $user->currentTeam ? $user->currentTeam->allUsers() : collect([$user]);
        $salespersons = $teamUsers->map(fn ($u) => [
            'id' => $u->id,
            'name' => $u->name,
            'email' => $u->email,
        ]);

        return Inertia::render('Crm/Leads/Show', [
            'lead' => $lead,
            'duplicates' => $duplicates,
            'warehouses' => $warehouses,
            'salespersons' => $salespersons,
        ]);
    }

    /**
     * Show the form for editing the specified lead.
     */
    public function edit(CrmLead $lead): Response
    {
        $this->authorize('update', $lead);

        $user = Auth::user();
        $teamId = $user->current_team_id;

        $warehouses = Warehouse::where('team_id', $teamId)
            ->where('is_active', true)
            ->get(['id', 'name', 'is_default']);

        $teamUsers = $user->currentTeam ? $user->currentTeam->allUsers() : collect([$user]);
        $salespersons = $teamUsers->map(fn ($u) => [
            'id' => $u->id,
            'name' => $u->name,
            'email' => $u->email,
        ]);

        return Inertia::render('Crm/Leads/Edit', [
            'lead' => $lead,
            'warehouses' => $warehouses,
            'salespersons' => $salespersons,
        ]);
    }

    /**
     * Update the specified lead in storage.
     */
    public function update(UpdateLeadRequest $request, CrmLead $lead): RedirectResponse
    {
        $user = Auth::user();
        $teamId = $user->current_team_id;

        $this->leadService->updateLead($teamId, $lead, $request->validated());

        return redirect()->route('crm.leads.show', $lead->id)
            ->with('success', "Lead {$lead->lead_number} updated successfully.");
    }

    /**
     * Remove the specified lead from storage.
     */
    public function destroy(CrmLead $lead): RedirectResponse
    {
        $this->authorize('delete', $lead);

        $user = Auth::user();
        $teamId = $user->current_team_id;

        // Controller-level safeguard against converted lead deletion
        if ($lead->status === 'converted' || $lead->converted_customer_id !== null) {
            abort(422, "Converted lead [{$lead->lead_number}] cannot be deleted to preserve canonical customer provenance and audit records.");
        }

        $leadNumber = $lead->lead_number;
        $this->leadService->deleteLead($teamId, $lead);

        return redirect()->route('crm.leads.index')
            ->with('success', "Lead {$leadNumber} deleted successfully.");
    }

    /**
     * Convert the lead to an ERP Customer.
     */
    public function convert(ConvertLeadRequest $request, CrmLead $lead): RedirectResponse
    {
        $user = Auth::user();
        $teamId = $user->current_team_id;

        $customer = $this->leadService->convertLeadToCustomer($teamId, $lead->id, $request->validated());

        return redirect()->route('crm.leads.show', $lead->id)
            ->with('success', "Lead {$lead->lead_number} successfully converted to customer [{$customer->name}].");
    }

    /**
     * Store a new activity attached to the lead.
     */
    public function storeActivity(StoreLeadActivityRequest $request, CrmLead $lead): RedirectResponse
    {
        $user = Auth::user();
        $teamId = $user->current_team_id;

        $this->leadService->addActivity($teamId, $lead, $request->validated());

        return redirect()->route('crm.leads.show', $lead->id)
            ->with('success', "Activity recorded successfully.");
    }

    /**
     * Toggle activity completion.
     */
    public function toggleActivity(CrmLead $lead, CrmActivity $activity): RedirectResponse
    {
        $this->authorize('update', $lead);

        $user = Auth::user();
        $teamId = $user->current_team_id;

        if ((int) $activity->related_id !== (int) $lead->id || $activity->related_type !== 'lead') {
            abort(404, "Activity does not belong to this lead.");
        }

        $this->leadService->toggleActivityCompletion($teamId, $activity);

        return redirect()->route('crm.leads.show', $lead->id)
            ->with('success', "Activity status updated.");
    }

    /**
     * Async duplicate check endpoint.
     */
    public function checkDuplicates(Request $request): JsonResponse
    {
        $this->authorize('viewAny', CrmLead::class);

        $teamId = Auth::user()->current_team_id;
        $phone = $request->query('phone');
        $email = $request->query('email');
        $excludeId = $request->query('exclude_id') ? (int) $request->query('exclude_id') : null;

        $results = $this->leadService->checkDuplicates($teamId, $phone, $email, $excludeId);

        return response()->json($results);
    }
}
