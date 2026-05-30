import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';
import tailwindcss from '@tailwindcss/vite';
import { visualizer } from 'rollup-plugin-visualizer';

const analyzeBundle = process.env.ANALYZE === '1' || process.env.ANALYZE === 'true';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js', 'resources/js/member-builder.js'],
            refresh: true,
        }),
        vue({
            template: {
                transformAssetUrls: {
                    base: null,
                    includeAbsolute: false,
                },
                compilerOptions: {
                    isCustomElement: (tag) => tag.startsWith('vds-') || tag.startsWith('media-'),
                },
            },
        }),
        tailwindcss(),
        ...(analyzeBundle
            ? [
                  visualizer({
                      filename: 'storage/app/bundle-stats.html',
                      gzipSize: true,
                      open: false,
                  }),
              ]
            : []),
    ],
    resolve: {
        alias: {
            '@': '/resources/js',
        },
    },
    build: {
        rollupOptions: {
            output: {
                manualChunks(id) {
                    if (!id.includes('node_modules')) {
                        return undefined;
                    }
                    if (id.includes('vidstack')) {
                        return 'vendor-vidstack';
                    }
                    if (id.includes('apexcharts') || id.includes('vue3-apexcharts')) {
                        return 'vendor-charts';
                    }
                    if (id.includes('firebase')) {
                        return 'vendor-firebase';
                    }
                    if (id.includes('@codemirror') || id.includes('/codemirror')) {
                        return 'vendor-codemirror';
                    }
                    if (id.includes('@inertiajs') || id.includes('/vue/') || id.includes('\\vue\\')) {
                        return 'vendor-vue';
                    }
                    if (id.includes('lucide-vue-next')) {
                        return 'vendor-icons';
                    }

                    return 'vendor-misc';
                },
            },
        },
    },
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
