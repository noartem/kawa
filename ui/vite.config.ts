import { wayfinder } from '@laravel/vite-plugin-wayfinder';
import tailwindcss from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue';
import laravel from 'laravel-vite-plugin';
import { defineConfig, loadEnv } from 'vite';

export default defineConfig(({ mode }) => {
    const env = loadEnv(mode, process.cwd(), '');
    const devServerPort = Number(env.VITE_PORT || 5173);
    const devServerOrigin = env.VITE_ORIGIN || undefined;
    const allowedDevOrigins = [
        /^https?:\/\/(?:(?:[^:]+\.)?localhost|127\.0\.0\.1|\[::1\])(?::\d+)?$/,
        ...(env.APP_URL ? [env.APP_URL] : []),
    ];
    const usePolling = ['1', 'true', 'yes', 'on'].includes(
        (env.VITE_USE_POLLING || '').toLowerCase(),
    );

    return {
        server: {
            host: env.VITE_HOST || 'localhost',
            port: devServerPort,
            strictPort: true,
            origin: devServerOrigin,
            cors: {
                origin: allowedDevOrigins,
            },
            watch: {
                ignored: ['**/*.swp', '**/*.swo', '**/*~', '**/.#*'],
                ...(usePolling
                    ? {
                          usePolling: true,
                          interval: Number(env.VITE_WATCH_INTERVAL || 250),
                      }
                    : {}),
            },
        },
        plugins: [
            laravel({
                input: ['resources/js/app.ts'],
                ssr: 'resources/js/ssr.ts',
                refresh: [
                    'app/View/Components/**',
                    'lang/**',
                    'resources/lang/**',
                    'resources/views/**',
                    'routes/**',
                ],
            }),
            tailwindcss(),
            wayfinder({
                formVariants: true,
            }),
            vue({
                template: {
                    transformAssetUrls: {
                        base: null,
                        includeAbsolute: false,
                    },
                },
            }),
        ],
    };
});
