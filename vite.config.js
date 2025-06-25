import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue';
import path from 'path';

const isDocker = process.env.VITE_DOCKER_ENV === 'true';
const isProduction = process.env.NODE_ENV === 'production' || process.env.APP_ENV === 'production';

/**
 * Get the appropriate host configuration based on environment
 */
function getHostConfig() {
    if (isProduction) {
        return {
            host: 'localhost',
            port: 5173,
        };
    }

    if (isDocker) {
        return {
            host: '0.0.0.0',
            port: 5173,
            strictPort: true,
        };
    }

    return {
        host: 'localhost',
        port: 5173,
    };
}

/**
 * Auto-detect HMR host from APP_URL
 */
function getHMRHost() {
    // Use explicit override if provided
    if (process.env.VITE_HMR_HOST) {
        return process.env.VITE_HMR_HOST;
    }

    // Auto-detect from APP_URL
    const appUrl = process.env.APP_URL;
    if (!appUrl) return 'localhost';

    try {
        const url = new URL(appUrl);
        return url.hostname;
    } catch {
        return 'localhost';
    }
}

/**
 * Get HMR configuration for different environments
 */
function getHMRConfig() {
    if (isProduction) {
        return false;
    }

    const hmrHost = getHMRHost();
    const hmrPort = parseInt(process.env.VITE_HMR_PORT || '5173');

    return {
        host: hmrHost,
        port: hmrPort,
        protocol: process.env.VITE_HMR_PROTOCOL || 'ws',
    };
}

/**
 * Get watch configuration for file changes
 */
function getWatchConfig() {
    if (isProduction) {
        return undefined;
    }

    if (isDocker) {
        return {
            usePolling: true,
            interval: parseInt(process.env.VITE_POLLING_INTERVAL || '1000'),
            ignored: ['**/node_modules/**', '**/vendor/**', '**/storage/**'],
        };
    }

    return {
        ignored: ['**/node_modules/**', '**/vendor/**', '**/storage/**'],
    };
}

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: !isProduction,
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
        ...getHostConfig(),
        hmr: getHMRConfig(),
        watch: getWatchConfig(),
        cors: {
            origin: process.env.VITE_CORS_ORIGIN ?
                process.env.VITE_CORS_ORIGIN.split(',').map(origin => origin.trim()) :
                true,
            credentials: true,
        },
        headers: {
            'Access-Control-Allow-Origin': '*',
            'Access-Control-Allow-Methods': 'GET, POST, PUT, DELETE, OPTIONS',
            'Access-Control-Allow-Headers': 'Origin, X-Requested-With, Content-Type, Accept, Authorization',
        },
    },

    optimizeDeps: {
        include: ['vue', '@vue/runtime-core'],
        exclude: isDocker ? [] : ['vue'],
    },

    ...(isProduction && {
        define: {
            __VUE_PROD_DEVTOOLS__: false,
            __VUE_OPTIONS_API__: true,
        },
    }),
});
