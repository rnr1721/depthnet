<template>
    <!-- Attachments block — shown below message content -->
    <div v-if="attachments.length" class="mt-2 flex flex-wrap gap-2">
        <a v-for="file in attachments" :key="file.id" :href="file.id ? route('admin.documents.download', file.id) : '#'"
            :target="file.id ? '_blank' : undefined" :class="[
                'inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg text-xs transition-all',
                file.id
                    ? (isDark
                        ? 'bg-gray-700 hover:bg-gray-600 text-gray-300 hover:text-white'
                        : 'bg-gray-100 hover:bg-gray-200 text-gray-600 hover:text-gray-900')
                    : (isDark ? 'bg-gray-800 text-gray-500' : 'bg-gray-50 text-gray-400'),
                'no-underline'
            ]" :title="file.id ? file.original_name : 'File deleted'">
            <!-- File type icon -->
            <span class="flex-shrink-0">{{ fileIcon(file) }}</span>

            <!-- Name — truncated -->
            <span class="truncate max-w-40">{{ file.original_name }}</span>

            <!-- Size -->
            <span v-if="file.human_size" :class="[isDark ? 'text-gray-500' : 'text-gray-400']">
                {{ file.human_size }}
            </span>

            <!-- Deleted indicator -->
            <span v-if="!file.id" class="italic">{{ t('chat_file_deleted') }}</span>

            <!-- Download icon when available -->
            <svg v-if="file.id" class="w-3 h-3 flex-shrink-0 opacity-60" fill="none" stroke="currentColor"
                viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                    d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
            </svg>
        </a>
    </div>
</template>

<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const props = defineProps({
    metadata: {
        type: Object,
        default: () => ({}),
    },
    isDark: {
        type: Boolean,
        default: false,
    },
});

const attachments = computed(() => props.metadata?.attachments ?? []);

const fileIcon = (file) => {
    if (!file.original_name) return '📎';
    const name = file.original_name.toLowerCase();
    if (name.endsWith('.pdf')) return '📄';
    if (name.match(/\.(xls|xlsx|ods|csv)$/)) return '📊';
    if (name.match(/\.(doc|docx|odt)$/)) return '📝';
    if (name.match(/\.(jpg|jpeg|png|gif|webp|svg)$/)) return '🖼️';
    if (name.match(/\.(mp3|wav|ogg|flac)$/)) return '🎵';
    if (name.match(/\.(mp4|mov|avi|mkv)$/)) return '🎬';
    if (name.match(/\.(zip|tar|gz|7z|rar)$/)) return '🗜️';
    if (name.match(/\.(php|js|ts|py|sh|rb|go|rs)$/)) return '💻';
    if (name.match(/\.(json|yaml|yml|xml|toml)$/)) return '⚙️';
    return '📎';
};
</script>