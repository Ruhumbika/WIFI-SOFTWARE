import { defineConfig, loadEnv } from 'vite'
import vue from '@vitejs/plugin-vue'

export default defineConfig(({ mode }) => {
  const env = loadEnv(mode, process.cwd(), 'VITE_')
  if (mode === 'development' && (!env.VITE_DEV_API_TARGET || !env.VITE_DEV_HOST || !env.VITE_DEV_PORT)) {
    throw new Error('Set VITE_DEV_API_TARGET, VITE_DEV_HOST and VITE_DEV_PORT in frontend/.env')
  }
  return {
    plugins: [vue()],
    server: mode === 'development' ? {
      host: env.VITE_DEV_HOST,
      port: Number(env.VITE_DEV_PORT),
      strictPort: true,
      proxy: {
        '/api': {
          target: env.VITE_DEV_API_TARGET,
          changeOrigin: true,
        },
      },
    } : undefined,
  }
})
