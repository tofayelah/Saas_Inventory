<script setup>
import { ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import ConfirmationModal from '@/Components/ConfirmationModal.vue';
import LeadConvertModal from './Partials/LeadConvertModal.vue';
import LeadActivityTimeline from './Partials/LeadActivityTimeline.vue';
import { formatDate } from '@/Utils/date';

const props = defineProps({
    lead: Object,
    duplicates: Object,
    warehouses: Array,
    salespersons: Array,
});

const isConvertModalOpen = ref(false);
const isDeleteModalOpen = ref(false);

function updateStatus(newStatus) {
    router.put(route('crm.leads.update', props.lead.id), {
        status: newStatus,
    }, {
        preserveScroll: true,
    });
}

function executeDelete() {
    router.delete(route('crm.leads.destroy', props.lead.id));
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
</script>

<template>
    <AppLayout :title="`Lead: ${lead.lead_number}`" subtitle="Prospect details, status progression, and activity history">
        <Head :title="`Lead: ${lead.lead_number}`" />

        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-6">
            <!-- Header with Quick Action Progression -->
            <div class="bg-white rounded-2xl border border-slate-200/80 shadow-xs p-5 flex flex-col md:flex-row md:items-center md:justify-between gap-4">
                <div class="space-y-1">
                    <div class="flex items-center gap-2.5">
                        <span class="font-mono text-xs font-bold text-indigo-600 bg-indigo-50 px-2.5 py-1 rounded-lg border border-indigo-100">
                            {{ lead.lead_number }}
                        </span>
                        <span
                            class="px-2.5 py-0.5 rounded-full text-xs font-bold capitalize border"
                            :class="getStatusBadgeClass(lead.status)"
                        >
                            {{ lead.status }}
                        </span>
                        <span class="text-xs text-slate-400">
                            via <strong class="text-slate-600 uppercase">{{ lead.source }}</strong>
                        </span>
                    </div>
                    <h2 class="text-xl sm:text-2xl font-black text-slate-900 tracking-tight">
                        {{ lead.title }}
                    </h2>
                </div>

                <!-- Status Progression & Action Buttons -->
                <div class="flex flex-wrap items-center gap-2">
                    <button
                        v-if="lead.status === 'new'"
                        type="button"
                        @click="updateStatus('contacted')"
                        class="px-3.5 py-2 rounded-xl bg-amber-500 hover:bg-amber-600 text-white font-bold text-xs shadow-xs transition"
                    >
                        Mark Contacted
                    </button>

                    <button
                        v-if="lead.status !== 'qualified' && lead.status !== 'converted'"
                        type="button"
                        @click="updateStatus('qualified')"
                        class="px-3.5 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white font-bold text-xs shadow-xs transition"
                    >
                        Qualify Lead
                    </button>

                    <button
                        v-if="lead.status !== 'unqualified' && lead.status !== 'converted'"
                        type="button"
                        @click="updateStatus('unqualified')"
                        class="px-3.5 py-2 rounded-xl border border-slate-200 bg-white hover:bg-slate-50 text-slate-700 font-semibold text-xs shadow-2xs transition"
                    >
                        Disqualify
                    </button>

                    <button
                        v-if="lead.status !== 'converted'"
                        type="button"
                        @click="isConvertModalOpen = true"
                        class="px-4 py-2 rounded-xl bg-purple-600 hover:bg-purple-700 text-white font-bold text-xs shadow-sm shadow-purple-600/30 transition hover:scale-[1.02] active:scale-[0.98]"
                    >
                        Convert to Customer
                    </button>

                    <Link
                        :href="route('crm.leads.edit', lead.id)"
                        class="px-3.5 py-2 rounded-xl border border-slate-200 bg-white text-slate-700 hover:bg-slate-50 font-semibold text-xs shadow-2xs transition"
                    >
                        Edit
                    </Link>

                    <button
                        v-if="lead.status !== 'converted'"
                        type="button"
                        @click="isDeleteModalOpen = true"
                        class="p-2 rounded-xl border border-rose-200 hover:bg-rose-50 text-rose-600 transition"
                        title="Delete Lead"
                    >
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                        </svg>
                    </button>
                </div>
            </div>

            <!-- Main 2-Column Content -->
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 items-start">
                <!-- Left Column: Lead Profile & Details -->
                <div class="space-y-6">
                    <!-- Duplicate Alert Card if matches found -->
                    <div
                        v-if="duplicates?.has_duplicates"
                        class="bg-amber-50 border border-amber-200 rounded-2xl p-4 text-xs space-y-2 text-amber-900 shadow-xs"
                    >
                        <div class="flex items-center gap-1.5 font-bold text-amber-950">
                            <svg class="w-4 h-4 text-amber-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                            </svg>
                            <span>Potential Workspace Matches</span>
                        </div>
                        <p class="text-amber-800">
                            Other records share this phone or email address.
                        </p>
                        <div v-if="duplicates.matched_customers?.length" class="space-y-1">
                            <span class="font-bold text-[11px] uppercase">ERP Customers:</span>
                            <div v-for="mc in duplicates.matched_customers" :key="mc.id" class="p-1.5 rounded bg-white/70 border border-amber-100">
                                <span class="font-semibold">{{ mc.name }}</span>
                                <span v-if="mc.company_name"> ({{ mc.company_name }})</span>
                            </div>
                        </div>
                    </div>

                    <!-- Profile Card -->
                    <div class="bg-white rounded-2xl border border-slate-200/80 shadow-xs p-5 space-y-4">
                        <h3 class="text-xs font-bold text-slate-800 uppercase tracking-wider border-b border-slate-100 pb-2.5">
                            Lead Profile
                        </h3>

                        <div class="space-y-3 text-xs">
                            <div>
                                <span class="text-slate-400 font-medium">Contact Person:</span>
                                <div class="font-semibold text-slate-900 text-sm mt-0.5">
                                    {{ [lead.first_name, lead.last_name].filter(Boolean).join(' ') || '—' }}
                                </div>
                            </div>

                            <div v-if="lead.company_name">
                                <span class="text-slate-400 font-medium">Company / Org:</span>
                                <div class="font-semibold text-slate-800 mt-0.5">
                                    {{ lead.company_name }}
                                </div>
                            </div>

                            <div>
                                <span class="text-slate-400 font-medium">Phone:</span>
                                <div class="font-semibold text-slate-800 mt-0.5">
                                    <a v-if="lead.phone" :href="`tel:${lead.phone}`" class="text-indigo-600 hover:underline">
                                        {{ lead.phone }}
                                    </a>
                                    <span v-else class="text-slate-400">None provided</span>
                                </div>
                            </div>

                            <div>
                                <span class="text-slate-400 font-medium">Email:</span>
                                <div class="font-semibold text-slate-800 mt-0.5">
                                    <a v-if="lead.email" :href="`mailto:${lead.email}`" class="text-indigo-600 hover:underline">
                                        {{ lead.email }}
                                    </a>
                                    <span v-else class="text-slate-400">None provided</span>
                                </div>
                            </div>

                            <!-- Lead Score Progress -->
                            <div>
                                <div class="flex items-center justify-between text-slate-400 font-medium mb-1">
                                    <span>Lead Score:</span>
                                    <span class="font-mono font-bold text-slate-800">{{ lead.score }} / 100</span>
                                </div>
                                <div class="w-full bg-slate-100 rounded-full h-2 overflow-hidden">
                                    <div
                                        class="h-full rounded-full transition-all"
                                        :class="lead.score >= 70 ? 'bg-emerald-500' : (lead.score >= 40 ? 'bg-amber-500' : 'bg-slate-400')"
                                        :style="{ width: `${lead.score}%` }"
                                    ></div>
                                </div>
                            </div>

                            <div class="pt-2 border-t border-slate-100 space-y-2">
                                <div class="flex justify-between">
                                    <span class="text-slate-400">Branch Hub:</span>
                                    <span class="font-semibold text-slate-800">{{ lead.warehouse?.name || 'Tenant-Wide' }}</span>
                                </div>

                                <div class="flex justify-between">
                                    <span class="text-slate-400">Assigned Rep:</span>
                                    <span class="font-semibold text-slate-800">{{ lead.assigned_user?.name || 'Unassigned' }}</span>
                                </div>

                                <div class="flex justify-between">
                                    <span class="text-slate-400">Registered:</span>
                                    <span class="text-slate-600">{{ formatDate(lead.created_at) }}</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Converted Customer Information Card -->
                    <div v-if="lead.status === 'converted'" class="bg-purple-50/70 border border-purple-200 rounded-2xl p-5 space-y-3 shadow-xs">
                        <div class="flex items-center gap-2 text-purple-900 font-bold text-xs uppercase tracking-wider">
                            <svg class="w-4 h-4 text-purple-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                            <span>Converted Customer</span>
                        </div>
                        <div class="text-xs space-y-1">
                            <p class="font-semibold text-purple-950 text-sm">
                                {{ lead.converted_customer?.name || 'ERP Customer' }}
                            </p>
                            <p v-if="lead.converted_customer?.company_name" class="text-purple-800">
                                {{ lead.converted_customer.company_name }}
                            </p>
                            <p class="text-purple-700 text-[11px]">
                                Converted on {{ formatDate(lead.converted_at) }}
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Right Column: Activities & Tasks Timeline -->
                <div class="lg:col-span-2">
                    <LeadActivityTimeline
                        :lead="lead"
                        :activities="lead.activities || []"
                        :salespersons="salespersons"
                    />
                </div>
            </div>
        </div>

        <!-- Convert Modal -->
        <LeadConvertModal
            :show="isConvertModalOpen"
            :lead="lead"
            :duplicates="duplicates"
            @close="isConvertModalOpen = false"
        />

        <!-- Delete Modal -->
        <ConfirmationModal :show="isDeleteModalOpen" @close="isDeleteModalOpen = false">
            <template #title>
                Delete Lead
            </template>
            <template #content>
                Are you sure you want to permanently delete lead <strong class="text-slate-900">{{ lead.lead_number }}</strong>?
            </template>
            <template #footer>
                <div class="flex justify-end gap-3">
                    <button
                        type="button"
                        @click="isDeleteModalOpen = false"
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
    </AppLayout>
</template>
