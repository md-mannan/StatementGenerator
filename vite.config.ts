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

        return `assets/js/${relativePath}-[hash].js`;
    }

    return `assets/${chunkInfo.name}-[hash].js`;
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
                assetFileNames: 'assets/[name]-[hash][extname]',
            },
        },
    },
});
