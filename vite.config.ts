import inertia from '@inertiajs/vite';
import { wayfinder } from '@laravel/vite-plugin-wayfinder';
import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import type { PreRenderedChunk } from 'rollup';
import { defineConfig } from 'vite';

function jsAssetName(chunkInfo: PreRenderedChunk): string {
    const sourceId = chunkInfo.facadeModuleId?.replace(/\\/g, '/');

    if (sourceId?.includes('/resources/js/')) {
        const relativePath = sourceId
            .split('/resources/js/')[1]
            .replace(/\.(tsx|ts|jsx|js)$/, '');

        return `assets/js/${relativePath}.js`;
    }

    return `assets/${chunkInfo.name}.js`;
}

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.tsx'],
            refresh: true,
            fonts: [
                bunny('Instrument Sans', {
                    weights: [400, 500, 600],
                }),
            ],
        }),
        inertia(),
        react({
            babel: {
                plugins: ['babel-plugin-react-compiler'],
            },
        }),
        tailwindcss(),
        wayfinder({
            formVariants: true,
        }),
    ],
    optimizeDeps: {
        include: ['@radix-ui/react-collapsible'],
    },
    build: {
        rollupOptions: {
            output: {
                entryFileNames: jsAssetName,
                chunkFileNames: jsAssetName,
                assetFileNames: 'assets/[name][extname]',
                manualChunks(id) {
                    if (
                        id.includes('node_modules/react/') ||
                        id.includes('node_modules/react-dom/') ||
                        id.includes('node_modules/@inertiajs/react/')
                    ) {
                        return 'vendor';
                    }

                    if (
                        id.includes('node_modules/@radix-ui/react-dialog/') ||
                        id.includes('node_modules/@radix-ui/react-dropdown-menu/') ||
                        id.includes('node_modules/@radix-ui/react-select/') ||
                        id.includes('node_modules/@radix-ui/react-collapsible/') ||
                        id.includes('node_modules/@radix-ui/react-slot/')
                    ) {
                        return 'radix';
                    }

                    if (id.includes('node_modules/lucide-react/')) {
                        return 'icons';
                    }
                },
            },
        },
    },
});
