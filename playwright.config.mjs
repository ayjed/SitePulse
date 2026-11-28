import { defineConfig, devices } from '@playwright/test';

export default defineConfig({
    testDir: 'tests/browser',
    workers: 1,
    timeout: 90000,
    forbidOnly: Boolean(process.env.CI),
    outputDir: 'storage/app/private/browser-results',
    reporter: [['list'], ['./tests/browser/report.mjs']],
    use: { ...devices['Desktop Chrome'], trace: 'off', screenshot: 'off', video: 'off' },
});
