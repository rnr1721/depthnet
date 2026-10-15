<template>
    <div>
        <label :class="labelClass">
            {{ field.label }}
            <span v-if="field.required" class="text-red-400 ml-0.5">*</span>
        </label>

        <!-- password -->
        <input v-if="field.type === 'password'" type="password" v-model="model" :placeholder="field.placeholder ?? ''"
            :autocomplete="autocomplete" :class="[inputClass, error ? errorBorderClass : '']" />

        <!-- select -->
        <select v-else-if="field.type === 'select'" v-model="model"
            :class="[inputClass, error ? errorBorderClass : '']">
            <option v-for="(label, val) in (field.options ?? {})" :key="val" :value="val">
                {{ label }}
            </option>
        </select>

        <!-- number -->
        <input v-else-if="field.type === 'number'" type="number" v-model="model" :min="field.min" :max="field.max"
            :step="field.step ?? 1" :placeholder="field.placeholder ?? ''"
            :class="[inputClass, error ? errorBorderClass : '']" />

        <!-- checkbox -->
        <button v-else-if="field.type === 'checkbox'" type="button" @click="model = !model" :class="[
            'relative inline-flex h-5 w-9 items-center rounded-full transition-colors focus:outline-none',
            model ? 'bg-indigo-600' : isDark ? 'bg-gray-600' : 'bg-gray-300'
        ]">
            <span :class="[
                'inline-block h-3.5 w-3.5 transform rounded-full bg-white transition-transform',
                model ? 'translate-x-4' : 'translate-x-1'
            ]"></span>
        </button>

        <!-- textarea -->
        <textarea v-else-if="field.type === 'textarea'" v-model="model" :rows="field.rows ?? 3"
            :placeholder="field.placeholder ?? ''"
            :class="[inputClass, 'resize-y font-mono', error ? errorBorderClass : '']"></textarea>

        <!-- url / text (+ optional trailing controls, e.g. "load models") -->
        <template v-else>
            <div class="flex gap-2">
                <input type="text" v-model="model" :placeholder="field.placeholder ?? ''" :list="list"
                    :autocomplete="autocomplete" :class="[inputClass, error ? errorBorderClass : '', 'flex-1']" />
                <slot name="append" />
            </div>
        </template>

        <slot name="below" />

        <p v-if="field.description" :class="['text-xs mt-1', isDark ? 'text-gray-500' : 'text-gray-400']">
            {{ field.description }}
        </p>
        <p v-if="error" class="text-xs mt-1 text-red-400">
            {{ error }}
        </p>
    </div>
</template>

<script setup>
import { computed } from 'vue';

/**
 * One form field rendered from a schema entry — the same schema used by
 * CapabilityProviderInterface::getConfigFields() and by AuthStep::$fields.
 *
 * Supported types: text, url, password, select, number, checkbox, textarea.
 * Unknown types render as text.
 */
const props = defineProps({
    field: { type: Object, required: true },
    modelValue: { default: null },
    isDark: { type: Boolean, default: false },
    error: { type: String, default: null },
    list: { type: String, default: undefined },
    // Login steps pass "one-time-code" / "tel" etc.; config forms leave it off.
    autocomplete: { type: String, default: 'off' },
});

const emit = defineEmits(['update:modelValue']);

// Proxy through a computed so v-model keeps Vue's built-in number casting
// for type="number", same as the inline v-model the card used before.
const model = computed({
    get: () => props.modelValue,
    set: (v) => emit('update:modelValue', v),
});

const labelClass = computed(() =>
    ['block text-sm font-medium mb-1.5', props.isDark ? 'text-gray-300' : 'text-gray-700'].join(' ')
);

const inputClass = computed(() => [
    'w-full px-3 py-2 rounded-lg border text-sm transition-colors focus:outline-none focus:ring-2 focus:ring-indigo-500',
    props.isDark
        ? 'bg-gray-700 border-gray-600 text-white placeholder-gray-400'
        : 'bg-white border-gray-300 text-gray-900 placeholder-gray-400',
].join(' '));

const errorBorderClass = 'border-red-500 focus:ring-red-500';
</script>
