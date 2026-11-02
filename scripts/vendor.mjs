// Copies browser builds of Alpine and Chart.js into public/js so the app serves them itself (no CDN, no runtime Node).
import { copyFileSync, mkdirSync } from 'node:fs'
mkdirSync('public/js', { recursive: true })
copyFileSync('node_modules/alpinejs/dist/cdn.min.js', 'public/js/alpine.min.js')
copyFileSync('node_modules/chart.js/dist/chart.umd.js', 'public/js/chart.umd.js')
