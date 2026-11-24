<script setup>
import { ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Pagination from '@/Components/Pagination.vue';
import ActionDropdown from '@/Components/ActionDropdown.vue';
import ConfirmationModal from '@/Components/ConfirmationModal.vue';
import LeadConvertModal from './Partials/LeadConvertModal.vue';
import { formatDate } from '@/Utils/date';

const props = defineProps({
    leads: Object,
    filters: Object,
    kpis: Object,
    warehouses: Array,
    salespersons: Array,
});

const search = ref(props.filters.search || '');
const status = ref(props.filters.status || 'all');
const source = ref(props.filters.source || 'all');
const warehouseId = ref(props.filters.warehouse_id || 'all');
const assignedUserId = ref(props.filters.assigned_user_id || 'all');

const deletingLead = ref(null);
const convertingLead = ref(null);

function applyFilter() {
    const params = {};
    if (search.value) params.search = search.value;
    if (status.value && status.value !== 'all') params.status = status.value;
    if (source.value && source.value !== 'all') params.source = source.value;
    if (warehouseId.value && warehouseId.value !== 'all') params.warehouse_id = warehouseId.value;
    if (assignedUserId.value && assignedUserId.value !== 'all') params.assigned_user_id = assignedUserId.value;

    router.get(route('crm.leads.index'), params, { preserveState: true, replace: true });
}

function setStatusFilter(newStatus) {
    status.value = newStatus;
    applyFilter();
}

function resetFilter() {
    search.value = '';
    status.value = 'all';
    source.value = 'all';
    warehouseId.value = 'all';
    assignedUserId.value = 'all';
    router.get(route('crm.leads.index'), {}, { preserveState: false, replace: true });
}

function confirmDelete(lead) {
    deletingLead.value = lead;
}

function executeDelete() {
    if (!deletingLead.value) return;
    router.delete(route('crm.leads.destroy', deletingLead.value.id), {
        preserveScroll: true,
        onSuccess: () => {
            deletingLead.value = null;
        },
    });
}

function openConvertModal(lead) {
    convertingLead.value = lead;
}

function getStatusBadgeClass(leadStatus) {
    switch (leadStatus) {
        case 'new': return 'bg-sky-50 text-sky-700 border-sky-200';
        case 'contacted': return 'bg-amber-50 text-amber-700 border-amber-200';
        case 'qualified': return 'bg-emerald-50 text-emerald-700 border-emerald-200';
        case 'unqualified': return 'bg-slate-100 text-slate-600 border-slate-200';
        case 'converted': return 'bg-purple-50 text-purple-700 border-purple-200';
        default: return 'bg-slate-50 text-slate-700 border-slate-200';
    }
}

function getSourceBadgeClass(src) {
    switch (src) {
        case 'website': return 'bg-blue-50 text-blue-700 border-blue-200';
        case 'whatsapp': return 'bg-emerald-50 text-emerald-700 border-emerald-200';
        case 'referral': return 'bg-violet-50 text-violet-700 border-violet-200';
        case 'campaign': return 'bg-rose-50 text-rose-700 border-rose-200';
        default: return 'bg-slate-50 text-slate-600 border-slate-200';
    }
}
</script>

<template>
    <AppLayout title="CRM Leads & Prospects" subtitle="Track prospective customers, lead scoring, and pipeline conversion">
        <Head title="CRM Leads & Prospects" />

        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-6">
            <!-- Top Action Header -->
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div>
                    <h2 class="text-xl sm:text-2xl font-black text-slate-900 tracking-tight">Leads & Prospects</h2>
                    <p class="text-xs text-slate-500 mt-0.5">Manage customer acquisition pipeline, qualification, and sales outreach.</p>
                </div>
                <Link
                    :href="route('crm.leads.create')"
                    class="inline-flex items-center justify-center gap-2 px-4 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white font-bold text-xs shadow-sm shadow-indigo-600/30 transition hover:scale-[1.02] active:scale-[0.98]"
                >
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                    </svg>
                    <span>Create Lead</span>
                </Link>
            </div>

            <!-- KPI Metric Cards -->
            <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3 sm:gap-4">
                <div class="p-4 rounded-2xl bg-white border border-slate-200/80 shadow-xs flex flex-col justify-between">
                    <span class="text-[11px] font-bold text-slate-400 uppercase tracking-wider">Total Leads</span>
                    <span class="text-2xl font-black text-slate-900 mt-2">{{ kpis.total }}</span>
                </div>

                <div class="p-4 rounded-2xl bg-white border border-slate-200/80 shadow-xs flex flex-col justify-between">
                    <span class="text-[11px] font-bold text-sky-600 uppercase tracking-wider">New</span>
                    <span class="text-2xl font-black text-sky-700 mt-2">{{ kpis.new }}</span>
                </div>

                <div class="p-4 rounded-2xl bg-white border border-slate-200/80 shadow-xs flex flex-col justify-between">
                    <span class="text-[11px] font-bold text-amber-600 uppercase tracking-wider">Contacted</span>
                    <span class="text-2xl font-black text-amber-700 mt-2">{{ kpis.contacted }}</span>
                </div>

                <div class="p-4 rounded-2xl bg-white border border-slate-200/80 shadow-xs flex flex-col justify-between">
                    <span class="text-[11px] font-bold text-emerald-600 uppercase tracking-wider">Qualified</span>
                    <span class="text-2xl font-black text-emerald-700 mt-2">{{ kpis.qualified }}</span>
                </div>

                <div class="p-4 rounded-2xl bg-white border border-slate-200/80 shadow-xs flex flex-col justify-between">
                    <span class="text-[11px] font-bold text-purple-600 uppercase tracking-wider">Converted</span>
                    <span class="text-2xl font-black text-purple-700 mt-2">{{ kpis.converted }}</span>
                </div>

                <div class="p-4 rounded-2xl bg-white border border-slate-200/80 shadow-xs flex flex-col justify-between">
                    <span class="text-[11px] font-bold text-rose-600 uppercase tracking-wider">Overdue Tasks</span>
                    <span class="text-2xl font-black text-rose-700 mt-2">{{ kpis.overdue_activities }}</span>
                </div>
            </div>

            <!-- Filters & Search Toolbar -->
            <div class="bg-white rounded-2xl border border-slate-200/80 shadow-xs p-4 space-y-3">
                <!-- Status Filter Pills -->
                <div class="flex items-center gap-1.5 overflow-x-auto pb-1 text-xs">
                    <button
                        type="button"
                        @click="setStatusFilter('all')"
                        class="px-3 py-1.5 rounded-xl font-semibold transition shrink-0"
                        :class="status === 'all' ? 'bg-slate-900 text-white shadow-2xs' : 'text-slate-600 hover:bg-slate-100'"
                    >
                        All Leads ({{ kpis.total }})
                    </button>
                    <button
                        type="button"
                        @click="setStatusFilter('new')"
                        class="px-3 py-1.5 rounded-xl font-semibold transition shrink-0"
                        :class="status === 'new' ? 'bg-sky-600 text-white shadow-2xs' : 'text-slate-600 hover:bg-slate-100'"
                    >
                        New ({{ kpis.new }})
                    </button>
                    <button
                        type="button"
                        @click="setStatusFilter('contacted')"
                        class="px-3 py-1.5 rounded-xl font-semibold transition shrink-0"
                        :class="status === 'contacted' ? 'bg-amber-600 text-white shadow-2xs' : 'text-slate-600 hover:bg-slate-100'"
                    >
                        Contacted ({{ kpis.contacted }})
                    </button>
                    <button
                        type="button"
                        @click="setStatusFilter('qualified')"
                        class="px-3 py-1.5 rounded-xl font-semibold transition shrink-0"
                        :class="status === 'qualified' ? 'bg-emerald-600 text-white shadow-2xs' : 'text-slate-600 hover:bg-slate-100'"
                    >
                        Qualified ({{ kpis.qualified }})
                    </button>
                    <button
                        type="button"
                        @click="setStatusFilter('converted')"
                        class="px-3 py-1.5 rounded-xl font-semibold transition shrink-0"
                        :class="status === 'converted' ? 'bg-purple-600 text-white shadow-2xs' : 'text-slate-600 hover:bg-slate-100'"
                    >
                        Converted ({{ kpis.converted }})
                    </button>
                    <button
                        type="button"
                        @click="setStatusFilter('unqualified')"
                        class="px-3 py-1.5 rounded-xl font-semibold transition shrink-0"
                        :class="status === 'unqualified' ? 'bg-slate-600 text-white shadow-2xs' : 'text-slate-600 hover:bg-slate-100'"
                    >
                        Unqualified ({{ kpis.unqualified }})
                    </button>
                </div>

                <!-- Secondary Filters -->
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 pt-2 border-t border-slate-100">
                    <div>
                        <input
                            v-model="search"
                            @keyup.enter="applyFilter"
                            type="text"
                            placeholder="Search by title, name, phone, email, lead #..."
                            class="w-full text-xs px-3.5 py-2 bg-slate-50 border border-slate-200 rounded-xl focus:bg-white focus:ring-2 focus:ring-indigo-500"
                        />
                    </div>

                    <div>
                        <select
                            v-model="source"
                            @change="applyFilter"
                            class="w-full text-xs px-3.5 py-2 bg-slate-50 border border-slate-200 rounded-xl focus:bg-white focus:ring-2 focus:ring-indigo-500"
                        >
                            <option value="all">All Sources</option>
                            <option value="manual">Manual Entry</option>
                            <option value="website">Website</option>
                            <option value="whatsapp">WhatsApp</option>
                            <option value="referral">Referral</option>
                            <option value="campaign">Campaign</option>
                            <option value="import">Import</option>
                        </select>
                    </div>

                    <div>
                        <select
                            v-model="warehouseId"
                            @change="applyFilter"
                            class="w-full text-xs px-3.5 py-2 bg-slate-50 border border-slate-200 rounded-xl focus:bg-white focus:ring-2 focus:ring-indigo-500"
                        >
                            <option value="all">All Branches</option>
                            <option v-for="w in warehouses" :key="w.id" :value="w.id">{{ w.name }}</option>
                        </select>
                    </div>

                    <div class="flex items-center gap-2">
                        <select
                            v-model="assignedUserId"
                            @change="applyFilter"
                            class="flex-1 text-xs px-3.5 py-2 bg-slate-50 border border-slate-200 rounded-xl focus:bg-white focus:ring-2 focus:ring-indigo-500"
                        >
                            <option value="all">All Representatives</option>
                            <option v-for="u in salespersons" :key="u.id" :value="u.id">{{ u.name }}</option>
                        </select>
                        <button
                            type="button"
                            @click="resetFilter"
                            title="Reset Filters"
                            class="p-2 rounded-xl border border-slate-200 hover:bg-slate-100 text-slate-500 transition"
                        >
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                            </svg>
                        </button>
                    </div>
                </div>
            </div>

            <!-- Leads Table -->
            <div class="bg-white rounded-2xl border border-slate-200/80 shadow-xs overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm min-w-[850px]">
                        <thead class="bg-slate-50/80 border-b border-slate-200 text-xs font-semibold text-slate-500 uppercase tracking-wider">
                            <tr>
                                <th class="py-3.5 px-4 whitespace-nowrap">Lead #</th>
                                <th class="py-3.5 px-4 whitespace-nowrap">Title / Opportunity</th>
                                <th class="py-3.5 px-4 whitespace-nowrap">Contact & Company</th>
                                <th class="py-3.5 px-4 whitespace-nowrap">Source</th>
                                <th class="py-3.5 px-4 whitespace-nowrap">Status</th>
                                <th class="py-3.5 px-4 whitespace-nowrap">Score</th>
                                <th class="py-3.5 px-4 whitespace-nowrap">Branch & Rep</th>
                                <th class="py-3.5 px-4 text-right whitespace-nowrap">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 text-slate-700">
                            <tr v-for="lead in leads.data" :key="lead.id" class="hover:bg-slate-50/70 transition">
                                <!-- Lead Number -->
                                <td class="py-3.5 px-4 whitespace-nowrap font-mono font-bold text-xs">
                                    <Link
                                        :href="route('crm.leads.show', lead.id)"
                                        class="text-indigo-600 hover:text-indigo-800 hover:underline"
                                    >
                                        {{ lead.lead_number }}
                                    </Link>
                                </td>

                                <!-- Title -->
                                <td class="py-3.5 px-4 font-semibold text-slate-900">
                                    <Link :href="route('crm.leads.show', lead.id)" class="hover:text-indigo-600 transition">
                                        {{ lead.title }}
                                    </Link>
                                </td>

                                <!-- Contact & Company -->
                                <td class="py-3.5 px-4">
                                    <div class="font-medium text-slate-800">
                                        {{ [lead.first_name, lead.last_name].filter(Boolean).join(' ') || '—' }}
                                    </div>
                                    <div v-if="lead.company_name" class="text-xs text-slate-500 font-medium">
                                        {{ lead.company_name }}
                                    </div>
                                    <div class="text-[11px] text-slate-400 mt-0.5">
                                        {{ lead.phone || lead.email || 'No contact info' }}
                                    </div>
                                </td>

                                <!-- Source -->
                                <td class="py-3.5 px-4 whitespace-nowrap">
                                    <span
                                        class="px-2 py-0.5 rounded-full text-[10px] font-extrabold uppercase border"
                                        :class="getSourceBadgeClass(lead.source)"
                                    >
                                        {{ lead.source }}
                                    </span>
                                </td>

                                <!-- Status -->
                                <td class="py-3.5 px-4 whitespace-nowrap">
                                    <span
                                        class="px-2.5 py-0.5 rounded-full text-xs font-bold capitalize border"
                                        :class="getStatusBadgeClass(lead.status)"
                                    >
                                        {{ lead.status }}
                                    </span>
                                </td>

                                <!-- Score -->
                                <td class="py-3.5 px-4 whitespace-nowrap">
                                    <div class="flex items-center gap-2">
                                        <div class="w-12 bg-slate-100 rounded-full h-1.5 overflow-hidden">
                                            <div
                                                class="h-full rounded-full transition-all"
                                                :class="lead.score >= 70 ? 'bg-emerald-500' : (lead.score >= 40 ? 'bg-amber-500' : 'bg-slate-400')"
                                                :style="{ width: `${lead.score}%` }"
                                            ></div>
                                        </div>
                                        <span class="font-mono text-xs font-bold text-slate-700">{{ lead.score }}</span>
                                    </div>
                                </td>

                                <!-- Branch & Rep -->
                                <td class="py-3.5 px-4 whitespace-nowrap text-xs">
                                    <div class="font-medium text-slate-800">
                                        {{ lead.warehouse?.name || 'Tenant-Wide' }}
                                    </div>
                                    <div class="text-[11px] text-slate-400">
                                        {{ lead.assigned_user?.name || 'Unassigned' }}
                                    </div>
                                </td>

                                <!-- Actions -->
                                <td class="py-3.5 px-4 text-right whitespace-nowrap">
                                    <ActionDropdown>
                                        <div class="py-1 min-w-[140px]">
                                            <Link
                                                :href="route('crm.leads.show', lead.id)"
                                                class="flex items-center gap-2 px-3 py-2 text-xs font-medium text-slate-700 hover:bg-slate-50 transition"
                                            >
                                                <span>View Details</span>
                                            </Link>
                                            <Link
                                                :href="route('crm.leads.edit', lead.id)"
                                                class="flex items-center gap-2 px-3 py-2 text-xs font-medium text-slate-700 hover:bg-slate-50 transition"
                                            >
                                                <span>Edit Lead</span>
                                            </Link>
                                            <button
                                                v-if="lead.status !== 'converted'"
                                                type="button"
                                                @click="openConvertModal(lead)"
                                                class="w-full flex items-center gap-2 px-3 py-2 text-xs font-semibold text-purple-700 hover:bg-purple-50 transition text-left"
                                            >
                                                <span>Convert to Customer</span>
                                            </button>
                                            <button
                                                type="button"
                                                @click="confirmDelete(lead)"
                                                class="w-full flex items-center gap-2 px-3 py-2 text-xs font-semibold text-rose-600 hover:bg-rose-50 transition text-left"
                                            >
                                                <span>Delete Lead</span>
                                            </button>
                                        </div>
                                    </ActionDropdown>
                                </td>
                            </tr>

                            <tr v-if="!leads.data || leads.data.length === 0">
                                <td colspan="8" class="text-center py-12 text-slate-400 font-medium">
                                    No leads found matching your search or filters.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <div v-if="leads.links && leads.links.length > 3" class="p-4 border-t border-slate-100 flex flex-col sm:flex-row items-center justify-between gap-3 text-xs text-slate-500">
                    <div>Showing {{ leads.from || 0 }} to {{ leads.to || 0 }} of {{ leads.total }} leads</div>
                    <Pagination :links="leads.links" />
                </div>
            </div>
        </div>

        <!-- Delete Confirmation Modal -->
        <ConfirmationModal :show="!!deletingLead" @close="deletingLead = null">
            <template #title>
                Delete Lead
            </template>
            <template #content>
                Are you sure you want to delete lead <strong class="text-slate-900">{{ deletingLead?.lead_number }}</strong>? This action cannot be undone.
            </template>
            <template #footer>
                <div class="flex justify-end gap-3">
                    <button
                        type="button"
                        @click="deletingLead = null"
                        class="px-4 py-2 rounded-xl border border-slate-200 text-xs font-semibold text-slate-700 hover:bg-slate-50"
                    >
                        Cancel
                    </button>
                    <button
                        type="button"
                        @click="executeDelete"
                        class="px-4 py-2 rounded-xl bg-rose-600 hover:bg-rose-700 text-white text-xs font-bold shadow-xs"
                    >
                        Confirm Delete
                    </button>
                </div>
            </template>
        </ConfirmationModal>

        <!-- Convert Lead Modal -->
        <LeadConvertModal
            :show="!!convertingLead"
            :lead="convertingLead"
            @close="convertingLead = null"
        />
    </AppLayout>
</template>
