import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';

// Dentro do Docker o Vite precisa escutar em todas as interfaces e usar polling,
// pois o sistema de arquivos do Windows não avisa o container sobre alterações.
const docker = process.env.VITE_DOCKER === 'true';

export default defineConfig({
    server: docker
        ? {
              host: '0.0.0.0',
              port: 5173,
              strictPort: true,
              hmr: { host: 'localhost' },
              watch: {
                  usePolling: true,
                  interval: 1000,
                  ignored: ['**/vendor/**', '**/storage/**', '**/node_modules/**', '**/public/build/**'],
              },
          }
        : undefined,
    plugins: [
        laravel({
            input: 'resources/js/app.ts',
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
    ],
});
