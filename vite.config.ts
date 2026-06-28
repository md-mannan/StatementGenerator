import inertia from '@inertiajs/vite';
import { wayfinder } from '@laravel/vite-plugin-wayfinder';
import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import type { PreRenderedChunk } from 'rollup';
import { defineConfig } from 'vite';

function entryFileName(chunkInfo: PreRenderedChunk): string {
    const sourceId = chunkInfo.facadeModuleId?.replace(/\\/g, '/');

    if (sourceId?.includes('/resources/js/app.tsx')) {
        return 'assets/js/app.js';
    }

    return 'assets/[name]-[hash].js';
}

function chunkFileName(chunkInfo: PreRenderedChunk): string {
    const sourceId = chunkInfo.facadeModuleId?.replace(/\\/g, '/');

    if (sourceId?.includes('/resources/js/pages/')) {
        const relativePath = sourceId
            .split('/resources/js/')[1]
            .replace(/\.(tsx|ts|jsx|js)$/, '');

        return `assets/js/${relativePath}-[hash].js`;
    }

    return 'assets/[name]-[hash].js';
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
                entryFileNames: entryFileName,
                chunkFileNames: chunkFileName,
                assetFileNames: 'assets/[name]-[hash][extname]',
            },
        },
    },
});
