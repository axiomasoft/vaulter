import { fileURLToPath, URL } from 'node:url'
import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import dts from 'vite-plugin-dts'

// Library build: type-check + emit declarations, externalize Vue (and the
// optional realtime/editor stack used from M3 onward), ship ES + UMD bundles.
export default defineConfig({
  plugins: [
    vue(),
    dts({ tsconfigPath: './tsconfig.json', include: ['src'], exclude: ['tests'] }),
  ],
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url)),
    },
  },
  build: {
    lib: {
      entry: fileURLToPath(new URL('./src/index.ts', import.meta.url)),
      name: 'DocVaultVue',
      fileName: 'docvault-vue',
    },
    rollupOptions: {
      external: ['vue', 'yjs', /^@tiptap\//, /^@hocuspocus\//],
      output: {
        exports: 'named',
        globals: {
          'vue': 'Vue',
          'yjs': 'Y',
          '@hocuspocus/provider': 'Hocuspocus',
        },
      },
    },
  },
  test: {
    environment: 'happy-dom',
  },
})
