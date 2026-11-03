/** Run: node tests/settings-browser-test.cjs.
 * External Playwright only (no jQuery): DIALOGA_BROWSER_TOOLING=/path/to/tooling.
 * DIALOGA_BROWSER_EXECUTABLE and PHP_BINARY override Chrome/PHP; DIALOGA_BROWSER_REQUIRE=1 makes missing tooling fail.
 */
'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { createRequire } = require('node:module');
const { spawnSync } = require('node:child_process');
const root = path.resolve(__dirname, '..');
const temporaryTools = '/var/folders/2w/_41g_m7s1b3ds8bs1w06m15r0000gp/T/opencode/flow-transfer-browser-tooling';
const slugs = ['general', 'appearance', 'messages', 'business-hours', 'notifications', 'engagement', 'data-privacy', 'danger-zone'];
const fragment = slug => '#dialoga-settings-' + slug;
const tab = (page, slug) => page.locator('#dialoga-settings-tab-' + slug);
function dependency(name) {
    for (const base of [process.env.DIALOGA_BROWSER_TOOLING, root, temporaryTools].filter(Boolean)) {
        try { return createRequire(path.join(base, 'package.json')).resolve(name); } catch {}
    }
    return null;
}
function bridge(input) {
    const result = spawnSync(process.env.PHP_BINARY || 'php', [path.join(__dirname, 'settings-test.php'), '--bridge'], {
        input: JSON.stringify(input), encoding: 'utf8', maxBuffer: 16 * 1024 * 1024
    });
    if (result.error || result.status !== 0) throw new Error(result.error?.message || result.stderr || 'Settings PHP bridge failed');
    return JSON.parse(result.stdout);
}
async function selected(page, slug, focused = false) {
    await page.waitForFunction(() => document.querySelector('.dialoga-settings-tabs')?.getAttribute('role') === 'tablist');
    const state = await page.evaluate(() => [...document.querySelectorAll('.dialoga-settings-tab')].map(element => ({
        slug: element.id.replace('dialoga-settings-tab-', ''), selected: element.getAttribute('aria-selected'),
        tabIndex: element.tabIndex, hidden: document.getElementById(element.hash.slice(1)).hidden,
        display: getComputedStyle(document.getElementById(element.hash.slice(1))).display,
        focused: document.activeElement === element, role: element.getAttribute('role'),
        controls: element.getAttribute('aria-controls'), labelled: document.getElementById(element.hash.slice(1)).getAttribute('aria-labelledby')
    })));
    assert.equal(state.length, 8);
    for (const item of state) {
        assert.equal(item.selected, String(item.slug === slug));
        assert.equal(item.tabIndex, item.slug === slug ? 0 : -1);
        assert.equal(item.hidden, item.slug !== slug);
        assert.equal(item.display === 'none', item.slug !== slug, 'actual CSS honors hidden');
        assert.equal(item.role, 'tab');
        assert.equal(item.controls, 'dialoga-settings-' + item.slug);
        assert.equal(item.labelled, 'dialoga-settings-tab-' + item.slug);
        if (focused && item.slug === slug) assert.equal(item.focused, true, 'keyboard focus follows selected tab');
    }
    assert.ok((await page.locator('form').getAttribute('action')).endsWith(fragment(slug)));
}
async function main() {
    const playwright = dependency('playwright') || dependency('playwright-core');
    if (!playwright) {
        console.log('SKIP: external Playwright required; set DIALOGA_BROWSER_TOOLING. No jQuery required.');
        if (process.env.DIALOGA_BROWSER_REQUIRE === '1') process.exitCode = 1;
        return;
    }
    const { chromium } = require(playwright);
    let browser;
    try {
        browser = await chromium.launch(process.env.DIALOGA_BROWSER_EXECUTABLE ? { executablePath: process.env.DIALOGA_BROWSER_EXECUTABLE } :
            fs.existsSync('/Applications/Google Chrome.app') ? { channel: 'chrome' } : {});
    } catch (error) {
        console.log('SKIP: Chrome unavailable: ' + error.message);
        if (process.env.DIALOGA_BROWSER_REQUIRE === '1') process.exitCode = 1;
        return;
    }
    let passed = 0, failed = 0;
    async function test(name, run, options = {}) {
        const context = await browser.newContext({ viewport: options.viewport || { width: 1440, height: 900 }, javaScriptEnabled: options.js !== false });
        const page = await context.newPage();
        page.setDefaultTimeout(5000);
        const errors = [], state = { posts: [], rendered: null, settings: options.settings || {}, dialogs: [], ajax: [] };
        const fixture = { site_locale: options.site || 'en_US', user_locale: options.user || null };
        page.on('pageerror', error => errors.push(error.message));
        page.on('dialog', async dialog => {
            state.dialogs.push({ type: dialog.type(), message: dialog.message() });
            if (dialog.type() === 'prompt') await dialog.accept('DELETE'); else await dialog.accept();
        });
        await page.route('http://dialoga.test/**', async route => {
            const request = route.request();
            const url = new URL(request.url());
            if (url.pathname === '/assets/settings.js') return route.fulfill({ contentType: 'text/javascript', body: fs.readFileSync(path.join(root, 'assets/js/settings.js'), 'utf8') });
            if (['/assets/admin.css', '/assets/screen-settings.css'].includes(url.pathname)) return route.fulfill({ contentType: 'text/css', body: fs.readFileSync(path.join(root, 'assets/css', path.basename(url.pathname)), 'utf8') });
            if (url.pathname === '/wp-admin/admin-ajax.php') {
                const raw = request.postData() || '';
                const fields = Object.fromEntries([...raw.matchAll(/name="([^"]+)"\r\n\r\n([\s\S]*?)(?=\r\n--)/g)].map(match => [match[1], match[2]]));
                state.ajax.push(fields);
                return route.fulfill({ json: { success: true, data: { deleted_count: 3 } } });
            }
            if (url.pathname !== '/wp-admin/admin.php') return route.fulfill({ status: 404, body: '' });
            try {
                const input = { ...fixture, settings: state.settings, get: Object.fromEntries(url.searchParams) };
                if (request.method() === 'POST') {
                    input.post = {};
                    for (const [key, value] of new URLSearchParams(request.postData())) {
                        if (key.endsWith('[]')) (input.post[key.slice(0, -2)] ||= []).push(value);
                        else input.post[key] = value;
                    }
                    state.posts.push(input.post);
                }
                const rendered = bridge(input);
                state.rendered = rendered;
                if (rendered.writes) state.settings = rendered.settings;
                const config = JSON.stringify({ i18n: rendered.dictionary, ajaxUrl: '/wp-admin/admin-ajax.php', nonce: 'browser-settings-nonce' }).replace(/</g, '\\u003c');
                await route.fulfill({ contentType: 'text/html', body: '<!doctype html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">' +
                    '<link rel="stylesheet" href="/assets/admin.css"><link rel="stylesheet" href="/assets/screen-settings.css">' +
                    '<style>body{margin:0;background:#f0f0f1;font:14px Arial}#wpcontent{margin-left:160px;padding:20px}.wrap{margin:10px 20px 0 2px}input,textarea,select{box-sizing:border-box}button{min-height:30px}@media(max-width:782px){#wpcontent{margin-left:0;padding:10px}.wrap{margin:0}}</style>' +
                    '</head><body><main id="wpcontent">' + rendered.html + '</main>' + (options.js === false ? '' : '<script>window.dialogaAdmin=' + config + '</script><script src="/assets/settings.js"></script>') + '</body></html>' });
            } catch (error) {
                errors.push('PHP fixture: ' + error.message);
                await route.fulfill({ status: 500, body: error.message });
            }
        });
        try {
            await page.goto('http://dialoga.test/wp-admin/admin.php?page=dialoga-settings' + (options.hash || ''));
            await run({ page, state });
            assert.deepEqual(errors, [], 'no uncaught browser/fixture errors');
            console.log('PASS: ' + name); passed++;
        } catch (error) { console.error('FAIL: ' + name + '\n  ' + error.message + (errors.length ? '\n  ' + errors.join('; ') : '')); failed++; }
        finally { await context.close(); }
    }
    try {
        await test('real script keyboard tabs, focus, wraparound, Home/End, Tab to panel, color and role controls', async ({ page }) => {
            assert.equal(await page.evaluate(() => typeof window.jQuery), 'undefined', 'native DOM without jQuery');
            assert.equal(await page.locator('.dialoga-settings-tabs').getAttribute('role'), 'tablist');
            await selected(page, 'general'); await tab(page, 'general').focus();
            for (const [key, slug] of [['End', 'danger-zone'], ['ArrowRight', 'general'], ['ArrowLeft', 'danger-zone'],
                ['Home', 'general'], ['ArrowDown', 'appearance'], ['ArrowUp', 'general']]) {
                await page.keyboard.press(key); await selected(page, slug, true);
                assert.equal(new URL(page.url()).hash, fragment(slug));
            }
            await page.keyboard.press('Tab');
            assert.equal(await page.evaluate(() => document.activeElement.id), 'dialoga-settings-general', 'Tab skips inactive tabs/hidden panels');
            await page.locator('[name="visibility_mode"]').selectOption('specific_roles');
            assert.equal(await page.locator('#dialoga-roles-selector').isVisible(), true);
            await page.locator('[name="visibility_mode"]').selectOption('everyone');
            assert.equal(await page.locator('#dialoga-roles-selector').isVisible(), false);
            await tab(page, 'appearance').click();
            await page.locator('[name="primary_color"]').evaluate(el => { el.value = '#abcdef'; el.dispatchEvent(new Event('input', { bubbles: true })); });
            assert.equal(await page.locator('[name="primary_color_text"]').inputValue(), '#abcdef');
        });
        await test('fragment restores on initial load, reload, native POST, hashchange and browser back', async ({ page, state }) => {
            await selected(page, 'engagement'); await page.reload(); await selected(page, 'engagement');
            await Promise.all([page.waitForNavigation(), page.locator('[name="dialoga_save_settings"]').first().click()]);
            assert.equal(state.posts.length, 1); await selected(page, 'engagement');
            assert.equal(new URL(page.url()).hash, fragment('engagement'));
            await page.evaluate(() => { location.hash = '#dialoga-settings-messages'; });
            await page.waitForFunction(() => document.querySelector('#dialoga-settings-tab-messages').getAttribute('aria-selected') === 'true');
            await selected(page, 'messages'); await page.goBack(); await selected(page, 'engagement');
        }, { hash: fragment('engagement') });
        await test('native hidden-panel inputs submit with visible fields and persist cross-tab edits in real PHP', async ({ page, state }) => {
            await page.locator('[name="enabled"]').check();
            await tab(page, 'messages').click(); await page.locator('[name="chat_header_title"]').fill('Talk to our team');
            await page.locator('[name="welcome_message"]').fill('Visitor\'s "welcome"\nSecond line \\ path');
            await page.locator('[name="offline_message"]').fill('Offline\'s "message"\nOther line');
            await tab(page, 'notifications').click(); await page.locator('[name="email_notifications_subject"]').fill('Custom lead subject');
            await tab(page, 'data-privacy').click(); await page.locator('[name="lead_retention_enabled"]').check(); await page.locator('[name="lead_retention_days"]').fill('45');
            await tab(page, 'appearance').click(); await page.locator('[name="chat_position"]').selectOption('bottom-left');
            const successful = await page.evaluate(() => Object.fromEntries(new FormData(document.querySelector('form'))));
            assert.equal(successful.chat_header_title, 'Talk to our team');
            assert.equal(successful.welcome_message, 'Visitor\'s "welcome"\nSecond line \\ path');
            assert.equal(successful.email_notifications_subject, 'Custom lead subject'); assert.equal(successful.lead_retention_days, '45');
            await Promise.all([page.waitForNavigation(), page.locator('[name="dialoga_save_settings"]').first().click()]);
            await selected(page, 'appearance');
            // Native HTML form encoding canonicalizes textarea line breaks to CRLF.
            assert.equal(state.settings.welcome_message.replace(/\r\n/g, '\n'), successful.welcome_message);
            assert.equal(state.settings.offline_message.replace(/\r\n/g, '\n'), successful.offline_message);
            assert.equal(state.settings.enabled, 1); assert.equal(state.settings.chat_position, 'bottom-left');
            assert.equal(state.settings.email_notifications.subject, 'Custom lead subject'); assert.equal(state.settings.lead_retention.days, 45);
            assert.equal(state.settings.extension_key, 'keep'); assert.equal(state.settings.multi_flow_strategy, 'ab');
            assert.equal(state.rendered.cron.length, 1);
        }, { settings: { extension_key: 'keep', multi_flow_strategy: 'ab' } });
        await test('native validation reveals first invalid hidden tab and focuses its field without submitting', async ({ page, state }) => {
            await page.locator('[name="session_duration"]').fill('0');
            await tab(page, 'engagement').click(); await page.locator('[name="preview_message_delay"]').fill('100');
            await tab(page, 'appearance').click();
            await page.locator('[name="dialoga_save_settings"]').first().click();
            await selected(page, 'general');
            assert.equal(await page.evaluate(() => document.activeElement.name), 'session_duration');
            assert.equal(state.posts.length, 0);
            await page.locator('[name="session_duration"]').fill('7');
            await page.locator('[name="dialoga_save_settings"]').first().click(); await selected(page, 'engagement');
            assert.equal(await page.evaluate(() => document.activeElement.name), 'preview_message_delay');
            assert.equal(state.posts.length, 0);
        });
        await test('no JavaScript exposes every panel and roles, fragment links and native saving still work', async ({ page, state }) => {
            for (const slug of slugs) assert.equal(await page.locator('#dialoga-settings-' + slug).isVisible(), true);
            assert.equal(await page.locator('#dialoga-roles-selector').isVisible(), true);
            await tab(page, 'messages').click(); assert.equal(new URL(page.url()).hash, fragment('messages'));
            await page.locator('[name="welcome_message"]').fill('No JS message\nStill saved');
            await Promise.all([page.waitForNavigation(), page.locator('[name="dialoga_save_settings"]').last().click()]);
            assert.equal(state.settings.welcome_message.replace(/\r\n/g, '\n'), 'No JS message\nStill saved'); assert.equal(state.posts.length, 1);
            assert.equal(await page.locator('.dialoga-settings-danger noscript').isVisible(), true);
        }, { js: false });
        for (const locale of ['es_ES', 'es_MX', 'es_AR', 'en_US']) {
            await test('real MO-rendered tabs and translated destructive dialogs: ' + locale, async ({ page, state }) => {
                const dictionary = state.rendered.dictionary;
                assert.equal(await tab(page, 'appearance').textContent(), dictionary.Appearance);
                assert.equal(await page.locator('h1').textContent(), dictionary['Settings']);
                await tab(page, 'danger-zone').click();
                await page.locator('#dialoga-clear-all-leads-btn').click();
                await page.waitForFunction(() => !document.querySelector('#dialoga-clear-all-leads-btn').disabled);
                assert.deepEqual(state.dialogs.map(item => item.message), [dictionary['Delete ALL leads?\n\nThis cannot be undone.'],
                    dictionary['Type DELETE (in caps) to confirm:'], dictionary['Deleted %d lead(s).'].replace('%d', '3')]);
                assert.deepEqual(state.ajax, [{ action: 'dialoga_delete_all_leads', nonce: 'browser-settings-nonce', confirm: 'DELETE' }]);
            }, { site: locale });
        }
        for (const [site, user, spanish] of [['en_US', 'es_MX', true], ['es_ES', 'en_US', false]]) {
            await test('render honors admin user locale over site: ' + site + '/' + user, async ({ page }) => {
                assert.equal(await tab(page, 'appearance').textContent(), spanish ? 'Apariencia' : 'Appearance');
            }, { site, user });
        }
        for (const width of [320, 390, 768, 1440]) {
            await test('actual styles fit viewport and mobile rows stack: ' + width, async ({ page }) => {
                for (const slug of slugs) {
                    await tab(page, slug).click();
                    const layout = await page.evaluate(() => ({ viewport: innerWidth, scroll: document.documentElement.scrollWidth,
                        tabs: (() => {
                            const nav = document.querySelector('.dialoga-settings-tabs');
                            const firstTab = document.querySelector('.dialoga-settings-tab');
                            const box = nav.getBoundingClientRect();
                            return {
                                left: box.left, right: box.right, clientWidth: nav.clientWidth, scrollWidth: nav.scrollWidth,
                                overflowX: getComputedStyle(nav).overflowX, wrap: getComputedStyle(nav).flexWrap,
                                tabHeight: firstTab.getBoundingClientRect().height, whiteSpace: getComputedStyle(firstTab).whiteSpace
                            };
                        })(),
                        rows: [...document.querySelectorAll('.dialoga-settings-panel:not([hidden]) .dialoga-setting-row')].map(row => ({
                            direction: getComputedStyle(row).flexDirection, left: row.getBoundingClientRect().left, right: row.getBoundingClientRect().right,
                            controls: [...row.querySelectorAll('input,select,textarea')].map(el => ({ left: el.getBoundingClientRect().left, right: el.getBoundingClientRect().right }))
                        })) }));
                    assert.ok(layout.scroll <= layout.viewport + 1, slug + ' horizontal overflow: ' + JSON.stringify(layout));
                    assert.ok(layout.tabs.left >= -1 && layout.tabs.right <= width + 1, slug + ' tab bar escapes viewport: ' + JSON.stringify(layout.tabs));
                    assert.equal(layout.tabs.wrap, 'nowrap', 'tabs remain in a single horizontal row');
                    assert.equal(layout.tabs.whiteSpace, 'nowrap', 'tab labels do not wrap');
                    assert.ok(layout.tabs.tabHeight >= 44, 'tabs keep a touch-friendly target');
                    if (width <= 782) assert.ok(['auto', 'scroll'].includes(layout.tabs.overflowX), 'small screens scroll tabs internally');
                    for (const row of layout.rows) {
                        if (width <= 782) assert.equal(row.direction, 'column', 'mobile setting labels/controls stack');
                        for (const box of row.controls) assert.ok(box.left >= -1 && box.right <= width + 1, slug + ' control escapes viewport: ' + JSON.stringify(box));
                    }
                }
            }, { viewport: { width, height: 850 }, site: 'es_ES' });
        }
    } finally { await browser.close(); }
    console.log(`RESULT: ${passed} passed, ${failed} failed (Chrome ${browser.version()}; native DOM, actual PHP/MO/CSS/JS)`);
    if (failed) process.exitCode = 1;
}
main().catch(error => { console.error(error); process.exitCode = 1; });
