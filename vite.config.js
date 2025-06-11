import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue';
import path from 'path';

const isDocker = process.env.VITE_DOCKER_ENV === 'true';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
        }),
        vue({
            template: {
                transformAssetUrls: {
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
        tailwindcss(),
    ],
    resolve: {
        alias: {
            '@': path.resolve(__dirname, 'resources/js'),
        },
    },
    server: {
        host: isDocker ? '0.0.0.0' : 'localhost',
        port: 5173,
        strictPort: true,
        hmr: {
            host: isDocker ? '0.0.0.0' : 'localhost',
            port: 5173,
        },
        ...(isDocker && {
            watch: {
                usePolling: true,
                interval: 1000,
            },
        }),
    }
});
