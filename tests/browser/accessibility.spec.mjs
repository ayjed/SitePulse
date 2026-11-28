import AxeBuilder from '@axe-core/playwright';
import { expect, login, test } from './fixture.mjs';

const tags = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'];

async function scan(page, label) {
    const result = await new AxeBuilder({ page }).withTags(tags).analyze();
    expect.soft(result.violations.map(({ id, nodes }) => ({ id, targets: nodes.map(({ target }) => target) })), label).toEqual([]);
}

async function reflows(page, label) {
    const fits = await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth);
    expect.soft(fits, label + ' document fits viewport').toBe(true);
}

async function fieldBoundary(control, surface) {
    const ratio = await control.evaluate((field, selector) => {
        const canvas = document.createElement('canvas');
        const context = canvas.getContext('2d', { willReadFrequently: true });
        const luminance = color => {
            context.fillStyle = color;
            context.fillRect(0, 0, 1, 1);
            const [red, green, blue] = [...context.getImageData(0, 0, 1, 1).data].slice(0, 3).map(value => {
                const channel = value / 255;
                return channel <= 0.04045 ? channel / 12.92 : ((channel + 0.055) / 1.055) ** 2.4;
            });
            return 0.2126 * red + 0.7152 * green + 0.0722 * blue;
        };
        const boundary = luminance(getComputedStyle(field).borderTopColor);
        const background = luminance(getComputedStyle(field.closest(selector)).backgroundColor);
        return (Math.max(boundary, background) + 0.05) / (Math.min(boundary, background) + 0.05);
    }, surface);
    expect.soft(ratio, 'Field boundary contrast against adjacent surface').toBeGreaterThanOrEqual(3);
}

for (const colorScheme of ['light', 'dark']) {
    for (const width of [1440, 768, 375, 320]) {
        test(colorScheme + ' theme at ' + width + ' CSS pixels', async ({ page, site }) => {
            await page.setViewportSize({ width, height: 900 });
            await page.emulateMedia({ colorScheme });
            await page.goto(site.url + '/login');
            await scan(page, 'Owner sign-in');
            await reflows(page, 'Owner sign-in');
            await fieldBoundary(page.getByRole('textbox', { name: /^Email address/ }), 'main');
            await login(page, site);
            await page.goto(site.url + '/dashboard');
            await expect(page.getByRole('link', { name: /^Payments/ })).toBeVisible();
            await scan(page, 'Dashboard and status badges');
            await reflows(page, 'Dashboard and status badges');

            await page.goto(site.url + '/monitors');
            await expect(page.getByRole('table', { name: /configured/i })).toBeVisible();
            await scan(page, 'Monitor management table');
            await reflows(page, 'Monitor management table');

            await page.goto(site.url + '/monitors/create');
            await scan(page, 'Create form');
            await reflows(page, 'Create form');
            await fieldBoundary(page.getByRole('textbox', { name: /^Monitor Name/ }), 'section');
            await page.goto(site.url + '/monitors/1/edit');
            await scan(page, 'Edit form');
            await reflows(page, 'Edit form');

            await page.goto(site.url + '/monitors/1');
            await page.locator('summary').filter({ hasText: /latency/i }).press('Enter');
            await page.locator('summary').filter({ hasText: 'Delete Monitor' }).press('Enter');
            await expect(page.getByRole('button', { name: 'Permanently delete monitor' })).toBeVisible();
            await expect(page.getByRole('table', { name: /latency/i })).toBeVisible();
            const incidents = page.getByRole('table', { name: 'Incident history' });
            await expect(incidents.getByRole('columnheader')).toHaveText(['Status', 'Cause', 'Started (UTC)', 'Resolved (UTC)', 'Duration']);
            await scan(page, 'Detail, expanded latency table and deletion confirmation');
            await reflows(page, 'Detail, expanded latency table and deletion confirmation');
            await fieldBoundary(page.getByRole('combobox', { name: /^Status/ }), 'section');
            if (process.env.SITEPULSE_BROWSER_SCREENSHOTS === '1' && !process.env.CI) {
                await page.screenshot({ path: 'storage/app/private/browser-evidence/detail-' + colorScheme + '-' + width + '.png' });
            }
        });
    }
}

test('keyboard reaches the skip target and server validation is associated with its fields', async ({ page, site }) => {
    await page.goto(site.url + '/login');
    await page.keyboard.press('Tab');
    await expect(page.getByRole('link', { name: /skip to/i })).toBeFocused();
    await page.keyboard.press('Enter');
    await expect(page.getByRole('heading', { level: 1 })).toBeFocused();
    await login(page, site);
    await page.goto(site.url + '/monitors/create');
    await page.getByLabel('Monitor Name').fill('Browser validation fixture');
    const url = page.getByLabel('Target URL');
    await url.fill('ftp://example.test/');
    await page.getByRole('button', { name: 'Save Monitor' }).press('Enter');
    await expect(page.getByRole('alert')).toBeFocused();
    await expect(url).toHaveAttribute('aria-invalid', 'true');
    await expect(url).toHaveAccessibleDescription(/http/i);
    await scan(page, 'Validation errors');
});

test('keyboard tab order reaches detail actions and the first table can scroll locally', async ({ page, site }) => {
    await page.setViewportSize({ width: 320, height: 900 });
    await login(page, site);
    await page.goto(site.url + '/monitors/1');
    const controls = [
        page.getByRole('link', { name: /skip to/i }),
        page.getByRole('navigation', { name: 'Primary' }).getByRole('link', { name: 'SitePulse' }),
        page.getByRole('navigation', { name: 'Primary' }).getByRole('link', { name: 'Monitors' }),
        page.getByRole('button', { name: 'Sign Out' }),
        page.getByRole('link', { name: 'All monitors' }),
        page.getByRole('link', { name: 'Edit', exact: true }),
        page.getByRole('button', { name: 'Pause Monitor' }),
        page.locator('summary').filter({ hasText: 'Delete Monitor' }),
        page.getByRole('button', { name: 'Refresh history' }),
        page.locator('summary').filter({ hasText: /latency/i }),
        page.getByRole('region', { name: 'Scrollable check results' }),
    ];
    for (const control of controls) {
        await page.keyboard.press('Tab');
        await expect(control).toBeFocused();
        await expect(control).toHaveCSS('outline-style', 'solid');
    }
    const checkRegion = controls.at(-1);
    await page.keyboard.press('ArrowRight');
    await expect.poll(() => checkRegion.evaluate(region => region.scrollLeft)).toBeGreaterThan(0);
    await page.keyboard.press('Shift+Tab');
    await expect(controls.at(-2)).toBeFocused();
    await page.keyboard.press('Tab');
    await page.keyboard.press('Tab');
    await page.keyboard.press('Tab');
    await page.keyboard.press('Tab');
    await expect(page.getByRole('combobox', { name: /^Status/ })).toBeFocused();
    await page.keyboard.press('Tab');
    const incidentRegion = page.getByRole('region', { name: 'Scrollable incident history' });
    await expect(incidentRegion).toBeFocused();
    await page.keyboard.press('ArrowRight');
    await expect.poll(() => incidentRegion.evaluate(region => region.scrollLeft)).toBeGreaterThan(0);
});

test('detail remains readable with 200% root text sizing at 320 CSS pixels', async ({ page, site }) => {
    await page.setViewportSize({ width: 320, height: 900 });
    await login(page, site);
    await page.goto(site.url + '/monitors/1');
    await page.addStyleTag({ content: 'html { font-size: 200% }' });
    await expect(page.locator('html')).toHaveCSS('font-size', '32px');
    await page.locator('summary').filter({ hasText: /latency/i }).press('Enter');
    await reflows(page, 'Enlarged detail');
    const clipped = await page.getByRole('table').evaluateAll(tables => tables.flatMap(table =>
        [...table.querySelectorAll('th, td')].filter(cell => cell.scrollWidth > cell.clientWidth).map(cell => cell.tagName)));
    expect(clipped).toEqual([]);
});
