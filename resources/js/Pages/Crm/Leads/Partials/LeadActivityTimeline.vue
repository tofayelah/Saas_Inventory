<script setup>
import { ref } from 'vue';
import { useForm, router } from '@inertiajs/vue3';
import { formatDate } from '@/Utils/date';

const props = defineProps({
    lead: {
        type: Object,
        required: true,
    },
    activities: {
        type: Array,
        default: () => [],
    },
    salespersons: {
        type: Array,
        default: () => [],
    },
});

const activeType = ref('call');

const form = useForm({
    activity_type: 'call',
    title: '',
    description: '',
    due_date: '',
    assigned_user_id: '',
});

function setType(type) {
    activeType.value = type;
    form.activity_type = type;
    if (type === 'call' && !form.title) form.title = 'Phone Call with Lead';
    if (type === 'meeting' && !form.title) form.title = 'Discovery Meeting';
    if (type === 'task' && !form.title) form.title = 'Follow-up Task';
    if (type === 'note' && !form.title) form.title = 'Internal Note';
}

function submitActivity() {
    form.post(route('crm.leads.activities.store', props.lead.id), {
        preserveScroll: true,
        onSuccess: () => {
            form.reset();
            form.activity_type = activeType.value;
        },
    });
}

function toggleActivity(act) {
    router.post(route('crm.leads.activities.toggle', [props.lead.id, act.id]), {}, {
        preserveScroll: true,
    });
}

function getActivityBadgeClass(type) {
    switch (type) {
        case 'call': return 'bg-sky-100 text-sky-700 border-sky-200';
        case 'meeting': return 'bg-purple-100 text-purple-700 border-purple-200';
        case 'task': return 'bg-amber-100 text-amber-700 border-amber-200';
        case 'note': return 'bg-slate-100 text-slate-700 border-slate-200';
        default: return 'bg-indigo-100 text-indigo-700 border-indigo-200';
    }
}
</script>

<template>
    <div class="space-y-6">
        <!-- Log Activity Box -->
        <div class="bg-white rounded-2xl border border-slate-200/80 shadow-xs p-5 space-y-4">
            <h4 class="text-xs font-bold text-slate-800 uppercase tracking-wider">Log New Activity</h4>

            <!-- Activity Type Tabs -->
            <div class="flex items-center gap-1.5 p-1 bg-slate-100/80 rounded-xl">
                <button
                    type="button"
                    @click="setType('call')"
                    class="flex-1 py-1.5 text-xs font-semibold rounded-lg transition"
                    :class="activeType === 'call' ? 'bg-white text-indigo-700 shadow-2xs' : 'text-slate-600 hover:text-slate-900'"
                >
                    📞 Call
                </button>
                <button
                    type="button"
                    @click="setType('meeting')"
                    class="flex-1 py-1.5 text-xs font-semibold rounded-lg transition"
                    :class="activeType === 'meeting' ? 'bg-white text-indigo-700 shadow-2xs' : 'text-slate-600 hover:text-slate-900'"
                >
                    📅 Meeting
                </button>
                <button
                    type="button"
                    @click="setType('task')"
                    class="flex-1 py-1.5 text-xs font-semibold rounded-lg transition"
                    :class="activeType === 'task' ? 'bg-white text-indigo-700 shadow-2xs' : 'text-slate-600 hover:text-slate-900'"
                >
                    ✅ Task
                </button>
                <button
                    type="button"
                    @click="setType('note')"
                    class="flex-1 py-1.5 text-xs font-semibold rounded-lg transition"
                    :class="activeType === 'note' ? 'bg-white text-indigo-700 shadow-2xs' : 'text-slate-600 hover:text-slate-900'"
                >
                    📝 Note
                </button>
            </div>

            <form @submit.prevent="submitActivity" class="space-y-3">
                <div>
                    <input
                        v-model="form.title"
                        type="text"
                        required
                        :placeholder="activeType === 'note' ? 'Note subject...' : 'Activity title / subject...'"
                        class="w-full text-xs sm:text-sm px-3.5 py-2 bg-slate-50 border border-slate-200 rounded-xl focus:bg-white focus:ring-2 focus:ring-indigo-500"
                    />
                </div>

                <div>
                    <textarea
                        v-model="form.description"
                        rows="2"
                        placeholder="Log detailed notes, key discussion points, or next steps..."
                        class="w-full text-xs sm:text-sm px-3.5 py-2 bg-slate-50 border border-slate-200 rounded-xl focus:bg-white focus:ring-2 focus:ring-indigo-500"
                    ></textarea>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label class="block text-[11px] font-semibold text-slate-500 uppercase mb-1">Due Date / Time</label>
                        <input
                            v-model="form.due_date"
                            type="datetime-local"
                            class="w-full text-xs px-3 py-1.5 bg-slate-50 border border-slate-200 rounded-xl focus:bg-white focus:ring-2 focus:ring-indigo-500"
                        />
                    </div>
                    <div>
                        <label class="block text-[11px] font-semibold text-slate-500 uppercase mb-1">Assigned Rep</label>
                        <select
                            v-model="form.assigned_user_id"
                            class="w-full text-xs px-3 py-1.5 bg-slate-50 border border-slate-200 rounded-xl focus:bg-white focus:ring-2 focus:ring-indigo-500"
                        >
                            <option value="">Unassigned</option>
                            <option v-for="u in salespersons" :key="u.id" :value="u.id">
                                {{ u.name }}
                            </option>
                        </select>
                    </div>
                </div>

                <div class="flex justify-end pt-1">
                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="px-4 py-1.5 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white font-bold text-xs shadow-xs transition hover:scale-[1.02] active:scale-[0.98] disabled:opacity-50"
                    >
                        Save Activity
                    </button>
                </div>
            </form>
        </div>

        <!-- Activity History Timeline -->
        <div class="bg-white rounded-2xl border border-slate-200/80 shadow-xs p-5 space-y-4">
            <div class="flex items-center justify-between border-b border-slate-100 pb-2.5">
                <h4 class="text-xs font-bold text-slate-800 uppercase tracking-wider">
                    Activity Timeline & History ({{ activities.length }})
                </h4>
            </div>

            <div v-if="!activities || activities.length === 0" class="py-8 text-center text-slate-400 text-xs">
                No activities logged yet. Use the form above to record calls, meetings, tasks, or notes.
            </div>

            <div v-else class="space-y-3">
                <div
                    v-for="act in activities"
                    :key="act.id"
                    class="p-3.5 rounded-xl border border-slate-100 bg-slate-50/50 hover:bg-slate-50 transition space-y-2"
                    :class="{ 'opacity-60 bg-slate-100/40': act.is_completed }"
                >
                    <div class="flex items-start justify-between gap-2">
                        <div class="flex items-center gap-2">
                            <!-- Completion Toggle -->
                            <input
                                type="checkbox"
                                :checked="act.is_completed"
                                @change="toggleActivity(act)"
                                class="w-4 h-4 rounded text-indigo-600 border-slate-300 focus:ring-indigo-500 cursor-pointer"
                                title="Mark completed"
                            />
                            <span
                                class="px-2 py-0.5 rounded text-[10px] font-extrabold uppercase border"
                                :class="getActivityBadgeClass(act.activity_type)"
                            >
                                {{ act.activity_type }}
                            </span>
                            <span
                                class="text-xs font-bold text-slate-900"
                                :class="{ 'line-through text-slate-400': act.is_completed }"
                            >
                                {{ act.title }}
                            </span>
                        </div>
                        <span class="text-[11px] text-slate-400 shrink-0">
                            {{ formatDate(act.created_at) }}
                        </span>
                    </div>

                    <p v-if="act.description" class="text-xs text-slate-600 pl-6 whitespace-pre-wrap">
                        {{ act.description }}
                    </p>

                    <div class="flex flex-wrap items-center gap-3 pl-6 text-[11px] text-slate-400">
                        <span v-if="act.assigned_user">
                            👤 {{ act.assigned_user.name }}
                        </span>
                        <span v-if="act.due_date" :class="{ 'text-rose-600 font-semibold': !act.is_completed && new Date(act.due_date) < new Date() }">
                            ⏰ Due: {{ formatDate(act.due_date) }}
                        </span>
                        <span v-if="act.is_completed" class="text-emerald-600 font-semibold">
                            ✓ Completed
                        </span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
