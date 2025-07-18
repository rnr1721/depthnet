<template>
    <div v-if="pagination && pagination.last_page > 1" :class="[
        'flex flex-col sm:flex-row items-center justify-between space-y-4 sm:space-y-0 p-4 rounded-xl backdrop-blur-sm border',
        isDark ? 'bg-gray-800 bg-opacity-90 border-gray-700' : 'bg-white bg-opacity-90 border-gray-200'
    ]">
        <!-- Info -->
        <div :class="['text-sm', isDark ? 'text-gray-300' : 'text-gray-700']">
            {{ t('pg_showing') }}
            <span class="font-medium">{{ pagination.from || 0 }}</span>
            {{ t('pg_to') }}
            <span class="font-medium">{{ pagination.to || 0 }}</span>
            {{ t('pg_of') }}
            <span class="font-medium">{{ pagination.total || 0 }}</span>
            {{ t('pg_results') }}
        </div>

        <!-- Pagination Controls -->
        <div class="flex items-center space-x-1">
            <!-- Previous Button -->
            <button @click="goToPage(pagination.current_page - 1)" :disabled="pagination.current_page <= 1" :class="[
                'px-3 py-2 rounded-lg text-sm font-medium transition-all focus:outline-none focus:ring-2 focus:ring-indigo-500',
                pagination.current_page <= 1 ?
                    (isDark ? 'bg-gray-700 text-gray-500 cursor-not-allowed' : 'bg-gray-100 text-gray-400 cursor-not-allowed') :
                    (isDark ? 'bg-gray-700 text-gray-200 hover:bg-gray-600' : 'bg-gray-200 text-gray-700 hover:bg-gray-300')
            ]">
                ← {{ t('pg_previous') }}
            </button>

            <!-- Page Numbers -->
            <template v-for="page in visiblePages" :key="page">
                <button v-if="page !== '...'" @click="goToPage(page)" :class="[
                    'px-3 py-2 rounded-lg text-sm font-medium transition-all focus:outline-none focus:ring-2 focus:ring-indigo-500',
                    page === pagination.current_page ?
                        'bg-indigo-600 text-white shadow-lg' :
                        (isDark ? 'bg-gray-700 text-gray-200 hover:bg-gray-600' : 'bg-gray-200 text-gray-700 hover:bg-gray-300')
                ]">
                    {{ page }}
                </button>
                <span v-else :class="['px-2 py-2 text-sm', isDark ? 'text-gray-500' : 'text-gray-400']">
                    ...
                </span>
            </template>

            <!-- Next Button -->
            <button @click="goToPage(pagination.current_page + 1)"
                :disabled="pagination.current_page >= pagination.last_page" :class="[
                    'px-3 py-2 rounded-lg text-sm font-medium transition-all focus:outline-none focus:ring-2 focus:ring-indigo-500',
                    pagination.current_page >= pagination.last_page ?
                        (isDark ? 'bg-gray-700 text-gray-500 cursor-not-allowed' : 'bg-gray-100 text-gray-400 cursor-not-allowed') :
                        (isDark ? 'bg-gray-700 text-gray-200 hover:bg-gray-600' : 'bg-gray-200 text-gray-700 hover:bg-gray-300')
                ]">
                {{ t('pg_next') }} →
            </button>
        </div>

        <!-- Per Page Selector -->
        <div class="flex items-center space-x-2">
            <label :class="['text-sm', isDark ? 'text-gray-300' : 'text-gray-700']">
                {{ t('pg_per_page') }}:
            </label>
            <select :value="pagination.per_page" @change="changePerPage($event.target.value)" :class="[
                'rounded-lg border-0 ring-1 ring-inset focus:ring-2 focus:ring-indigo-500 transition-all px-3 py-1 text-sm',
                isDark ? 'bg-gray-700 text-white ring-gray-600' : 'bg-gray-50 text-gray-900 ring-gray-300'
            ]">
                <option value="10">10</option>
                <option value="20">20</option>
                <option value="50">50</option>
                <option value="100">100</option>
            </select>
        </div>
    </div>
</template>

<script setup>
import { computed } from 'vue';
import { router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const props = defineProps({
    pagination: {
        type: Object,
        required: true
    },
    isDark: {
        type: Boolean,
        default: false
    },
    preserveState: {
        type: Boolean,
        default: true
    }
});

// Generate visible page numbers
const visiblePages = computed(() => {
    const current = props.pagination.current_page;
    const last = props.pagination.last_page;
    const pages = [];

    if (last <= 7) {
        for (let i = 1; i <= last; i++) {
            pages.push(i);
        }
    } else {
        // Always show first page
        pages.push(1);

        if (current > 4) {
            pages.push('...');
        }

        const start = Math.max(2, current - 1);
        const end = Math.min(last - 1, current + 1);

        for (let i = start; i <= end; i++) {
            pages.push(i);
        }

        if (current < last - 3) {
            pages.push('...');
        }

        // Always show last page
        if (last > 1) {
            pages.push(last);
        }
    }

    return pages;
});

const goToPage = (page) => {
    if (page < 1 || page > props.pagination.last_page || page === props.pagination.current_page) {
        return;
    }

    const currentParams = new URLSearchParams(window.location.search);
    currentParams.set('page', page);

    router.get(`${window.location.pathname}?${currentParams.toString()}`, {}, {
        preserveState: props.preserveState,
        preserveScroll: true
    });
};

const changePerPage = (perPage) => {
    const currentParams = new URLSearchParams(window.location.search);
    currentParams.set('per_page', perPage);
    currentParams.delete('page'); // Reset to first page when changing per_page

    router.get(`${window.location.pathname}?${currentParams.toString()}`, {}, {
        preserveState: props.preserveState,
        preserveScroll: false
    });
};
</script>
