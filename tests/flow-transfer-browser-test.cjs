/** Run: node tests/flow-transfer-browser-test.cjs. See companion Markdown. */
'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { createRequire } = require('node:module');
const { spawnSync } = require('node:child_process');
const root = path.resolve(__dirname, '..');
const temporaryTools = '/var/folders/2w/_41g_m7s1b3ds8bs1w06m15r0000gp/T/opencode/flow-transfer-browser-tooling';

function dependency(name) {
    for (const base of [process.env.DIALOGA_BROWSER_TOOLING, root, temporaryTools].filter(Boolean)) {
        try { return createRequire(path.join(base, 'package.json')).resolve(name); } catch {}
    }
    return null;
}

function bridge(input) {
    const result = spawnSync(process.env.PHP_BINARY || 'php', [path.join(__dirname, 'flow-transfer-browser-test.php')], {
        input: JSON.stringify(input), encoding: 'utf8', maxBuffer: 16 * 1024 * 1024
    });
    if (result.error || result.status !== 0) throw new Error(result.error?.message || result.stderr || 'PHP bridge failed');
    return JSON.parse(result.stdout);
}

const node = (id, x, y, message = 'Saved message') => ({
    id, type: 'custom', position: { x, y }, data: { nodeType: 'question', message, is_start: id === 'n1', options: [] }
});
const savedGraph = { nodes: [node('n1', 12, 20)], edges: [] };
const freshGraph = {
    nodes: [node('n1', 412, 520), node('n2', 650, 700, 'New node')],
    edges: [{ id: 'fresh-edge', source: 'n1', target: 'n2' }]
};
const flow = (id, name) => ({ id, flow_name: name, is_active: 1, visibility_mode: 'everyone',
    visible_roles: '[]', flow_data: JSON.stringify(savedGraph), updated_at: '2026-10-31 12:00:00' });
const packageFixture = () => ({ format: 'dialoga-flows', version: 1, plugin_version: 'elsewhere', flows: [
    { flow_name: 'Imported sales', visibility_mode: 'everyone', visible_roles: [], flow_data: {
        nodes: [node('n1', 12, 20), node('n2', 100, 200)],
        edges: [{ id: 'e1', source: 'n1', target: 'n2' }]
    } },
    { flow_name: 'Imported draft', visibility_mode: 'logged_in', visible_roles: [], flow_data: { nodes: [], edges: [] } }
] });

async function main() {
    const playwrightPath = dependency('playwright') || dependency('playwright-core');
    const jqueryPath = dependency('jquery');
    if (!playwrightPath || !jqueryPath || spawnSync(process.env.PHP_BINARY || 'php', ['--version']).status !== 0) {
        console.log('SKIP: requires external Playwright, jQuery 3.x and PHP. Set DIALOGA_BROWSER_TOOLING; see companion Markdown.');
        if (process.env.DIALOGA_BROWSER_REQUIRE === '1') process.exitCode = 1;
        return;
    }
    const { chromium } = require(playwrightPath);
    let browser;
    try {
        const executablePath = process.env.DIALOGA_BROWSER_EXECUTABLE;
        browser = await chromium.launch(executablePath ? { executablePath } :
            fs.existsSync('/Applications/Google Chrome.app') ? { channel: 'chrome' } : {});
    } catch (error) {
        console.log('SKIP: browser unavailable: ' + error.message);
        if (process.env.DIALOGA_BROWSER_REQUIRE === '1') process.exitCode = 1;
        return;
    }
    const rendered = bridge({ render: true });
    const adminSource = fs.readFileSync(path.join(root, 'assets/js/admin.js'), 'utf8');
    // Evaluate the actual object unchanged, omitting only document-ready init and its unrelated timers.
    const builderSource = adminSource.slice(adminSource.indexOf('    function t(message)'),
        adminSource.indexOf('    $(document).ready(function()')) + '\nwindow.dialogaBuilder = dialogaBuilder;';
    let passed = 0;
    let failed = 0;

    async function test(name, run, viewport = { width: 1440, height: 900 }) {
        const context = await browser.newContext({ viewport, acceptDownloads: true });
        const page = await context.newPage();
        const errors = [];
        const state = { rows: [flow(7, 'Saved current'), flow(8, 'Other flow'), flow(9, 'Unselected flow')], requests: [], fault: null, gate: null };
        page.on('pageerror', error => errors.push(error.message));
        await page.route('http://dialoga.test/**', async route => {
            const request = route.request();
            const url = new URL(request.url());
            if (url.pathname === '/fixture') {
                await route.fulfill({ contentType: 'text/html', body: '<!doctype html><html><head><style>body{margin:0}#wpadminbar{height:32px}#adminmenumain{width:160px;height:40px}#wpcontent{margin-left:160px}button{min-height:30px}</style></head><body><div id="wpadminbar">Admin bar</div><div id="adminmenumain">Admin menu</div><div id="wpcontent">' + rendered.html + '</div></body></html>' });
                return;
            }
            if (url.pathname !== '/wp-admin/admin-ajax.php') {
                await route.fulfill({ status: 404, body: '' });
                return;
            }
            const raw = request.postData() || '';
            const fields = {};
            if ((request.headers()['content-type'] || '').includes('multipart/form-data')) {
                for (const match of raw.matchAll(/name="([^"]+)"\r\n\r\n([\s\S]*?)(?=\r\n--)/g)) fields[match[1]] = match[2];
            } else Object.assign(fields, Object.fromEntries(new URLSearchParams(raw)));
            assert.equal(fields.nonce, 'browser-test-nonce', 'AJAX nonce contract');
            const action = fields.action;
            delete fields.action;
            delete fields.nonce;
            const decoded = Object.fromEntries(Object.entries(fields).map(([key, value]) => [key, JSON.parse(value)]));
            state.requests.push({ action, fields: decoded });
            if (state.gate) await state.gate;
            if (state.fault) {
                const fault = state.fault;
                state.fault = null;
                if (fault.abort) await route.abort('failed');
                else await route.fulfill({ status: fault.status || 500, contentType: fault.contentType || 'application/json',
                    body: fault.body || JSON.stringify({ success: false, data: { message: 'Export package exceeds 5 MiB; select fewer flows.' } }) });
                return;
            }
            if (action === 'dialoga_get_flows') {
                await route.fulfill({ json: { success: true, data: state.rows } });
                return;
            }
            const response = bridge({ action, fields: decoded, rows: state.rows });
            state.rows = response.rows;
            await route.fulfill({ status: response.status, json: response.body });
        });
        try {
            await page.goto('http://dialoga.test/fixture');
            await page.addStyleTag({ path: path.join(root, 'assets/css/admin.css') });
            await page.addStyleTag({ path: path.join(root, 'assets/css/screen-builder.css') });
            await page.addScriptTag({ path: jqueryPath });
            await page.addScriptTag({ content: '(function($){' + builderSource + '})(jQuery);' });
            await page.evaluate(({ templates, savedGraph, freshGraph }) => {
                window.dialogaTemplates = templates;
                window.dialogaAdmin = { ajaxUrl: '/wp-admin/admin-ajax.php', nonce: 'browser-test-nonce', builderMode: 'editor', builderUrl: '/wp-admin/admin.php?page=dialoga-builder', flowId: 7 };
                window.fixture = { messages: [], saveCalls: [], loadCalls: 0, switchCalls: 0, resizeCalls: 0,
                    graph: structuredClone(freshGraph), writes: [] };
                window.dialogaModal = Object.fromEntries(['alert', 'error', 'success'].map(type => [type,
                    (message, title) => fixture.messages.push({ type, message, title })]));
                Object.assign(dialogaBuilder, { isEditor: true, currentFlowId: '7', nodes: structuredClone(savedGraph.nodes),
                    edges: structuredClone(savedGraph.edges), selectedNode: null });
                dialogaBuilder.markDirty = () => { dialogaBuilder.isDirty = true; };
                const save = dialogaBuilder.saveNodeEdits;
                dialogaBuilder.saveNodeEdits = function(silent) { fixture.saveCalls.push(silent); return save.call(this, silent); };
                const load = dialogaBuilder.loadFlows;
                dialogaBuilder.loadFlows = function() { fixture.loadCalls++; return load.call(this); };
                dialogaBuilder.loadFlow = () => { fixture.switchCalls++; };
                window.dialogaReactInstance = {
                    getFlow: () => structuredClone(fixture.graph),
                    setFlow: graph => {
                        fixture.writes.push(structuredClone(graph));
                        requestAnimationFrame(() => { fixture.graph = structuredClone(graph); });
                    }
                };
                window.addEventListener('resize', () => fixture.resizeCalls++);
                jQuery('#dialoga-builder-empty-state').hide();
                jQuery('#dialoga-builder-active, #dialoga-canvas').show();
                jQuery('#dialoga-flow-name').val('Unsaved current name');
                jQuery('#dialoga-flow-visibility').val('specific_roles');
                jQuery('.dialoga-role-checkbox[value="administrator"]').prop('checked', true);
            }, { templates: rendered.templates, savedGraph, freshGraph });
            await page.addScriptTag({ path: path.join(root, 'assets/js/flow-transfer.js') });
            await page.waitForFunction(() => Boolean(jQuery._data(document.querySelector('.dialoga-export-current'), 'events')?.click));
            await run({ page, state, errors });
            assert.deepEqual(errors, [], 'no uncaught browser errors');
            console.log('PASS: ' + name);
            passed++;
        } catch (error) {
            console.error('FAIL: ' + name + '\n  ' + error.message);
            if (errors.length) console.error('  Browser errors: ' + errors.join('; '));
            failed++;
        } finally { await context.close(); }
    }

    async function downloaded(page, trigger) {
        const pending = page.waitForEvent('download');
        await trigger();
        const download = await pending;
        assert.match(download.suggestedFilename(), /^dialoga-flows-\d{4}-\d{2}-\d{2}\.json$/);
        return JSON.parse(fs.readFileSync(await download.path(), 'utf8'));
    }
    async function upload(page, pkg, name = 'flows.json') {
        await page.locator('#dialoga-import-file').setInputFiles({ name, mimeType: 'application/json',
            buffer: Buffer.from(typeof pkg === 'string' ? pkg : JSON.stringify(pkg)) });
    }
    async function addListTransferControls(page) {
        await page.evaluate(() => {
            if (document.querySelector('.dialoga-export-flows')) return;
            const actions = document.createElement('div');
            actions.className = 'dialoga-builder-list-actions';
            actions.innerHTML = '<button type="button" class="dialoga-export-flows">Export flows</button>';
            document.body.append(actions);
        });
    }
    async function message(page, type, fragment) {
        await page.waitForFunction(({ type, fragment }) => fixture.messages.some(item => item.type === type && item.message.includes(fragment)), { type, fragment });
    }
    const submit = page => page.locator('.dialoga-transfer-actions .button-primary');

    try {
        await test('individual download uses current form and latest graph, not saved DB graph', async ({ page, state }) => {
            assert.equal(await page.locator('.dialoga-import-flows').count(), 1,
                'editor exposes import for a single flow workflow');
            assert.equal(await page.locator('.dialoga-export-flows').count(), 0,
                'editor does not expose bulk export');
            const pkg = await downloaded(page, () => page.locator('.dialoga-export-current').click());
            assert.equal(pkg.format, 'dialoga-flows');
            assert.equal(pkg.version, 1);
            assert.equal(pkg.flows.length, 1);
            assert.equal(pkg.flows[0].flow_name, 'Unsaved current name');
            assert.equal(pkg.flows[0].visibility_mode, 'specific_roles');
            assert.deepEqual(pkg.flows[0].visible_roles, ['administrator']);
            assert.deepEqual(pkg.flows[0].flow_data, freshGraph);
            assert.equal(state.rows[0].flow_name, 'Saved current');
            assert.equal(state.requests[0].fields.current_flow.id, 7);
            assert.equal(await page.locator('.dialoga-export-current').isEnabled(), true);
        });

        for (const width of [1025, 1100, 1280, 1440]) {
            await test('editor toolbar stays on one row at ' + width, async ({ page }) => {
                await page.evaluate(() => {
                    jQuery('#dialoga-visibility-settings').show();
                    jQuery('#dialoga-save-status')
                        .removeClass('dialoga-save-status--saved')
                        .addClass('dialoga-save-status--unsaved')
                        .find('.dialoga-save-status-text').text('Unsaved changes');
                });
                const layout = await page.evaluate(() => {
                    const toolbar = document.querySelector('.dialoga-builder-toolbar');
                    const groups = [...toolbar.querySelectorAll(':scope > div')].map(item => item.getBoundingClientRect());
                    return {
                        height: toolbar.getBoundingClientRect().height,
                        scrollWidth: toolbar.scrollWidth,
                        clientWidth: toolbar.clientWidth,
                        centers: groups.map(box => Math.round(box.top + box.height / 2))
                    };
                });
                assert.equal(new Set(layout.centers).size, 1, 'toolbar groups share one row');
                assert.ok(layout.scrollWidth <= layout.clientWidth, 'toolbar does not overflow horizontally: ' + JSON.stringify(layout));
            }, { width, height: 900 });
        }

        await test('selectedNode form commit preserves fresh graph positions, new nodes and edges', async ({ page }) => {
            await page.evaluate(() => {
                dialogaBuilder.selectedNode = structuredClone(dialogaBuilder.nodes[0]);
                jQuery('#dialoga-node-editor').show();
                jQuery('#dialoga-node-editor-content').html('<textarea class="dialoga-node-field" data-field="message">Latest form message</textarea>');
            });
            const pkg = await downloaded(page, () => page.locator('.dialoga-export-current').click());
            assert.deepEqual(await page.evaluate(() => fixture.saveCalls), [true]);
            assert.equal(pkg.flows[0].flow_data.nodes[0].data.message, 'Latest form message');
            assert.deepEqual({ positions: pkg.flows[0].flow_data.nodes.map(item => item.position),
                ids: pkg.flows[0].flow_data.nodes.map(item => item.id), edges: pkg.flows[0].flow_data.edges,
                livePosition: await page.evaluate(() => fixture.graph.nodes[0].position) },
            { positions: freshGraph.nodes.map(item => item.position), ids: ['n1', 'n2'], edges: freshGraph.edges,
                livePosition: freshGraph.nodes[0].position }, 'silent save must preserve latest graph, including live canvas state');
        });

        await test('question quick replies survive normal save with detached XYFlow node', async ({ page }) => {
            await page.evaluate(() => {
                dialogaBuilder.selectedNode = structuredClone(dialogaBuilder.nodes[0]);
                jQuery('#dialoga-node-editor').show();
                jQuery('#dialoga-node-editor-content').html(`
                    <textarea class="dialoga-node-field" data-field="message">Updated question</textarea>
                    <div class="dialoga-option-item" data-option-id="yes">
                        <input class="dialoga-option-label" value="Yes, please">
                    </div>
                    <div class="dialoga-option-item" data-option-id="no">
                        <input class="dialoga-option-label" value="No, thanks">
                    </div>
                `);
                dialogaBuilder.saveNodeEdits(false);
            });
            await page.waitForFunction(() => fixture.graph.nodes[0]?.data?.options?.length === 2);
            const result = await page.evaluate(() => ({
                graph: fixture.graph,
                write: fixture.writes.at(-1),
            }));
            const expectedOptions = [
                { id: 'yes', label: 'Yes, please' },
                { id: 'no', label: 'No, thanks' },
            ];
            assert.deepEqual(result.graph.nodes[0].data.options, expectedOptions);
            assert.equal(result.graph.nodes[0].data.message, 'Updated question');
            assert.deepEqual(result.graph.nodes.map(item => item.position), freshGraph.nodes.map(item => item.position));
            assert.deepEqual(result.graph.edges, freshGraph.edges);
            assert.deepEqual(result.write.nodes[0].data.options, expectedOptions);
        });

        await test('loop message and delay save for free installs with detached XYFlow node', async ({ page }) => {
            await page.evaluate(() => {
                const loopGraph = {
                    nodes: [
                        { id: 'loop', type: 'custom', position: { x: 320, y: 400 }, data: { nodeType: 'loop', label: 'Loop' } },
                        { id: 'target', type: 'custom', position: { x: 80, y: 100 }, data: { nodeType: 'question', message: 'Target' } },
                    ],
                    edges: [{ id: 'loop-edge', source: 'loop', sourceHandle: 'left', target: 'target', type: 'smoothstep' }],
                };
                fixture.graph = structuredClone(loopGraph);
                dialogaBuilder.nodes = structuredClone(loopGraph.nodes);
                dialogaBuilder.edges = structuredClone(loopGraph.edges);
                dialogaBuilder.selectedNode = structuredClone(loopGraph.nodes[0]);
                jQuery('#dialoga-node-editor').show();
                jQuery('#dialoga-node-editor-content').html(`
                    <textarea id="dialoga-loop-message">Anything else?</textarea>
                    <input type="number" id="dialoga-loop-delay" value="1.5">
                `);
                dialogaBuilder.saveNodeEdits(false);
            });
            await page.waitForFunction(() => fixture.graph.nodes[0]?.data?.delay_ms === 1500);
            const graph = await page.evaluate(() => fixture.graph);
            assert.equal(graph.nodes[0].data.message, 'Anything else?');
            assert.equal(graph.nodes[0].data.delay_ms, 1500);
            assert.equal(graph.nodes[0].data.target_node_id, 'target');
            assert.deepEqual(graph.edges, [{ id: 'loop-edge', source: 'loop', sourceHandle: 'left', target: 'target', type: 'smoothstep' }]);
        });

        await test('mass export sends only selected IDs and current override only when included', async ({ page, state }) => {
            await addListTransferControls(page);
            await page.locator('.dialoga-export-flows').click();
            await page.locator('.dialoga-export-choice[value="8"]').check();
            const pkg = await downloaded(page, () => submit(page).click());
            assert.equal(pkg.flows.length, 2);
            assert.deepEqual(pkg.flows.map(item => item.flow_name), ['Unsaved current name', 'Other flow']);
            assert.deepEqual(state.requests.find(item => item.action === 'dialoga_export_flows').fields.flow_ids, [7, 8]);
            await page.waitForSelector('.dialoga-transfer-overlay', { state: 'detached' });
            await page.locator('.dialoga-export-flows').click();
            await page.locator('.dialoga-export-choice[value="7"]').uncheck();
            await page.locator('.dialoga-export-choice[value="9"]').check();
            await downloaded(page, () => submit(page).click());
            const request = state.requests.filter(item => item.action === 'dialoga_export_flows').at(-1);
            assert.deepEqual(request.fields.flow_ids, [9]);
            assert.equal('current_flow' in request.fields, false);
        });

        await test('mass export select all, empty selection and 100-flow limit', async ({ page, state }) => {
            await addListTransferControls(page);
            state.rows = Array.from({ length: 101 }, (_, index) => flow(index + 1, 'Flow ' + (index + 1)));
            await page.locator('.dialoga-export-flows').click();
            await page.locator('.dialoga-export-choice[value="7"]').uncheck();
            await submit(page).click();
            await page.waitForFunction(() => document.querySelector('.dialoga-transfer-status').textContent.includes('Select at least one'));
            await page.locator('.dialoga-transfer-content input').first().check();
            assert.equal(await page.locator('.dialoga-export-choice:checked').count(), 101);
            await submit(page).click();
            await page.waitForFunction(() => document.querySelector('.dialoga-transfer-status').textContent.includes('up to 100'));
            assert.equal(state.requests.filter(item => item.action === 'dialoga_export_flows').length, 0);
            await page.locator('.dialoga-export-choice[value="101"]').uncheck();
            const pkg = await downloaded(page, () => submit(page).click());
            assert.equal(pkg.flows.length, 100);
        });

        await test('import previews exact counts and scrub warnings, commits inactive without switching editor', async ({ page, state }) => {
            await addListTransferControls(page);
            const pkg = packageFixture();
            pkg.flows[0].flow_data.nodes[0].data.config = { api_key: 'private', connector_id: 'local', url: 'https://example.com/review' };
            await page.evaluate(() => { window.beforeImportGraph = structuredClone(fixture.graph); });
            await upload(page, '\uFEFF' + JSON.stringify(pkg));
            await page.getByRole('heading', { name: 'Import 2 flow(s)' }).waitFor();
            assert.match(await page.locator('.dialoga-transfer-content').textContent(), /Imported sales \(2 nodes, 1 connections\)/);
            assert.match(await page.locator('.dialoga-transfer-content').textContent(), /Imported draft \(0 nodes, 0 connections\)/);
            assert.equal(await page.locator('.dialoga-transfer-warnings li').count(), 3);
            assert.equal(state.rows.length, 3, 'preview does not write');
            await submit(page).click();
            await message(page, 'success', '2 flow(s) imported as inactive copies');
            await page.waitForFunction(() => fixture.loadCalls === 1);
            assert.equal(state.rows.length, 5);
            assert.deepEqual(state.rows.slice(3).map(item => item.is_active), [0, 0]);
            const config = JSON.parse(state.rows[3].flow_data).nodes[0].data.config;
            assert.equal('api_key' in config, false);
            assert.equal(config.connector_id, '');
            assert.deepEqual(await page.evaluate(() => ({ id: dialogaBuilder.currentFlowId, switches: fixture.switchCalls,
                unchanged: JSON.stringify(fixture.graph) === JSON.stringify(beforeImportGraph) })), { id: '7', switches: 0, unchanged: true });
        });

        await test('production editor escapes flow names without rendering a sidebar', async ({ page }) => {
            assert.equal(await page.locator('.dialoga-flows-sidebar').count(), 0);
            assert.equal(await page.evaluate(() => dialogaBuilder.escapeEditorHtml('Sales <script>alert(1)</script> & $&')),
                'Sales &lt;script&gt;alert(1)&lt;/script&gt; &amp; $&amp;');
        });

        await test('invalid JSON, wrong extension, oversized upload and invalid package fail without commits', async ({ page, state }) => {
            await addListTransferControls(page);
            await upload(page, '{bad');
            await message(page, 'error', 'does not contain valid JSON');
            assert.equal(state.requests.length, 0);
            await upload(page, '{}', 'flows.txt');
            await message(page, 'error', 'no larger than 5 MiB');
            await page.evaluate(() => { fixture.messages = []; });
            await upload(page, ' '.repeat(5242881));
            await message(page, 'error', 'no larger than 5 MiB');
            assert.equal(state.requests.length, 0);
            await upload(page, { format: 'other', version: 1, flows: [] });
            await message(page, 'error', 'version 1');
            assert.equal(state.requests.length, 1);
            assert.equal(state.rows.length, 3);
            assert.equal(await page.locator('#dialoga-import-file').inputValue(), '');
            assert.equal(await page.locator('.dialoga-import-flows').isEnabled(), true);
        });

        await test('import commit failure remains in preview, reenables controls and never switches or reloads editor', async ({ page, state }) => {
            await addListTransferControls(page);
            await upload(page, packageFixture());
            await page.locator('.dialoga-transfer-dialog').waitFor();
            state.fault = { body: JSON.stringify({ success: false, data: {
                message: 'Import failed. All flows created by this request were removed.' } }) };
            await submit(page).click();
            await page.waitForFunction(() => document.querySelector('.dialoga-transfer-status').textContent.includes('Import failed'));
            assert.equal(await submit(page).isEnabled(), true);
            assert.equal(await page.locator('.dialoga-import-flows').isEnabled(), true);
            assert.deepEqual(await page.evaluate(() => ({ reloads: fixture.loadCalls, switches: fixture.switchCalls,
                id: dialogaBuilder.currentFlowId })), { reloads: 0, switches: 0, id: '7' });
            assert.equal(state.rows.length, 3);
            await page.keyboard.press('Escape');
            assert.equal(await page.locator('.dialoga-transfer-dialog').count(), 0);
        });

        await test('export progress disables controls, prevents duplicates, exposes server error and permits retry', async ({ page, state }) => {
            await addListTransferControls(page);
            let release;
            state.gate = new Promise(resolve => { release = resolve; });
            await page.locator('.dialoga-export-flows').click();
            await page.waitForFunction(() => document.querySelector('.dialoga-export-flows').disabled);
            assert.equal(await page.locator('.dialoga-import-flows').isDisabled(), true);
            release();
            state.gate = null;
            await page.locator('.dialoga-transfer-dialog').waitFor();
            state.gate = new Promise(resolve => { release = resolve; });
            state.fault = {};
            await submit(page).click();
            assert.equal(await submit(page).isDisabled(), true);
            assert.equal(await page.locator('.dialoga-transfer-status').textContent(), 'Working...');
            await page.evaluate(() => document.querySelector('.dialoga-transfer-actions .button-primary').click());
            await page.keyboard.press('Escape');
            assert.equal(await page.locator('.dialoga-transfer-dialog').count(), 1);
            release();
            state.gate = null;
            await page.waitForFunction(() => document.querySelector('.dialoga-transfer-status').textContent.includes('exceeds 5 MiB'));
            assert.equal(await submit(page).isEnabled(), true);
            assert.equal(state.requests.filter(item => item.action === 'dialoga_export_flows').length, 1);
            await downloaded(page, () => submit(page).click());
        });

        for (const fault of [{ contentType: 'text/html', body: '<html>Upload limit</html>' }, { abort: true }]) {
            await test('individual export recovers from ' + (fault.abort ? 'network failure' : 'non-JSON server response'), async ({ page, state }) => {
                state.fault = fault;
                await page.locator('.dialoga-export-current').click();
                await message(page, 'error', fault.abort ? 'Failed to fetch' : 'invalid response');
                await page.waitForFunction(() => !document.querySelector('.dialoga-export-current').disabled);
                await downloaded(page, () => page.locator('.dialoga-export-current').click());
            });
        }

        await test('export warning appears after selection dialog closes', async ({ page, state }) => {
            await addListTransferControls(page);
            const graph = structuredClone(savedGraph);
            graph.nodes[0].data.config = { api_key: 'private' };
            state.rows[1].flow_data = JSON.stringify(graph);
            await page.locator('.dialoga-export-flows').click();
            await page.locator('.dialoga-export-choice[value="7"]').uncheck();
            await page.locator('.dialoga-export-choice[value="8"]').check();
            const pkg = await downloaded(page, () => submit(page).click());
            await message(page, 'alert', 'Credential fields');
            assert.equal(await page.locator('.dialoga-transfer-dialog').count(), 0);
            assert.equal('api_key' in pkg.flows[0].flow_data.nodes[0].data.config, false);
        });

        for (const viewport of [{ width: 1440, height: 900 }, { width: 1100, height: 650 }]) {
            await test('fullscreen hides admin chrome, fits and resizes viewport ' + viewport.width, async ({ page }) => {
                await page.locator('.dialoga-fullscreen-toggle').click();
                assert.equal(await page.locator('body').evaluate(el => el.classList.contains('dialoga-canvas-fullscreen')), true);
                assert.equal(await page.locator('.dialoga-fullscreen-toggle').getAttribute('aria-pressed'), 'true');
                for (const selector of ['#wpadminbar', '#adminmenumain'])
                    assert.equal(await page.locator(selector).evaluate(el => getComputedStyle(el).visibility), 'hidden');
                assert.equal(await page.locator('.dialoga-flows-sidebar').count(), 0);
                for (const size of [viewport, { width: viewport.width - 40, height: viewport.height - 90 }]) {
                    await page.setViewportSize(size);
                    const box = await page.locator('.dialoga-builder-container').boundingBox();
                    assert.deepEqual(box, { x: 0, y: 0, width: size.width, height: size.height });
                    const canvas = await page.locator('.dialoga-canvas').boundingBox();
                    const minimum = await page.locator('.dialoga-canvas').evaluate(el => getComputedStyle(el).minHeight);
                    assert.ok(canvas.height > 80 && canvas.y + canvas.height <= size.height + 1,
                        `canvas fits viewport: ${JSON.stringify({ viewport: size, canvas, minimum })}`);
                }
                await page.waitForFunction(() => fixture.resizeCalls >= 2);
                await page.keyboard.press('Escape');
                assert.equal(await page.locator('.dialoga-fullscreen-toggle').getAttribute('aria-pressed'), 'false');
                assert.equal(await page.locator('.dialoga-flows-sidebar').count(), 0);
                assert.equal(await page.locator('#wpadminbar').evaluate(el => getComputedStyle(el).visibility), 'visible');
            }, viewport);
        }

        await test('dialog Tab/Shift-Tab focus trap and Escape closes dialog before fullscreen; focus/scroll restored', async ({ page }) => {
            await addListTransferControls(page);
            await page.evaluate(() => window.scrollTo(0, 100));
            const scroll = await page.evaluate(() => window.scrollY);
            await page.locator('.dialoga-fullscreen-toggle').click();
            // Import controls are intentionally hidden in expanded mode; dispatch the same public change event.
            await upload(page, packageFixture());
            await page.locator('.dialoga-transfer-dialog').waitFor();
            assert.equal(await page.evaluate(() => document.activeElement.textContent), 'Cancel');
            await page.keyboard.press('Shift+Tab');
            assert.equal(await page.evaluate(() => document.activeElement.textContent), 'Import inactive copies');
            await page.keyboard.press('Tab');
            assert.equal(await page.evaluate(() => document.activeElement.textContent), 'Cancel');
            await page.keyboard.press('Escape');
            assert.equal(await page.locator('.dialoga-transfer-dialog').count(), 0);
            assert.equal(await page.locator('.dialoga-fullscreen-toggle').getAttribute('aria-pressed'), 'true');
            assert.equal(await page.locator('.dialoga-fullscreen-toggle').evaluate(el => el === document.activeElement), true);
            await page.evaluate(() => { const modal = document.createElement('div'); modal.className = 'dialoga-modal-overlay'; document.body.append(modal); });
            await page.keyboard.press('Escape');
            assert.equal(await page.locator('.dialoga-fullscreen-toggle').getAttribute('aria-pressed'), 'true');
            await page.evaluate(() => document.querySelector('.dialoga-modal-overlay').remove());
            await page.keyboard.press('Escape');
            assert.equal(await page.locator('.dialoga-fullscreen-toggle').getAttribute('aria-pressed'), 'false');
            assert.equal(await page.locator('.dialoga-fullscreen-toggle').evaluate(el => el === document.activeElement), true);
            assert.equal(await page.evaluate(() => window.scrollY), scroll);
        });

        await test('hiding active builder automatically exits fullscreen', async ({ page }) => {
            await page.locator('.dialoga-fullscreen-toggle').click();
            await page.evaluate(() => jQuery('#dialoga-builder-active').hide());
            await page.waitForFunction(() => !document.body.classList.contains('dialoga-canvas-fullscreen'));
            assert.equal(await page.locator('.dialoga-fullscreen-toggle').getAttribute('aria-pressed'), 'false');
        });

        await test('fullscreen exit button restores label, icon and focus', async ({ page }) => {
            const button = page.locator('.dialoga-fullscreen-toggle');
            await button.click();
            assert.equal(await button.locator('.dialoga-fullscreen-label').textContent(), 'Exit full screen');
            assert.equal(await button.locator('.dashicons').getAttribute('class'), 'dashicons dashicons-editor-contract');
            await button.click();
            assert.equal(await button.getAttribute('aria-pressed'), 'false');
            assert.equal(await button.locator('.dialoga-fullscreen-label').textContent(), 'Full screen');
            assert.equal(await button.locator('.dashicons').getAttribute('class'), 'dashicons dashicons-editor-expand');
            assert.equal(await button.evaluate(el => el === document.activeElement), true);
        });
    } finally { await browser.close(); }
    console.log(`RESULT: ${passed} passed, ${failed} failed (Chromium ${browser.version()})`);
    if (failed) process.exitCode = 1;
}

main().catch(error => { console.error(error); process.exitCode = 1; });
