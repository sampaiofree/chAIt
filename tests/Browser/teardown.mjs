import { spawnSync } from 'node:child_process';
import { existsSync, readFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import process from 'node:process';

// Playwright can kill the server process group before its PHP cleanup child exits.
// Run the same guarded cleanup from the test controller before stopping the server.
export default function teardown() {
    const port = Number(process.env.CHAIT_BROWSER_PORT || 8895);
    const metadata = join(tmpdir(), `chait-browser-schema-${port}.json`);

    if (!existsSync(metadata)) {
        return;
    }

    const { schema } = JSON.parse(readFileSync(metadata, 'utf8'));

    if (!/^chait_browser_[a-f0-9]{32}$/.test(schema)) {
        throw new Error('Invalid browser schema metadata.');
    }

    process.loadEnvFile('.env.testing');

    const result = spawnSync('php', ['tests/Browser/database.php', 'drop'], {
        env: {
            ...process.env,
            APP_ENV: 'testing',
            DB_CONNECTION: 'pgsql',
            DB_DATABASE: 'chait_test',
            DB_SCHEMA: schema,
            DB_URL: '',
        },
        stdio: 'inherit',
    });

    if (result.status !== 0) {
        throw new Error('Browser schema cleanup failed.');
    }

    rmSync(metadata);
}
