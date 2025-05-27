<template>

    <Head :title="computedTitle" />
</template>

<script setup>
import { Head, usePage } from '@inertiajs/vue3'
import { computed } from 'vue'

const props = defineProps({
    title: {
        type: String,
        default: null
    }
})

const page = usePage()
const appName = page.props.app_name || 'DepthNet'

const computedTitle = computed(() => {
    if (!props.title) {
        return appName
    }

    if (props.title.includes(appName)) {
        return props.title
    }

    return `${props.title} - ${appName}`
})
</script>
