const { defineConfig } = require('@playwright/test');

module.exports = defineConfig({
  testDir: 'tests/E2E',
  timeout: 60 * 1000,
  use: {
    baseURL: process.env.WPBRIDGE_E2E_BASE_URL || 'http://localhost:8888',
    headless: true,
    launchOptions: process.env.WPBRIDGE_E2E_CHROMIUM
      ? { executablePath: process.env.WPBRIDGE_E2E_CHROMIUM }
      : {},
  },
});
