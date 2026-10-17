import { spawn, spawnSync } from 'node:child_process';
import { randomUUID } from 'node:crypto';
import { mkdtempSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, resolve } from 'node:path';
import process from 'node:process';

const port = Number(process.env.CHAIT_BROWSER_PORT || 8895);
process.loadEnvFile('.env.testing');
const directory = mkdtempSync(join(tmpdir(), 'chait-browser-'));
const schema = `chait_browser_${randomUUID().replaceAll('-', '')}`;
const metadata = join(tmpdir(), `chait-browser-schema-${port}.json`);
const env = {
    ...process.env,
    APP_ENV: 'testing',
    CHAIT_REALTIME_ENABLED: process.env.CHAIT_BROWSER_REALTIME || 'false',
    BROADCAST_CONNECTION: 'null',
    REVERB_APP_ID: 'browser-test',
    REVERB_APP_KEY: 'browser-test-key',
    REVERB_APP_SECRET: 'browser-test-secret',
    REVERB_HOST: '127.0.0.1',
    REVERB_PORT: '9908',
    REVERB_SCHEME: 'http',
    APP_DEBUG: 'true',
    APP_URL: `http://127.0.0.1:${port}`,
    DB_CONNECTION: 'pgsql',
    DB_DATABASE: 'chait_test',
    DB_SCHEMA: schema,
    DB_URL: '',
    SESSION_DRIVER: 'database',
    SESSION_FILES: join(directory, 'sessions'),
    SESSION_COOKIE: 'chait_browser_test',
    SESSION_SECURE_COOKIE: 'false',
    CACHE_STORE: 'array',
    QUEUE_CONNECTION: 'sync',
    MAIL_MAILER: 'array',
    BCRYPT_ROUNDS: '4',
    UAZAPI_BASE_URL: '',
    UAZAPI_ADMIN_TOKEN: '',
};

let schemaCreated = false;
function cleanup() {
    if (schemaCreated) {
        schemaCreated = false;
        const result = spawnSync(
            'php',
            ['tests/Browser/database.php', 'drop'],
            {
                env,
                stdio: 'inherit',
            },
        );

        if (result.status !== 0) {
            process.exitCode = 1;
        }
    }

    rmSync(directory, { recursive: true, force: true });
}

for (const args of [
    ['tests/Browser/database.php', 'create'],
    ['artisan', 'migrate', '--force', '--no-interaction'],
    ['tests/Browser/seed.php'],
]) {
    const result = spawnSync('php', args, { env, stdio: 'inherit' });

    if (result.status !== 0) {
        cleanup();
        process.exit(result.status ?? 1);
    }

    if (args[0] === 'tests/Browser/database.php') {
        schemaCreated = true;
        writeFileSync(metadata, JSON.stringify({ schema }));
    }
}

const child = spawn(
    'php',
    [
        '-S',
        `127.0.0.1:${port}`,
        '-t',
        'public',
        resolve('tests/Browser/server.php'),
    ],
    { env, stdio: 'inherit' },
);
function stop() {
    child.kill('SIGTERM');
    cleanup();
}
process.on('SIGTERM', () => {
    stop();
    process.exit(0);
});
process.on('SIGINT', () => {
    stop();
    process.exit(0);
});
child.on('exit', (code) => {
    stop();
    process.exit(code ?? 0);
});
