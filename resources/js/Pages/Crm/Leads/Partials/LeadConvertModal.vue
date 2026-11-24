<script setup>
import { watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import Modal from '@/Components/Modal.vue';

const props = defineProps({
    show: {
        type: Boolean,
        default: false,
    },
    lead: {
        type: Object,
        default: null,
    },
    duplicates: {
        type: Object,
        default: () => ({ matched_customers: [] }),
    },
});

const emit = defineEmits(['close']);

const form = useForm({
    name: '',
    company_name: '',
    phone: '',
    email: '',
    address: '',
});

watch(() => props.lead, (newLead) => {
    if (newLead) {
        const contactName = [newLead.first_name, newLead.last_name].filter(Boolean).join(' ');
        form.name = contactName || newLead.company_name || newLead.title;
        form.company_name = newLead.company_name || '';
        form.phone = newLead.phone || '';
        form.email = newLead.email || '';
        form.address = '';
    }
}, { immediate: true });

function submit() {
    if (!props.lead) return;
    form.post(route('crm.leads.convert', props.lead.id), {
        onSuccess: () => {
            emit('close');
        },
    });
}
</script>

<template>
    <Modal :show="show" max-width="lg" @close="emit('close')">
        <div class="p-6 space-y-5">
            <div class="flex items-start justify-between border-b border-slate-100 pb-3">
                <div>
                    <h3 class="text-base font-bold text-slate-900">Convert Lead to Canonical Customer</h3>
                    <p class="text-xs text-slate-500 mt-0.5">
                        Promotes <span class="font-semibold text-slate-800">{{ lead?.lead_number }}</span> into the central ERP customer master ledger.
                    </p>
                </div>
                <button
                    type="button"
                    @click="emit('close')"
                    class="text-slate-400 hover:text-slate-600 rounded-lg p-1 transition"
                >
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>

            <!-- Existing Customer Alert -->
            <div
                v-if="duplicates?.matched_customers?.length"
                class="p-3.5 rounded-xl border border-sky-200 bg-sky-50 text-sky-900 text-xs flex items-start gap-2.5"
            >
                <svg class="w-4 h-4 text-sky-600 shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
                <div>
                    <p class="font-bold">Existing Customer Match Found</p>
                    <p class="text-sky-800 mt-0.5">
                        A customer with this phone or email already exists:
                        <span class="font-semibold">{{ duplicates.matched_customers[0].name }}</span>
                        ({{ duplicates.matched_customers[0].phone || duplicates.matched_customers[0].email }}).
                        Converting will securely link this lead directly to the existing customer without creating a duplicate record.
                    </p>
                </div>
            </div>

            <form @submit.prevent="submit" class="space-y-4">
                <div>
                    <label class="block text-xs font-semibold text-slate-700 uppercase tracking-wider mb-1">
                        Customer / Contact Name <span class="text-rose-500">*</span>
                    </label>
                    <input
                        v-model="form.name"
                        type="text"
                        required
                        class="w-full text-sm px-3.5 py-2.5 bg-slate-50 border border-slate-200 rounded-xl focus:bg-white focus:ring-2 focus:ring-indigo-500"
                        :class="{ 'border-rose-300': form.errors.name }"
                    />
                    <p v-if="form.errors.name" class="text-xs text-rose-500 mt-1">{{ form.errors.name }}</p>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-slate-700 uppercase tracking-wider mb-1">Company / Organization</label>
                    <input
                        v-model="form.company_name"
                        type="text"
                        class="w-full text-sm px-3.5 py-2.5 bg-slate-50 border border-slate-200 rounded-xl focus:bg-white focus:ring-2 focus:ring-indigo-500"
                    />
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-semibold text-slate-700 uppercase tracking-wider mb-1">Phone</label>
                        <input
                            v-model="form.phone"
                            type="text"
                            class="w-full text-sm px-3.5 py-2.5 bg-slate-50 border border-slate-200 rounded-xl focus:bg-white focus:ring-2 focus:ring-indigo-500"
                        />
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-slate-700 uppercase tracking-wider mb-1">Email</label>
                        <input
                            v-model="form.email"
                            type="email"
                            class="w-full text-sm px-3.5 py-2.5 bg-slate-50 border border-slate-200 rounded-xl focus:bg-white focus:ring-2 focus:ring-indigo-500"
                        />
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-slate-700 uppercase tracking-wider mb-1">Billing / Shipping Address</label>
                    <textarea
                        v-model="form.address"
                        rows="2"
                        placeholder="Street, City, Postal Code"
                        class="w-full text-sm px-3.5 py-2.5 bg-slate-50 border border-slate-200 rounded-xl focus:bg-white focus:ring-2 focus:ring-indigo-500"
                    ></textarea>
                </div>

                <div class="pt-3 border-t border-slate-100 flex items-center justify-end gap-3">
                    <button
                        type="button"
                        @click="emit('close')"
                        class="px-4 py-2 rounded-xl border border-slate-200 bg-white text-slate-700 text-xs font-semibold hover:bg-slate-50"
                    >
                        Cancel
                    </button>
                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="inline-flex items-center gap-2 px-5 py-2 rounded-xl bg-purple-600 hover:bg-purple-700 text-white font-bold text-xs shadow-sm shadow-purple-600/30 transition hover:scale-[1.02] active:scale-[0.98] disabled:opacity-50"
                    >
                        <svg v-if="form.processing" class="animate-spin w-3.5 h-3.5 text-white" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path>
                        </svg>
                        <span>Confirm & Convert</span>
                    </button>
                </div>
            </form>
        </div>
    </Modal>
</template>
