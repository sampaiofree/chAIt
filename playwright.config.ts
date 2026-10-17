import { defineConfig } from '@playwright/test';

const port = Number(process.env.CHAIT_BROWSER_PORT || 8895);

export default defineConfig({
    testDir: './tests/Browser',
    testMatch: '**/*.spec.ts',
    fullyParallel: false,
    workers: 1,
    globalTeardown: './tests/Browser/teardown.mjs',
    timeout: 30000,
    use: {
        browserName:
            process.env.CHAIT_BROWSER_ENGINE === 'webkit'
                ? 'webkit'
                : 'chromium',
        baseURL: `http://127.0.0.1:${port}`,
        viewport: { width: 1440, height: 900 },
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
    },
    webServer: {
        command: 'node tests/Browser/start-server.mjs',
        url: `http://127.0.0.1:${port}/login`,
        reuseExistingServer: false,
        timeout: 60000,
    },
});
