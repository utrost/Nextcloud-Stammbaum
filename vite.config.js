import { defineConfig } from 'vite';
import vue from '@vitejs/plugin-vue';
export default defineConfig({
  plugins: [vue()],
  define: { 'process.env.NODE_ENV': JSON.stringify('production') },
  build: {
    outDir: 'dist',
    sourcemap: false,
    lib: {
      entry: 'src/main.js',
      formats: ['iife'],
      name: 'Stammbaum',
      fileName: () => 'main.js',
      cssFileName: 'main',
    },
    rollupOptions: { output: { inlineDynamicImports: true } },
  },
});
