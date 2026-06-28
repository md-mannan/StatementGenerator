import inertia from '@inertiajs/vite';
import { wayfinder } from '@laravel/vite-plugin-wayfinder';
import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import type { PreRenderedAsset, PreRenderedChunk } from 'rollup';
import { defineConfig } from 'vite';

function moduleSourcePath(chunkInfo: PreRenderedChunk): string | undefined {
    const sourceId = chunkInfo.facadeModuleId ?? chunkInfo.moduleIds.at(-1);

    return sourceId?.replace(/\\/g, '/');
}

function jsOutputPath(chunkInfo: PreRenderedChunk): string {
    const sourceId = moduleSourcePath(chunkInfo);

    if (sourceId?.includes('/resources/js/')) {
        const relativePath = sourceId
            .split('/resources/js/')[1]
            .replace(/\.(tsx|ts|jsx|js)$/, '');

        return `assets/js/${relativePath}-[hash].js`;
    }

    if (sourceId?.includes('/node_modules/')) {
        const modulePath = sourceId
            .split('/node_modules/')[1]
            .replace(/\.(tsx|ts|jsx|js|mjs|cjs)$/, '');

        return `assets/vendor/${modulePath}-[hash].js`;
    }

    return `assets/chunks/${chunkInfo.name}-[hash].js`;
}

function assetOutputPath(assetInfo: PreRenderedAsset): string {
    const sourcePath = assetInfo.originalFileNames.at(-1)?.replace(/\\/g, '/');

    if (sourcePath?.includes('/resources/css/')) {
        const relativePath = sourcePath
            .split('/resources/css/')[1]
            .replace(/\.css$/, '');

        return `assets/${relativePath}-[hash].css`;
    }

    return `assets/[name]-[hash][extname]`;
}

export default defineConfig({
    server: {
        host: '127.0.0.1',
        port: 5173,
        strictPort: true,
        hmr: {
            host: '127.0.0.1',
        },
    },
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
                entryFileNames: jsOutputPath,
                chunkFileNames: jsOutputPath,
                assetFileNames: assetOutputPath,
            },
        },
    },
});
