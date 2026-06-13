<template>
  <div :class="['border-b last:border-b-0', isDark ? 'border-gray-700' : 'border-gray-200']">
    <div :class="[
      'px-4 py-2 text-xs font-semibold uppercase tracking-wider',
      isDark ? 'text-gray-400 bg-gray-800' : 'text-gray-500 bg-gray-50'
    ]">
      {{ title }}
    </div>
    <div class="py-1">
      <Link v-for="item in items" :key="item.label" :href="item.href" @click="closeMenu" :class="[
        'flex items-center px-4 py-2 text-sm transition-colors',
        isDark
          ? 'text-gray-200 hover:bg-gray-700'
          : 'text-gray-700 hover:bg-gray-100'
      ]">
        <span>{{ $t(item.label) }}</span>
      </Link>
    </div>
  </div>
</template>

<script setup>
import { Link } from '@inertiajs/vue3';
import { inject } from 'vue';

defineProps({
  title: { type: String, required: true },
  items: { type: Array, required: true },
  isDark: { type: Boolean, required: true },
});

const closeMenu = inject('closeAdminMenu', () => { });
</script>