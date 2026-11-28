import { expect, login, test } from './fixture.mjs';

const update = /\/livewire-[^/]+\/update$/;

function heldRequest(page, url, matches = () => true) {
    let release;
    let arrived;
    let count = 0;
    const pending = new Promise(resolve => { release = resolve; });
    const started = new Promise(resolve => { arrived = resolve; });
    const install = page.route(url, async route => {
        if (matches(route.request())) {
            count++;
            arrived();
            await pending;
        }
        await route.continue();
    });
    return { install, started, release, count: () => count };
}

test('guests and non-owner accounts cannot use the owner UI', async ({ page, site }) => {
    await page.goto(site.url + '/monitors/1');
    await expect(page).toHaveURL(/\/login$/);
    await login(page, site, 'outsider@example.test');
    await expect(page.getByRole('alert')).toBeVisible();
    await expect(page).toHaveURL(/\/login$/);
    await login(page, site);
    await page.goto(site.url + '/dashboard');
    await expect(page.getByRole('link', { name: 'Foreign monitor' })).toHaveCount(0);
    expect((await page.goto(site.url + '/monitors/7')).status()).toBe(403);
    expect((await page.request.delete(site.url + '/monitors/1')).status()).toBe(419);
    expect(site.state()).toHaveLength(19);
});

test('owner creates, edits, pauses, resumes and explicitly deletes a monitor', async ({ page, site }) => {
    await login(page, site);
    await page.goto(site.url + '/monitors/create');
    await page.getByLabel('Monitor Name').fill('Browser-created monitor');
    await page.getByLabel('Target URL').fill('https://new.example.test/health');
    await page.getByRole('button', { name: 'Save Monitor' }).click();
    const monitor = site.state().find(item => item.name === 'Browser-created monitor');
    expect(monitor.status).toBe('unknown');
    await page.goto(site.url + '/monitors/' + monitor.id);
    await page.getByRole('link', { name: 'Edit', exact: true }).click();
    await page.getByLabel('Monitor Name').fill('Browser-edited monitor');
    await page.getByRole('button', { name: /save|update/i }).click();
    await expect(page.getByText('Browser-edited monitor', { exact: true }).first()).toBeVisible();
    await page.goto(site.url + '/monitors/' + monitor.id);
    await page.getByRole('button', { name: 'Pause Monitor' }).click();
    await expect(page.getByRole('button', { name: 'Resume Monitor' })).toBeVisible();
    expect(site.state().at(-1).status).toBe('paused');
    await page.getByRole('button', { name: 'Resume Monitor' }).click();
    await expect(page.getByRole('button', { name: 'Pause Monitor' })).toBeVisible();
    expect(site.state().at(-1).status).toBe('unknown');

    const disclosure = page.locator('summary').filter({ hasText: 'Delete Monitor' });
    const confirmation = page.getByRole('button', { name: 'Permanently delete monitor' });
    await disclosure.press('Enter');
    await expect(confirmation).toBeVisible();
    expect(site.state().at(-1).id).toBe(monitor.id);
    await page.locator('summary').filter({ hasText: 'Cancel deletion' }).press('Space');
    await expect(confirmation).toBeHidden();
    await expect(disclosure).toBeFocused();
    expect(site.state().at(-1).id).toBe(monitor.id);

    await disclosure.press('Enter');
    const held = heldRequest(page, '**/monitors/' + monitor.id, request => request.method() === 'POST');
    await held.install;
    const { x, y, width, height } = await confirmation.boundingBox();
    try {
        await confirmation.press('Enter', { noWaitAfter: true });
        await held.started;

        await page.mouse.click(x + width / 2, y + height / 2, { clickCount: 2 });
        await page.keyboard.press('Enter');
        expect(site.state().at(-1).id).toBe(monitor.id);
        expect(held.count()).toBe(1);
    } finally {
        held.release();
    }
    await expect(page).toHaveURL(/\/monitors$/);
    expect(site.state().some(item => item.id === monitor.id)).toBe(false);
    expect(held.count()).toBe(1);
});

test('dashboard refresh and history interactions stay synchronized under overlap', async ({ page, site }) => {
    await login(page, site);
    await page.goto(site.url + '/dashboard');
    const status = page.getByRole('region', { name: 'Monitor status' });
    for (const label of ['Operational', 'Not checked', 'Degraded', 'Down', 'Down · recovering', 'Paused']) {
        expect(await status.ariaSnapshot()).toContain('Status: ' + label + ' Last check');
    }
    const overviewRefresh = status.getByRole('button', { name: 'Refresh' });
    await expect(overviewRefresh).toHaveAttribute('aria-disabled', 'false');
    const refreshed = page.waitForResponse(update);
    await overviewRefresh.press('Enter');
    const response = await refreshed;
    expect(response.ok()).toBe(true);
    expect(response.headers()['cache-control']).toMatch(/no-store/);
    await expect(status.getByRole('button', { name: 'Refresh' })).toBeFocused();
    await status.getByRole('link', { name: /^Payments/ }).press('Enter');
    await expect(page.getByText('80%', { exact: true })).toHaveCount(3);
    await expect(page.getByText('65 completed checks', { exact: true })).toHaveCount(3);
    const chart = page.locator('summary').filter({ hasText: /latency/i });
    await chart.press('Enter');
    await expect(page.getByRole('table', { name: /latency/i })).toBeVisible();
    await expect(chart).toBeFocused();

    const cutoff = page.locator('#history-reference');
    const originalCutoff = await cutoff.getAttribute('datetime');
    const refresh = page.getByRole('button', { name: 'Refresh history' });
    const filter = page.getByRole('combobox', { name: /^Status/ });
    const next = page.getByRole('button', { name: 'Next check page' });
    await expect(refresh).toHaveAttribute('aria-disabled', 'false');
    const held = heldRequest(page, update);
    await held.install;
    await next.scrollIntoViewIfNeeded();
    const { x, y, width, height } = await next.boundingBox();
    try {
        await expect(next).toHaveAttribute('data-cursor', /.+/);
        await next.press('Enter');
        await expect.poll(held.count).toBe(1);
        await expect(next).toHaveAttribute('aria-disabled', 'true');
        await expect(page.getByRole('status').filter({ hasText: 'Loading history' })).toBeVisible();
        await next.press('Space');
        await page.mouse.click(x + width / 2, y + height / 2, { clickCount: 2 });
        await refresh.press('Enter');
        await filter.evaluate(select => {
            select.value = 'resolved';
            select.dispatchEvent(new Event('change', { bubbles: true }));
        });
        await expect(filter).toHaveValue('all');
        expect(held.count()).toBe(1);
    } finally {
        held.release();
    }
    await expect(next).toHaveAttribute('aria-disabled', 'false');
    await expect(cutoff).toHaveAttribute('datetime', originalCutoff);
    const table = page.getByRole('region', { name: 'Check history' }).getByRole('table');
    await expect(table.getByText('0 ms', { exact: true })).toHaveCount(0);
    await next.press('Enter');
    await expect(next).toHaveAttribute('data-cursor', '');
    await expect(next).toHaveAttribute('aria-disabled', 'true');
    await expect(next).toBeFocused();
    await expect(next).toHaveCSS('outline-style', 'solid');
    const previous = page.getByRole('button', { name: 'Previous check page' });
    await previous.press('Enter');
    await expect(next).toHaveAttribute('aria-disabled', 'false');

    const nextIncident = page.getByRole('button', { name: 'Next incident page' });
    await nextIncident.press('Enter');
    await expect(nextIncident).toHaveAttribute('data-cursor', '');
    await expect(nextIncident).toHaveAttribute('aria-disabled', 'true');
    await page.getByRole('button', { name: 'Previous incident page' }).press('Enter');
    await expect(nextIncident).toHaveAttribute('aria-disabled', 'false');
    await filter.selectOption('open');
    await expect(page.getByText('Still open', { exact: true })).toBeVisible();
    await expect(filter).toHaveValue('open');
    site.resolve();
    await filter.selectOption('resolved');
    await expect(page.getByText('Resolved after the cutoff', { exact: true })).toBeVisible();
    await expect(cutoff).toHaveAttribute('datetime', originalCutoff);
    await expect(page.getByText(/cutoff applies to incident start times/)).toBeVisible();
    await refresh.press('Enter');
    await expect(refresh).toHaveAttribute('aria-disabled', 'false');
    await expect(refresh).toBeFocused();
    await expect(refresh).toHaveCSS('outline-style', 'solid');
    await expect(cutoff).not.toHaveAttribute('datetime', originalCutoff);
});

test('empty history and synthetic query failure have understandable recovery states', async ({ page, site }) => {
    await login(page, site);
    await page.goto(site.url + '/monitors/2');
    await expect(page.getByText('No data', { exact: true })).toHaveCount(3);
    await expect(page.getByText('No recorded checks yet.')).toBeVisible();
    await expect(page.getByText('No incidents match this filter.')).toBeVisible();
    await page.goto(site.url + '/monitors/1?fixture-error=1');
    await expect(page.getByRole('alert')).toContainText('temporarily unavailable');
    await expect(page.getByText('Synthetic browser fixture query failure.')).toHaveCount(0);
    await page.getByRole('button', { name: 'Refresh history' }).press('Enter');
    await expect(page.getByRole('heading', { name: 'Observed-check uptime' })).toBeVisible();
    await expect(page.getByRole('alert')).toHaveCount(0);
});

test.describe('empty dashboard', () => {
    test.use({ emptySite: true });
    test('guides an owner to add the first monitor', async ({ page, site }) => {
        await login(page, site);
        await page.goto(site.url + '/dashboard');
        await expect(page.getByText('No monitors configured yet')).toBeVisible();
        await expect(page.getByRole('link', { name: /add monitor|create monitor/i }).first()).toBeVisible();
    });
});



test('monitor navigation is bounded and a stale signed-out tab cannot refresh', async ({ page, site }) => {
    await login(page, site);
    await page.goto(site.url + '/dashboard');
    const monitors = page.getByRole('list', { name: 'Monitors', exact: true });
    const refresh = page.getByRole('button', { name: 'Refresh', exact: true });
    await expect(refresh).toHaveAttribute('aria-disabled', 'false');
    await expect(monitors.getByRole('listitem')).toHaveCount(15);
    await page.getByRole('button', { name: 'Next page' }).press('Enter');
    await expect(monitors.getByRole('listitem')).toHaveCount(3);
    await expect(monitors.getByRole('link').last()).toHaveText('Additional monitor 12');
    await page.getByRole('button', { name: 'Previous page' }).press('Enter');
    await expect(monitors.getByRole('link').first()).toHaveAccessibleName(/^Payments/);
    const other = await page.context().newPage();
    await other.goto(site.url + '/dashboard');
    await other.getByRole('button', { name: /sign out/i }).click();
    await expect(other).toHaveURL(/\/login$/);
    const response = page.waitForResponse(update);
    await refresh.press('Enter');
    const rejected = await response;
    expect(rejected.status()).toBe(302);
    expect(rejected.headers().location).toBe(site.url + '/login');
});
