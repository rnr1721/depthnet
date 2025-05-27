<template>
    <PageTitle :title="t('users')" />
    <div :class="[
        'min-h-screen transition-colors duration-300',
        isDark ? 'bg-gray-900' : 'bg-gray-50'
    ]">
        <AdminHeader :title="t('users')" :isAdmin="true" />

        <!-- Main content -->
        <main class="relative">
            <!-- Background decoration -->
            <div class="absolute inset-0 overflow-hidden pointer-events-none">
                <div :class="[
                    'absolute -top-40 -right-40 w-80 h-80 rounded-full opacity-10 blur-3xl',
                    isDark ? 'bg-blue-500' : 'bg-blue-300'
                ]"></div>
                <div :class="[
                    'absolute -bottom-40 -left-40 w-80 h-80 rounded-full opacity-10 blur-3xl',
                    isDark ? 'bg-emerald-500' : 'bg-emerald-300'
                ]"></div>
            </div>

            <div class="relative max-w-7xl mx-auto py-8 px-4 sm:px-6 lg:px-8">
                <!-- Success/Error Messages -->
                <Transition enter-active-class="transition ease-out duration-300"
                    enter-from-class="transform opacity-0 scale-95" enter-to-class="transform opacity-100 scale-100"
                    leave-active-class="transition ease-in duration-200"
                    leave-from-class="transform opacity-100 scale-100" leave-to-class="transform opacity-0 scale-95">
                    <div v-if="$page.props.flash.success" :class="[
                        'mb-6 p-4 rounded-xl border-l-4 backdrop-blur-sm',
                        isDark
                            ? 'bg-green-900 bg-opacity-50 border-green-400 text-green-200'
                            : 'bg-green-50 border-green-400 text-green-800'
                    ]">
                        <div class="flex items-center">
                            <svg class="w-5 h-5 mr-3 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd"
                                    d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z"
                                    clip-rule="evenodd"></path>
                            </svg>
                            <span class="font-medium">{{ $page.props.flash.success }}</span>
                        </div>
                    </div>
                </Transition>

                <!-- Header Section -->
                <div :class="[
                    'backdrop-blur-sm border shadow-xl rounded-2xl overflow-hidden transition-all mb-8',
                    isDark
                        ? 'bg-gray-800 bg-opacity-90 border-gray-700'
                        : 'bg-white bg-opacity-90 border-gray-200'
                ]">
                    <div :class="[
                        'px-6 py-8 border-b flex flex-col sm:flex-row sm:items-center sm:justify-between',
                        isDark ? 'border-gray-700' : 'border-gray-200'
                    ]">
                        <div class="flex items-center space-x-4 mb-4 sm:mb-0">
                            <div
                                class="w-16 h-16 bg-gradient-to-br from-blue-500 to-purple-600 rounded-2xl flex items-center justify-center">
                                <svg class="w-8 h-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197m13.5-9a2.5 2.5 0 11-5 0 2.5 2.5 0 015 0z">
                                    </path>
                                </svg>
                            </div>
                            <div>
                                <h1 :class="[
                                    'text-3xl font-bold',
                                    isDark ? 'text-white' : 'text-gray-900'
                                ]">{{ t('u_header') }}</h1>
                                <p :class="[
                                    'text-sm mt-1',
                                    isDark ? 'text-gray-400' : 'text-gray-600'
                                ]">{{ t('u_description') }}</p>
                            </div>
                        </div>

                        <div class="flex items-center space-x-3">
                            <!-- Export Button -->
                            <button @click="exportUsers" :disabled="exportLoading" :class="[
                                'px-4 py-3 rounded-xl font-semibold transition-all duration-200 transform',
                                'focus:outline-none focus:ring-2 focus:ring-offset-2',
                                exportLoading ? 'cursor-not-allowed opacity-50' : 'hover:scale-105 active:scale-95',
                                'bg-gradient-to-r from-green-600 to-emerald-600 hover:from-green-700 hover:to-emerald-700',
                                'text-white shadow-lg',
                                isDark ? 'focus:ring-green-500 focus:ring-offset-gray-800' : 'focus:ring-green-500'
                            ]">
                                <span class="flex items-center">
                                    <div v-if="exportLoading" class="flex items-center">
                                        <svg class="animate-spin -ml-1 mr-2 h-4 w-4 text-white" fill="none"
                                            viewBox="0 0 24 24">
                                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor"
                                                stroke-width="4"></circle>
                                            <path class="opacity-75" fill="currentColor"
                                                d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z">
                                            </path>
                                        </svg>
                                        {{ t('u_export_process') }}...
                                    </div>
                                    <div v-else class="flex items-center">
                                        <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z">
                                            </path>
                                        </svg>
                                        {{ t('u_export') }}
                                    </div>
                                </span>
                            </button>

                            <!-- Add User Button -->
                            <button @click="showCreateModal = true" :class="[
                                'px-6 py-3 rounded-xl font-semibold transition-all duration-200 transform',
                                'focus:outline-none focus:ring-2 focus:ring-offset-2',
                                'hover:scale-105 active:scale-95',
                                'bg-gradient-to-r from-blue-600 to-purple-600 hover:from-blue-700 hover:to-purple-700',
                                'text-white shadow-lg',
                                isDark ? 'focus:ring-blue-500 focus:ring-offset-gray-800' : 'focus:ring-blue-500'
                            ]">
                                <span class="flex items-center">
                                    <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                            d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                                    </svg>
                                    {{ t('u_user_add') }}
                                </span>
                            </button>
                        </div>
                    </div>

                    <!-- Search and Filters -->
                    <div class="p-6">
                        <div class="flex flex-col sm:flex-row space-y-4 sm:space-y-0 sm:space-x-4">
                            <!-- Search -->
                            <div class="relative flex-1">
                                <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                    <svg class="w-5 h-5" :class="isDark ? 'text-gray-400' : 'text-gray-500'" fill="none"
                                        stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                            d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                                    </svg>
                                </div>
                                <input v-model="searchQuery" type="text" :class="[
                                    'w-full rounded-xl border-0 ring-1 ring-inset focus:ring-2 focus:ring-blue-500 transition-all',
                                    'pl-10 pr-4 py-3 text-sm',
                                    isDark
                                        ? 'bg-gray-700 text-white placeholder-gray-400 ring-gray-600'
                                        : 'bg-gray-50 text-gray-900 placeholder-gray-500 ring-gray-300'
                                ]" :placeholder="t('u_user_search_ph')">
                            </div>

                            <!-- Filter -->
                            <select v-model="filterRole" :class="[
                                'rounded-xl border-0 ring-1 ring-inset focus:ring-2 focus:ring-blue-500 transition-all',
                                'px-4 py-3 text-sm min-w-[200px]',
                                isDark
                                    ? 'bg-gray-700 text-white ring-gray-600'
                                    : 'bg-gray-50 text-gray-900 ring-gray-300'
                            ]">
                                <option value="">{{ t('u_roles_all') }}</option>
                                <option value="admin">{{ t('u_roles_admin') }}</option>
                                <option value="user">{{ t('u_roles_user') }}</option>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- Users Table -->
                <div :class="[
                    'backdrop-blur-sm border shadow-xl rounded-2xl overflow-hidden transition-all',
                    isDark
                        ? 'bg-gray-800 bg-opacity-90 border-gray-700'
                        : 'bg-white bg-opacity-90 border-gray-200'
                ]">
                    <!-- Table Header -->
                    <div :class="[
                        'px-6 py-4 border-b',
                        isDark ? 'border-gray-700 bg-gray-800' : 'border-gray-200 bg-gray-50'
                    ]">
                        <div class="flex items-center justify-between">
                            <h2 :class="[
                                'text-lg font-semibold',
                                isDark ? 'text-white' : 'text-gray-900'
                            ]">
                                {{ t('u_users_found') }}: {{ users.total }}
                            </h2>

                            <div class="flex items-center space-x-2 text-sm"
                                :class="isDark ? 'text-gray-400' : 'text-gray-600'">
                                <span class="flex items-center">
                                    <div class="w-3 h-3 bg-green-500 rounded-full mr-2"></div>
                                    {{ t('u_admins') }}: {{ adminCount }}
                                </span>
                                <span class="flex items-center ml-4">
                                    <div class="w-3 h-3 bg-blue-500 rounded-full mr-2"></div>
                                    {{ t('u_users') }}: {{ userCount }}
                                </span>
                            </div>
                        </div>
                    </div>

                    <!-- Table Content -->
                    <div class="overflow-x-auto">
                        <table class="w-full">
                            <thead>
                                <tr :class="isDark ? 'bg-gray-800' : 'bg-gray-50'">
                                    <th :class="[
                                        'px-6 py-4 text-left text-sm font-semibold',
                                        isDark ? 'text-gray-300' : 'text-gray-700'
                                    ]">{{ t('u_user') }}</th>
                                    <th :class="[
                                        'px-6 py-4 text-left text-sm font-semibold',
                                        isDark ? 'text-gray-300' : 'text-gray-700'
                                    ]">{{ t('u_email') }}</th>
                                    <th :class="[
                                        'px-6 py-4 text-left text-sm font-semibold',
                                        isDark ? 'text-gray-300' : 'text-gray-700'
                                    ]">{{ t('u_role') }}</th>
                                    <th :class="[
                                        'px-6 py-4 text-left text-sm font-semibold',
                                        isDark ? 'text-gray-300' : 'text-gray-700'
                                    ]">{{ t('u_created') }}</th>
                                    <th :class="[
                                        'px-6 py-4 text-center text-sm font-semibold',
                                        isDark ? 'text-gray-300' : 'text-gray-700'
                                    ]">{{ t('u_actions') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y" :class="isDark ? 'divide-gray-700' : 'divide-gray-200'">
                                <tr v-for="user in users.data" :key="user.id" :class="[
                                    'transition-colors duration-200',
                                    isDark ? 'hover:bg-gray-700' : 'hover:bg-gray-50'
                                ]">
                                    <!-- User Info -->
                                    <td class="px-6 py-4">
                                        <div class="flex items-center space-x-3">
                                            <div :class="[
                                                'w-10 h-10 rounded-xl flex items-center justify-center text-white font-bold',
                                                user.is_admin
                                                    ? 'bg-gradient-to-br from-yellow-500 to-orange-600'
                                                    : 'bg-gradient-to-br from-blue-500 to-purple-600'
                                            ]">
                                                {{ user.name.charAt(0).toUpperCase() }}
                                            </div>
                                            <div>
                                                <div :class="[
                                                    'font-semibold',
                                                    isDark ? 'text-white' : 'text-gray-900'
                                                ]">{{ user.name }}</div>
                                                <div :class="[
                                                    'text-sm',
                                                    isDark ? 'text-gray-400' : 'text-gray-600'
                                                ]">ID: {{ user.id }}</div>
                                            </div>
                                        </div>
                                    </td>

                                    <!-- Email -->
                                    <td class="px-6 py-4">
                                        <div :class="[
                                            'text-sm',
                                            isDark ? 'text-gray-300' : 'text-gray-700'
                                        ]">{{ user.email }}</div>
                                    </td>

                                    <!-- Role -->
                                    <td class="px-6 py-4">
                                        <span :class="[
                                            'inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold',
                                            user.is_admin
                                                ? (isDark ? 'bg-yellow-900 text-yellow-200 border border-yellow-700' : 'bg-yellow-100 text-yellow-800 border border-yellow-300')
                                                : (isDark ? 'bg-blue-900 text-blue-200 border border-blue-700' : 'bg-blue-100 text-blue-800 border border-blue-300')
                                        ]">
                                            {{ user.is_admin ? t('u_admin') : t('u_user') }}
                                        </span>
                                    </td>

                                    <!-- Created At -->
                                    <td class="px-6 py-4">
                                        <div :class="[
                                            'text-sm',
                                            isDark ? 'text-gray-400' : 'text-gray-600'
                                        ]">{{ formatDate(user.created_at) }}</div>
                                    </td>

                                    <!-- Actions -->
                                    <td class="px-6 py-4">
                                        <div class="flex items-center justify-center space-x-2">
                                            <!-- Edit Button -->
                                            <button @click="editUser(user)" :class="[
                                                'p-2 rounded-lg transition-all duration-200 hover:scale-110',
                                                isDark
                                                    ? 'bg-blue-900 text-blue-400 hover:bg-blue-800'
                                                    : 'bg-blue-100 text-blue-600 hover:bg-blue-200'
                                            ]" :title="t('u_edit')">
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor"
                                                    viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round"
                                                        stroke-width="2"
                                                        d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z">
                                                    </path>
                                                </svg>
                                            </button>

                                            <!-- Toggle Admin Button -->
                                            <button @click="toggleAdmin(user)" :disabled="isTogglingAdmin(user.id)"
                                                :class="[
                                                    'p-2 rounded-lg transition-all duration-200 relative',
                                                    isTogglingAdmin(user.id)
                                                        ? 'cursor-not-allowed opacity-50'
                                                        : 'hover:scale-110',
                                                    user.is_admin
                                                        ? (isDark ? 'bg-orange-900 text-orange-400 hover:bg-orange-800' : 'bg-orange-100 text-orange-600 hover:bg-orange-200')
                                                        : (isDark ? 'bg-green-900 text-green-400 hover:bg-green-800' : 'bg-green-100 text-green-600 hover:bg-green-200')
                                                ]"
                                                :title="isTogglingAdmin(user.id) ? t('u_role_changing') : (user.is_admin ? t('u_remove_admin') : t('u_make_admin'))">
                                                <!-- Loading Spinner -->
                                                <div v-if="isTogglingAdmin(user.id)"
                                                    class="flex items-center justify-center">
                                                    <svg class="animate-spin h-4 w-4" fill="none" viewBox="0 0 24 24">
                                                        <circle class="opacity-25" cx="12" cy="12" r="10"
                                                            stroke="currentColor" stroke-width="4"></circle>
                                                        <path class="opacity-75" fill="currentColor"
                                                            d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z">
                                                        </path>
                                                    </svg>
                                                </div>

                                                <!-- Normal Icons -->
                                                <template v-else>
                                                    <!-- Remove Admin Icon -->
                                                    <svg v-if="user.is_admin" class="w-4 h-4" fill="none"
                                                        stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round"
                                                            stroke-width="2"
                                                            d="M13 7a4 4 0 11-8 0 4 4 0 018 0zM9 14a3 3 0 00-3 3v1h12v-1a3 3 0 00-3-3H9z">
                                                        </path>
                                                    </svg>

                                                    <!-- Make Admin Icon -->
                                                    <svg v-else class="w-4 h-4" fill="none" stroke="currentColor"
                                                        viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round"
                                                            stroke-width="2"
                                                            d="M5 3v4M3 5h4M6 17v4m-2-2h4m5-16l2.286 6.857L21 12l-5.714 2.143L13 21l-2.286-6.857L5 12l5.714-2.143L13 3z">
                                                        </path>
                                                    </svg>
                                                </template>
                                            </button>

                                            <!-- Delete Button -->
                                            <button @click="deleteUser(user)" v-if="user.id !== currentUserId" :class="[
                                                'p-2 rounded-lg transition-all duration-200 hover:scale-110',
                                                isDark
                                                    ? 'bg-red-900 text-red-400 hover:bg-red-800'
                                                    : 'bg-red-100 text-red-600 hover:bg-red-200'
                                            ]" :title="t('u_remove')">
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor"
                                                    viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round"
                                                        stroke-width="2"
                                                        d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16">
                                                    </path>
                                                </svg>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- Pagination -->
                    <div v-if="users.last_page > 1" :class="[
                        'px-6 py-4 border-t flex items-center justify-between',
                        isDark ? 'border-gray-700' : 'border-gray-200'
                    ]">
                        <div :class="[
                            'text-sm',
                            isDark ? 'text-gray-400' : 'text-gray-600'
                        ]">
                            {{ users.from }}-{{ users.to }} / {{ users.total }}
                        </div>

                        <div class="flex items-center space-x-2">
                            <button @click="goToPage(users.current_page - 1)" :disabled="!users.prev_page_url" :class="[
                                'px-3 py-2 rounded-lg text-sm font-medium transition-all',
                                !users.prev_page_url
                                    ? (isDark ? 'bg-gray-800 text-gray-600 cursor-not-allowed' : 'bg-gray-100 text-gray-400 cursor-not-allowed')
                                    : (isDark ? 'bg-gray-700 text-white hover:bg-gray-600' : 'bg-white text-gray-700 hover:bg-gray-50')
                            ]">
                                {{ t('u_back') }}
                            </button>

                            <span :class="[
                                'px-4 py-2 text-sm font-medium',
                                isDark ? 'text-gray-300' : 'text-gray-700'
                            ]">
                                {{ users.current_page }} / {{ users.last_page }}
                            </span>

                            <button @click="goToPage(users.current_page + 1)" :disabled="!users.next_page_url" :class="[
                                'px-3 py-2 rounded-lg text-sm font-medium transition-all',
                                !users.next_page_url
                                    ? (isDark ? 'bg-gray-800 text-gray-600 cursor-not-allowed' : 'bg-gray-100 text-gray-400 cursor-not-allowed')
                                    : (isDark ? 'bg-gray-700 text-white hover:bg-gray-600' : 'bg-white text-gray-700 hover:bg-gray-50')
                            ]">
                                {{ t('u_next') }}
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </main>

        <!-- Modals -->
        <CreateUserModal :show="showCreateModal" :isDark="isDark" @close="showCreateModal = false"
            @created="handleUserCreated" />

        <EditUserModal :show="showEditModal" :isDark="isDark" :user="editingUser" @close="closeEditModal"
            @updated="handleUserUpdated" />

        <DeleteUserModal :show="showDeleteModal" :isDark="isDark" :user="deletingUser" @close="closeDeleteModal"
            @deleted="handleUserDeleted" />
    </div>
</template>

<script setup>
import { ref, computed, onMounted, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import PageTitle from '@/Components/PageTitle.vue';
import AdminHeader from '@/Components/AdminHeader.vue';
import CreateUserModal from '@/Components/Admin/CreateUserModal.vue';
import EditUserModal from '@/Components/Admin/EditUserModal.vue';
import DeleteUserModal from '@/Components/Admin/DeleteUserModal.vue';

const { t } = useI18n();

const isDark = ref(false);

const props = defineProps({
    users: Object, // Laravel paginated obj
    currentUserId: Number,
    filters: Object
});

const searchQuery = ref(props.filters?.search || '');
const filterRole = ref(props.filters?.role || '');
const showCreateModal = ref(false);
const toggleAdminLoading = ref(new Set());
const showDeleteModal = ref(false);
const deletingUser = ref(null);
const showEditModal = ref(false);
const editingUser = ref(null);
const exportLoading = ref(false);

const adminCount = computed(() => {
    return props.users.data.filter(user => user.is_admin).length;
});

const userCount = computed(() => {
    return props.users.data.filter(user => !user.is_admin).length;
});

let searchTimeout = null;
watch([searchQuery, filterRole], ([newSearch, newRole]) => {
    if (searchTimeout) {
        clearTimeout(searchTimeout);
    }

    searchTimeout = setTimeout(() => {
        updateFilters();
    }, 500);
});

const updateFilters = () => {
    const params = {};

    if (searchQuery.value) {
        params.search = searchQuery.value;
    }

    if (filterRole.value) {
        params.role = filterRole.value;
    }

    router.get(route('admin.users.index'), params, {
        preserveState: true,
        preserveScroll: true,
        replace: true
    });
};

const goToPage = (page) => {
    if (page < 1 || page > props.users.last_page) {
        return;
    }

    const params = {
        page: page
    };

    if (searchQuery.value) {
        params.search = searchQuery.value;
    }

    if (filterRole.value) {
        params.role = filterRole.value;
    }

    router.get(route('admin.users.index'), params, {
        preserveState: true,
        preserveScroll: true
    });
};

const formatDate = (dateString) => {
    return new Date(dateString).toLocaleDateString('ru-RU', {
        year: 'numeric',
        month: 'short',
        day: 'numeric'
    });
};

const editUser = (user) => {
    editingUser.value = user;
    showEditModal.value = true;
};

const toggleAdmin = async (user) => {
    if (toggleAdminLoading.value.has(user.id)) {
        return;
    }
    const newAdminStatus = !user.is_admin;
    const action = user.is_admin ? 'remove administrator rights from' : 'grant administrator rights';

    const confirmed = confirm(
        `Are you sure you want to ${action} for user "${user.name}"?\n\n` +
        `Current: ${user.is_admin ? 'Admin' : 'User'}\n` +
        `New role: ${user.is_admin ? 'User' : 'Admin'}`
    );

    if (!confirmed) {
        return;
    }

    toggleAdminLoading.value.add(user.id);

    try {
        await router.patch(`/admin/users/${user.id}/toggle-admin`, {
            is_admin: newAdminStatus
        }, {
            onSuccess: (page) => {
                console.log(`User role "${user.name}" successfully changed!`);
            },
            onError: (errors) => {
                console.error('Error changing role:', errors);
                alert(`Failed to change user role "${user.name}"`);
            },
            onFinish: () => {
                toggleAdminLoading.value.delete(user.id);
            }
        });
    } catch (error) {
        console.error('Error:', error);
        toggleAdminLoading.value.delete(user.id);
        alert('An error occurred while changing the user role');
    }
};

const isTogglingAdmin = (userId) => {
    return toggleAdminLoading.value.has(userId);
};

const deleteUser = (user) => {
    deletingUser.value = user;
    showDeleteModal.value = true;
};

const handleUserDeleted = () => {
    const params = {};

    if (searchQuery.value) {
        params.search = searchQuery.value;
    }

    if (filterRole.value) {
        params.role = filterRole.value;
    }

    if (props.users.current_page > 1) {
        params.page = props.users.current_page;
    }

    router.get(route('admin.users.index'), params);
};

const closeDeleteModal = () => {
    showDeleteModal.value = false;
    deletingUser.value = null;
};

const handleUserCreated = () => {
    const params = {};

    if (searchQuery.value) {
        params.search = searchQuery.value;
    }

    if (filterRole.value) {
        params.role = filterRole.value;
    }

    router.get(route('admin.users.index'), params);
};

const handleUserUpdated = () => {

    document.body.style.overflow = '';

    const params = {};

    if (searchQuery.value) {
        params.search = searchQuery.value;
    }

    if (filterRole.value) {
        params.role = filterRole.value;
    }

    if (props.users.current_page > 1) {
        params.page = props.users.current_page;
    }
    console.log('params:', params);
    router.get(route('admin.users.index'), params);
};

const closeEditModal = () => {
    showEditModal.value = false;
    editingUser.value = null;
};

const exportUsers = () => {
    exportLoading.value = true;
    console.log('Start export users process...');

    window.location.href = '/admin/users/export';

    setTimeout(() => {
        exportLoading.value = false;
    }, 1000);
};

onMounted(() => {
    const savedTheme = localStorage.getItem('chat-theme');
    if (savedTheme === 'dark' || (!savedTheme && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
        isDark.value = true;
        document.documentElement.classList.add('dark');
    }

    window.addEventListener('theme-changed', (event) => {
        isDark.value = event.detail.isDark;
    });
});
</script>