import { defineConfig } from '@playwright/test'

const executablePath = process.env.BROWSER_BIN

export default defineConfig({
  testDir: './tests',
  timeout: 180_000,
  expect: { timeout: 20_000 },
  fullyParallel: false,
  workers: 1,
  retries: 0,
  reporter: [['list']],
  use: {
    headless: true,
    launchOptions: executablePath ? { executablePath } : {},
  },
})
