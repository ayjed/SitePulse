import { test as base, expect } from '@playwright/test';
import { execFileSync, spawn } from 'node:child_process';
import { randomBytes } from 'node:crypto';
import { mkdir, mkdtemp, rm, stat, writeFile } from 'node:fs/promises';
import { createServer } from 'node:net';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { once } from 'node:events';

const phpOptions = ['-d', 'disable_functions=curl_exec,curl_multi_exec,fsockopen,pfsockopen,stream_socket_client,dns_get_record,gethostbyname,gethostbynamel'];

async function loopbackPort() {
    const socket = createServer();
    socket.listen(0, '127.0.0.1');
    await once(socket, 'listening');
    const { port } = socket.address();
    await new Promise(resolve => socket.close(resolve));
    return port;
}

export async function startSite(empty) {
    await stat('public/build/manifest.json');
    const viteHot = await stat('public/hot').catch(error => {
        if (error.code !== 'ENOENT') throw error;
    });
    if (viteHot) {
        throw new Error('Stop the Vite dev server before running browser tests against the production build.');
    }
    const directory = await mkdtemp(join(tmpdir(), 'sitepulse-browser-'));
    const password = randomBytes(24).toString('hex');
    const database = join(directory, 'browser.sqlite');
    const port = await loopbackPort();
    const url = 'http://127.0.0.1:' + port;
    const env = {
        ...Object.fromEntries(Object.entries(process.env).filter(([key]) => /^(path|systemroot|windir|temp|tmp|tmpdir|ld_library_path)$/i.test(key))),
        SITEPULSE_BROWSER_DIRECTORY: directory,
        BROWSER_PASSWORD: password,
        APP_NAME: 'SitePulse',
        APP_ENV: 'testing',
        APP_DEBUG: 'false',
        APP_KEY: 'base64:' + randomBytes(32).toString('base64'),
        APP_URL: url,
        APP_CONFIG_CACHE: join(directory, 'config.php'),
        APP_ROUTES_CACHE: join(directory, 'routes.php'),
        APP_EVENTS_CACHE: join(directory, 'events.php'),
        DB_CONNECTION: 'sqlite',
        DB_DATABASE: database,
        SESSION_DRIVER: 'file',
        CACHE_STORE: 'array',
        QUEUE_CONNECTION: 'sync',
        MAIL_MAILER: 'array',
        BCRYPT_ROUNDS: '4',
    };
    const invoke = (...args) => execFileSync('php', [...phpOptions, 'tests/browser/site.php', ...args], { env, encoding: 'utf8' });
    let server;
    try {
        for (const path of ['framework/cache/data', 'framework/sessions', 'framework/views', 'logs']) {
            await mkdir(join(directory, 'storage', path), { recursive: true });
        }
        await writeFile(database, '', { mode: 0o600 });
        await writeFile(join(directory, 'browser-fixture'), '', { mode: 0o600 });
        invoke('setup', empty ? 'empty' : 'populated');
        server = spawn('php', [...phpOptions, '-S', '127.0.0.1:' + port, '-t', 'public', 'tests/browser/site.php'], { env, stdio: 'ignore' });
        await expect.poll(async () => {
            try {
                return (await fetch(url + '/login', { signal: AbortSignal.timeout(3500) })).status;
            } catch {
                return 0;
            }
        }, { timeout: 25000 }).toBe(200);
        return {
            url,
            password,
            state: () => JSON.parse(invoke('state')),
            resolve: () => invoke('resolve'),
            stop: async () => {
                if (server.exitCode === null && server.signalCode === null) {
                    const closed = once(server, 'exit');
                    server.kill();
                    await closed;
                }
                await rm(directory, { recursive: true, force: true });
            },
        };
    } catch (error) {
        server?.kill();
        await rm(directory, { recursive: true, force: true });
        throw error;
    }
}

export const test = base.extend({
    emptySite: [false, { option: true }],
    site: async ({ emptySite }, use) => {
        const site = await startSite(emptySite);
        try {
            await use(site);
        } finally {
            await site.stop();
        }
    },
    page: async ({ page, site }, use) => {
        await page.context().route('**/*', route => new URL(route.request().url()).origin === site.url ? route.continue() : route.abort());
        await use(page);
    },
});

export async function login(page, site, email = 'owner@example.test') {
    await page.goto(site.url + '/login');
    await page.locator('#email').fill(email);
    await page.locator('#password').fill(site.password);
    await page.getByRole('button', { name: 'Sign In', exact: true }).click();
}

export { expect };
