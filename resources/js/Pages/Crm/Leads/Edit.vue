<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import LeadForm from './Partials/LeadForm.vue';

defineProps({
    lead: Object,
    warehouses: Array,
    salespersons: Array,
});
</script>

<template>
    <AppLayout :title="`Edit Lead ${lead.lead_number}`" subtitle="Update lead information and qualification metrics">
        <Head :title="`Edit Lead ${lead.lead_number}`" />

        <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-6">
            <!-- Header with Back Button -->
            <div class="flex items-center justify-between">
                <div>
                    <h2 class="text-xl sm:text-2xl font-black text-slate-900 tracking-tight">
                        Edit Lead <span class="font-mono text-indigo-600">{{ lead.lead_number }}</span>
                    </h2>
                    <p class="text-xs text-slate-500 mt-0.5">{{ lead.title }}</p>
                </div>
                <Link
                    :href="route('crm.leads.show', lead.id)"
                    class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl border border-slate-200 bg-white text-slate-700 text-xs font-semibold hover:bg-slate-50 shadow-2xs transition"
                >
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                    </svg>
                    <span>Back to Lead</span>
                </Link>
            </div>

            <!-- Form -->
            <LeadForm
                :lead="lead"
                :warehouses="warehouses"
                :salespersons="salespersons"
                :is-edit="true"
            />
        </div>
    </AppLayout>
</template>
