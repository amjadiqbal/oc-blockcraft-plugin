import { defineConfig } from 'vite';
import vue from '@vitejs/plugin-vue';
import { resolve } from 'node:path';

// BlockCraft ships a single hydrator entry (assets/js/blockcraft.ts) that
// mounts BlockCraftEditor.vue into every [data-control="blockcraft"] element
// on the page - see classes/ViteResolver.php for how October resolves the
// built/hashed output in production vs. the local Vite dev server.
export default defineConfig({
    plugins: [vue()],
    base: '/plugins/amjadiqbal/blockcraft/assets/dist/',
    build: {
        manifest: true,
        outDir: 'assets/dist',
        emptyOutDir: true,
        rollupOptions: {
            input: {
                blockcraft: resolve(__dirname, 'assets/js/blockcraft.ts'),
            },
        },
    },
    server: {
        origin: 'http://localhost:5174',
    },
});
