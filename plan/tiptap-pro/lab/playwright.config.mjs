import { defineConfig } from '@playwright/test'

export default defineConfig({
  testDir: './tests',
  timeout: 40000,
  expect: { timeout: 12000 },
  fullyParallel: false,
  forbidOnly: Boolean(process.env.CI),
  retries: 0,
  reporter: [['list']],
  use: {
    baseURL: 'http://127.0.0.1:5193',
    headless: true,
    channel: process.env.PLAYWRIGHT_CHANNEL || 'chrome',
    screenshot: 'only-on-failure',
    trace: 'off',
  },
  webServer: {
    command: 'npm run dev',
    url: 'http://127.0.0.1:5193',
    reuseExistingServer: !process.env.CI,
    timeout: 30000,
  },
})
