<script setup>
import { ref, watch } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';

const props = defineProps({
    lead: {
        type: Object,
        default: null,
    },
    warehouses: {
        type: Array,
        default: () => [],
    },
    salespersons: {
        type: Array,
        default: () => [],
    },
    defaultWarehouseId: {
        type: [Number, String],
        default: '',
    },
    isEdit: {
        type: Boolean,
        default: false,
    },
});

const form = useForm({
    title: props.lead?.title || '',
    first_name: props.lead?.first_name || '',
    last_name: props.lead?.last_name || '',
    company_name: props.lead?.company_name || '',
    email: props.lead?.email || '',
    phone: props.lead?.phone || '',
    source: props.lead?.source || 'manual',
    status: props.lead?.status || 'new',
    score: props.lead?.score ?? 0,
    warehouse_id: props.lead?.warehouse_id || props.defaultWarehouseId || '',
    assigned_user_id: props.lead?.assigned_user_id || '',
    custom_fields: props.lead?.custom_fields || {},
});

const duplicateCheckResult = ref(null);
const isCheckingDuplicates = ref(false);
let debounceTimer = null;

function runDuplicateCheck() {
    if (!form.phone && !form.email) {
        duplicateCheckResult.value = null;
        return;
    }

    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(async () => {
        isCheckingDuplicates.value = true;
        try {
            const params = new URLSearchParams();
            if (form.phone) params.append('phone', form.phone);
            if (form.email) params.append('email', form.email);
            if (props.lead?.id) params.append('exclude_id', props.lead.id);

            const res = await fetch(route('crm.leads.check-duplicates') + '?' + params.toString(), {
                headers: { 'Accept': 'application/json' },
            });
            if (res.ok) {
                duplicateCheckResult.value = await res.json();
            }
        } catch (e) {
            console.error('Duplicate check error:', e);
        } finally {
            isCheckingDuplicates.value = false;
        }
    }, 400);
}

watch(() => [form.phone, form.email], () => {
    runDuplicateCheck();
});

function submit() {
    if (props.isEdit) {
        form.put(route('crm.leads.update', props.lead.id));
    } else {
        form.post(route('crm.leads.store'));
    }
}
</script>

<template>
    <form @submit.prevent="submit" class="space-y-6">
        <!-- Duplicate Warning Alert -->
        <div
            v-if="duplicateCheckResult?.has_duplicates"
            class="p-4 rounded-xl border border-amber-300 bg-amber-50 text-amber-900 text-sm flex items-start gap-3 shadow-xs"
        >
            <svg class="w-5 h-5 text-amber-600 shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
            </svg>
            <div class="flex-1 space-y-1">
                <p class="font-bold text-amber-950">Potential Duplicate Detected in Workspace</p>
                <div v-if="duplicateCheckResult.matched_leads?.length" class="text-xs">
                    <span class="font-semibold">Matching Leads:</span>
                    <ul class="list-disc list-inside mt-0.5">
                        <li v-for="ml in duplicateCheckResult.matched_leads" :key="ml.id">
                            {{ ml.lead_number }} — {{ ml.title }} ({{ ml.phone || ml.email || 'No contact' }}) [{{ ml.status }}]
                        </li>
                    </ul>
                </div>
                <div v-if="duplicateCheckResult.matched_customers?.length" class="text-xs">
                    <span class="font-semibold">Existing ERP Customers:</span>
                    <ul class="list-disc list-inside mt-0.5">
                        <li v-for="mc in duplicateCheckResult.matched_customers" :key="mc.id">
                            {{ mc.name }} {{ mc.company_name ? `(${mc.company_name})` : '' }} — {{ mc.phone || mc.email }}
                        </li>
                    </ul>
                </div>
            </div>
        </div>

        <div class="bg-white rounded-2xl border border-slate-200/80 shadow-xs p-6 space-y-6">
            <h3 class="text-sm font-bold text-slate-800 uppercase tracking-wider border-b border-slate-100 pb-3">
                Lead Information
            </h3>

            <!-- Title -->
            <div>
                <label class="block text-xs font-semibold text-slate-700 uppercase tracking-wider mb-1.5">
                    Lead / Opportunity Title <span class="text-rose-500">*</span>
                </label>
                <input
                    v-model="form.title"
                    type="text"
                    required
                    placeholder="e.g. Enterprise Software Implementation Inquiry"
                    class="w-full text-sm px-3.5 py-2.5 bg-slate-50 border border-slate-200 rounded-xl focus:bg-white focus:ring-2 focus:ring-indigo-500 transition"
                    :class="{ 'border-rose-300': form.errors.title }"
                />
                <p v-if="form.errors.title" class="text-xs text-rose-500 mt-1">{{ form.errors.title }}</p>
            </div>

            <!-- Contact Name & Company -->
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div>
                    <label class="block text-xs font-semibold text-slate-700 uppercase tracking-wider mb-1.5">First Name</label>
                    <input
                        v-model="form.first_name"
                        type="text"
                        placeholder="John"
                        class="w-full text-sm px-3.5 py-2.5 bg-slate-50 border border-slate-200 rounded-xl focus:bg-white focus:ring-2 focus:ring-indigo-500 transition"
                    />
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-700 uppercase tracking-wider mb-1.5">Last Name</label>
                    <input
                        v-model="form.last_name"
                        type="text"
                        placeholder="Doe"
                        class="w-full text-sm px-3.5 py-2.5 bg-slate-50 border border-slate-200 rounded-xl focus:bg-white focus:ring-2 focus:ring-indigo-500 transition"
                    />
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-700 uppercase tracking-wider mb-1.5">Company / Organization</label>
                    <input
                        v-model="form.company_name"
                        type="text"
                        placeholder="Acme Corporation"
                        class="w-full text-sm px-3.5 py-2.5 bg-slate-50 border border-slate-200 rounded-xl focus:bg-white focus:ring-2 focus:ring-indigo-500 transition"
                    />
                </div>
            </div>

            <!-- Phone & Email -->
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-semibold text-slate-700 uppercase tracking-wider mb-1.5">Phone Number</label>
                    <input
                        v-model="form.phone"
                        type="text"
                        placeholder="e.g. +8801711000000"
                        class="w-full text-sm px-3.5 py-2.5 bg-slate-50 border border-slate-200 rounded-xl focus:bg-white focus:ring-2 focus:ring-indigo-500 transition"
                        :class="{ 'border-rose-300': form.errors.phone }"
                    />
                    <p v-if="form.errors.phone" class="text-xs text-rose-500 mt-1">{{ form.errors.phone }}</p>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-700 uppercase tracking-wider mb-1.5">Email Address</label>
                    <input
                        v-model="form.email"
                        type="email"
                        placeholder="e.g. john@acme.com"
                        class="w-full text-sm px-3.5 py-2.5 bg-slate-50 border border-slate-200 rounded-xl focus:bg-white focus:ring-2 focus:ring-indigo-500 transition"
                        :class="{ 'border-rose-300': form.errors.email }"
                    />
                    <p v-if="form.errors.email" class="text-xs text-rose-500 mt-1">{{ form.errors.email }}</p>
                </div>
            </div>

            <!-- Source & Status & Score -->
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div>
                    <label class="block text-xs font-semibold text-slate-700 uppercase tracking-wider mb-1.5">
                        Lead Source <span class="text-rose-500">*</span>
                    </label>
                    <select
                        v-model="form.source"
                        required
                        class="w-full text-sm px-3.5 py-2.5 bg-slate-50 border border-slate-200 rounded-xl focus:bg-white focus:ring-2 focus:ring-indigo-500 transition"
                    >
                        <option value="manual">Manual Entry</option>
                        <option value="website">Website Inquiry</option>
                        <option value="whatsapp">WhatsApp</option>
                        <option value="referral">Client Referral</option>
                        <option value="campaign">Marketing Campaign</option>
                        <option value="import">Import / Bulk List</option>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-slate-700 uppercase tracking-wider mb-1.5">
                        Lead Status <span class="text-rose-500">*</span>
                    </label>
                    <select
                        v-model="form.status"
                        required
                        :disabled="lead?.status === 'converted'"
                        class="w-full text-sm px-3.5 py-2.5 bg-slate-50 border border-slate-200 rounded-xl focus:bg-white focus:ring-2 focus:ring-indigo-500 transition disabled:bg-slate-100 disabled:text-slate-400"
                    >
                        <option value="new">New Prospect</option>
                        <option value="contacted">Contacted</option>
                        <option value="qualified">Qualified</option>
                        <option value="unqualified">Unqualified / Lost</option>
                        <option v-if="isEdit" value="converted" disabled>Converted</option>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-slate-700 uppercase tracking-wider mb-1.5">
                        Lead Score (0 - 100)
                    </label>
                    <div class="flex items-center gap-3">
                        <input
                            v-model.number="form.score"
                            type="range"
                            min="0"
                            max="100"
                            class="flex-1 accent-indigo-600"
                        />
                        <span class="w-10 text-center font-mono font-bold text-sm text-slate-800 bg-slate-100 py-1 rounded-lg">
                            {{ form.score }}
                        </span>
                    </div>
                </div>
            </div>

            <!-- Warehouse / Branch & Assigned User -->
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 pt-2 border-t border-slate-100">
                <div>
                    <label class="block text-xs font-semibold text-slate-700 uppercase tracking-wider mb-1.5">Branch / Warehouse Hub</label>
                    <select
                        v-model="form.warehouse_id"
                        class="w-full text-sm px-3.5 py-2.5 bg-slate-50 border border-slate-200 rounded-xl focus:bg-white focus:ring-2 focus:ring-indigo-500 transition"
                    >
                        <option value="">Tenant-Wide (No specific branch)</option>
                        <option v-for="w in warehouses" :key="w.id" :value="w.id">
                            {{ w.name }} {{ w.is_default ? '(Default)' : '' }}
                        </option>
                    </select>
                    <p class="text-[11px] text-slate-400 mt-1">Limits visibility to personnel assigned to this branch hub.</p>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-slate-700 uppercase tracking-wider mb-1.5">Assigned Sales Representative</label>
                    <select
                        v-model="form.assigned_user_id"
                        class="w-full text-sm px-3.5 py-2.5 bg-slate-50 border border-slate-200 rounded-xl focus:bg-white focus:ring-2 focus:ring-indigo-500 transition"
                    >
                        <option value="">Unassigned</option>
                        <option v-for="u in salespersons" :key="u.id" :value="u.id">
                            {{ u.name }} ({{ u.email }})
                        </option>
                    </select>
                    <p class="text-[11px] text-slate-400 mt-1">Responsible account executive or representative.</p>
                </div>
            </div>
        </div>

        <!-- Form Actions -->
        <div class="flex items-center justify-end gap-3">
            <Link
                :href="lead ? route('crm.leads.show', lead.id) : route('crm.leads.index')"
                class="px-5 py-2.5 rounded-xl border border-slate-200 bg-white text-slate-700 text-xs font-semibold hover:bg-slate-50 transition shadow-2xs"
            >
                Cancel
            </Link>
            <button
                type="submit"
                :disabled="form.processing"
                class="inline-flex items-center gap-2 px-6 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white font-bold text-xs shadow-sm shadow-indigo-600/30 transition hover:scale-[1.02] active:scale-[0.98] disabled:opacity-50"
            >
                <svg v-if="form.processing" class="animate-spin w-4 h-4 text-white" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path>
                </svg>
                <span>{{ isEdit ? 'Update Lead' : 'Save Lead' }}</span>
            </button>
        </div>
    </form>
</template>
