import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import { VitePWA } from 'vite-plugin-pwa'

const proxy = {
  target: 'http://127.0.0.1:8787',
  changeOrigin: true,
  secure: true,
}

export default defineConfig({
  plugins: [
    vue(),
    VitePWA({
      registerType: 'autoUpdate',
      includeAssets: ['favicon.png', 'icons/apple-touch-icon.png'],
      manifest: {
        id: '/',
        name: 'MGames',
        short_name: 'MGames',
        description: 'Responsive game lobby',
        start_url: '/',
        scope: '/',
        display: 'standalone',
        background_color: '#111416',
        theme_color: '#267b54',
        categories: ['games', 'entertainment'],
        icons: [
          { src: 'icons/pwa-icon-192.png', sizes: '192x192', type: 'image/png' },
          { src: 'icons/pwa-icon-512.png', sizes: '512x512', type: 'image/png' },
          { src: 'icons/pwa-icon-512.png', sizes: '512x512', type: 'image/png', purpose: 'maskable' },
        ],
      },
      workbox: {
        navigateFallbackDenylist: [/^\/api(?:\/|$)/, /^\/operation(?:\/|$)/],
        runtimeCaching: [{
          urlPattern: ({ request }) => request.destination === 'image',
          handler: 'CacheFirst',
          options: {
            cacheName: 'game-images',
            expiration: { maxEntries: 160, maxAgeSeconds: 60 * 60 * 24 * 30 },
            cacheableResponse: { statuses: [0, 200] },
          },
        }],
      },
    }),
  ],
  server: {
    proxy: {
      '/api': proxy,
      '/operation': proxy,
    },
  },
})
