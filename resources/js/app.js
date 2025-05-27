import './bootstrap';
import { createApp, h } from 'vue';
import { createInertiaApp } from '@inertiajs/vue3';
import { InertiaProgress } from '@inertiajs/progress';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { ZiggyVue } from 'ziggy-js';
import { Ziggy } from './ziggy';

import { createI18n } from 'vue-i18n';
import ru from './lang/ru'
import en from './lang/en'

InertiaProgress.init();

createInertiaApp({
    resolve: name => resolvePageComponent(`./Pages/${name}.vue`, import.meta.glob('./Pages/**/*.vue')),

    setup({ el, App, props, plugin }) {
        const locale = props.initialPage.props.locale || 'en';

        const i18n = createI18n({
            legacy: false,
            locale: locale,
            fallbackLocale: 'en',
            messages: {
                ru,
                en
            }
        });

        createApp({ render: () => h(App, props) })
            .use(plugin)
            .use(ZiggyVue, Ziggy)
            .use(i18n)
            .mount(el);
    },
});
