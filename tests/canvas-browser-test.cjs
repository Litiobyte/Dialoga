'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { createRequire } = require('node:module');

const root = path.resolve(__dirname, '..');
const temporaryTools = '/var/folders/2w/_41g_m7s1b3ds8bs1w06m15r0000gp/T/opencode/flow-transfer-browser-tooling';

function dependency(name) {
    for (const base of [process.env.DIALOGAWP_BROWSER_TOOLING, root, temporaryTools].filter(Boolean)) {
        try { return createRequire(path.join(base, 'package.json')).resolve(name); } catch {}
    }
    return null;
}

async function run() {
    const playwrightPath = dependency('playwright') || dependency('playwright-core');
    if (!playwrightPath) {
        if (process.env.DIALOGAWP_BROWSER_REQUIRE === '1') throw new Error('Playwright is required');
        console.log('SKIP: canvas browser test (Playwright unavailable)');
        return;
    }
    const { chromium } = require(playwrightPath);
    let browser;
    try {
        browser = await chromium.launch(process.env.DIALOGAWP_BROWSER_EXECUTABLE
            ? { headless: true, executablePath: process.env.DIALOGAWP_BROWSER_EXECUTABLE }
            : (fs.existsSync('/Applications/Google Chrome.app') ? { headless: true, channel: 'chrome' } : { headless: true }));
    } catch (error) {
        if (process.env.DIALOGAWP_BROWSER_REQUIRE === '1') throw error;
        console.log('SKIP: canvas browser test (Chrome unavailable): ' + error.message);
        return;
    }
    const page = await browser.newPage({ viewport: { width: 1280, height: 900 } });
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));

    await page.setContent('<div class="dialogawp-builder-wrap"><div id="dialogawp-canvas" style="width:1100px;height:700px"></div></div>');
    await page.addStyleTag({ path: path.join(root, 'assets/js/build/flow-canvas.css') });
    await page.addScriptTag({ path: path.join(root, 'node_modules/react/umd/react.production.min.js') });
    await page.addScriptTag({ path: path.join(root, 'node_modules/react-dom/umd/react-dom.production.min.js') });
    await page.evaluate(() => {
        window.dialogawpAdmin = { i18n: {}, logoUrl: '' };
        window.dialogawpModal = { confirm(message, callback) { callback(); } };
        window.dialogawpBuilder = {
            selectedNode: null,
            dropped: null,
            addNodeAtPosition(type, x, y, isStart) { this.dropped = { type, x, y, isStart }; },
            selectNode(node) { this.selectedNode = node; },
            deleteNode() {},
        };
    });
    await page.addScriptTag({ path: path.join(root, 'assets/js/build/flow-canvas.js') });

    const initial = {
        nodes: [
            { id: 'question', type: 'custom', position: { x: 80, y: 180 }, data: { nodeType: 'question', label: 'Question', options: [{ id: 'a', label: 'A' }] } },
            { id: 'branch', type: 'custom', position: { x: 420, y: 180 }, data: { nodeType: 'branch', output_groups: [
                { id: 'yes', logic: 'and', conditions: [{ value: 'yes' }] },
                { id: 'no', logic: 'and', conditions: [{ value: 'no' }] },
            ] } },
            { id: 'loop', type: 'custom', position: { x: 720, y: 360 }, data: { nodeType: 'loop', label: 'Loop' } },
        ],
        edges: [
            { id: 'start', source: '__start__', sourceHandle: null, target: 'question', type: 'smoothstep', animated: true },
            { id: 'to-branch', source: 'question', sourceHandle: null, target: 'branch', type: 'smoothstep', animated: true },
            { id: 'loop-back', source: 'loop', sourceHandle: 'left', target: 'question', type: 'smoothstep', animated: true },
        ],
    };

    await page.evaluate(initialFlow => {
        const root = ReactDOM.createRoot(document.getElementById('dialogawp-canvas'));
        root.render(React.createElement(window.dialogawpCanvasComponent, {
            initialNodes: initialFlow.nodes,
            initialEdges: initialFlow.edges,
            onNodeClick(node) { window.dialogawpBuilder.selectNode(node); },
        }));
    }, initial);
    await page.waitForFunction(() => window.dialogawpReactInstance && document.querySelectorAll('.react-flow__node').length === 4);

    const flow = await page.evaluate(() => window.dialogawpReactInstance.getFlow());
    assert.deepEqual(flow, initial, 'portable graph remains unchanged and excludes transient start node');
    assert.equal(await page.locator('.react-flow__node[data-id="branch"] .react-flow__handle.source').count(), 2, 'branch exposes one source handle per output group');
    assert.equal(await page.locator('.react-flow__node[data-id="question"] .react-flow__handle.source').count(), 1, 'question keeps one generic output');
    assert.equal(await page.locator('.react-flow__node[data-id="loop"] .react-flow__handle.source').count(), 2, 'loop exposes left and right handles');

    const replacement = {
        nodes: [
            { id: 'fresh', type: 'custom', position: { x: 150, y: 220 }, data: { nodeType: 'message', label: 'Fresh' } },
            { id: 'target', type: 'custom', position: { x: 550, y: 300 }, data: { nodeType: 'action', label: 'Target' } },
        ],
        edges: [{ id: 'fresh-start', source: '__start__', sourceHandle: null, target: 'fresh', type: 'smoothstep', animated: true }],
    };
    const immediate = await page.evaluate(next => {
        window.dialogawpReactInstance.setFlow(next);
        return window.dialogawpReactInstance.getFlow();
    }, replacement);
    assert.deepEqual(immediate, replacement, 'setFlow followed by getFlow is synchronous');
    await page.waitForFunction(() => document.querySelector('.react-flow__node[data-id="fresh"]'));

    await page.locator('.react-flow__node[data-id="fresh"]').click();
    assert.equal(await page.evaluate(() => window.dialogawpBuilder.selectedNode.id), 'fresh', 'node click preserves callback contract');

    const beforeDrag = await page.evaluate(() => window.dialogawpReactInstance.getFlow().nodes.find(node => node.id === 'fresh').position);
    const nodeBox = await page.locator('.react-flow__node[data-id="fresh"]').boundingBox();
    await page.mouse.move(nodeBox.x + 80, nodeBox.y + 30);
    await page.mouse.down();
    await page.mouse.move(nodeBox.x + 140, nodeBox.y + 70, { steps: 5 });
    await page.mouse.up();
    const afterDrag = await page.evaluate(() => window.dialogawpReactInstance.getFlow().nodes.find(node => node.id === 'fresh').position);
    assert.notDeepEqual(afterDrag, beforeDrag, 'node dragging updates the portable position');

    const sourceBox = await page.locator('.react-flow__node[data-id="fresh"] .react-flow__handle.source').boundingBox();
    const targetBox = await page.locator('.react-flow__node[data-id="target"] .react-flow__handle.target').boundingBox();
    await page.mouse.move(sourceBox.x + sourceBox.width / 2, sourceBox.y + sourceBox.height / 2);
    await page.mouse.down();
    await page.mouse.move(targetBox.x + targetBox.width / 2, targetBox.y + targetBox.height / 2, { steps: 8 });
    await page.mouse.up();
    await page.waitForFunction(() => window.dialogawpReactInstance.getFlow().edges.length === 2);
    const connected = await page.evaluate(() => window.dialogawpReactInstance.getFlow().edges[1]);
    assert.equal(connected.source, 'fresh');
    assert.equal(connected.target, 'target');
    assert.equal(connected.type, 'smoothstep');

    await page.locator('.react-flow__edge').last().hover();
    await page.locator('.dialogawp-rf-edge-delete').click();
    await page.waitForFunction(() => window.dialogawpReactInstance.getFlow().edges.length === 1);

    const largeFlow = {
        nodes: Array.from({ length: 100 }, (_, index) => ({
            id: `node-${index}`,
            type: 'custom',
            position: { x: (index % 10) * 260, y: Math.floor(index / 10) * 140 },
            data: { nodeType: index % 5 === 0 ? 'branch' : 'question', label: `Node ${index}`, output_groups: [] },
        })),
        edges: [],
    };
    await page.evaluate(next => window.dialogawpReactInstance.setFlow(next), largeFlow);
    await page.waitForFunction(() => document.querySelectorAll('.react-flow__node').length === 101);
    assert.equal((await page.evaluate(() => window.dialogawpReactInstance.getFlow().nodes.length)), 100, '100-node graph remains portable');

    await page.evaluate(next => window.dialogawpReactInstance.setFlow(next), replacement);
    await page.waitForFunction(() => document.querySelector('.react-flow__node[data-id="fresh"]'));

    await page.evaluate(() => {
        const dataTransfer = new DataTransfer();
        dataTransfer.setData('dialogawp/node-type', 'question');
        document.querySelector('.dialogawp-rf-canvas').dispatchEvent(new DragEvent('drop', {
            bubbles: true,
            cancelable: true,
            clientX: 500,
            clientY: 400,
            dataTransfer,
        }));
    });
    const dropped = await page.evaluate(() => window.dialogawpBuilder.dropped);
    assert.equal(dropped.type, 'question', 'palette drop delegates to the existing builder');
    assert.equal(dropped.isStart, false, 'palette drop preserves addNodeAtPosition contract');

    const state = await page.evaluate(() => window.dialogawpReactInstance.getState());
    assert.equal(typeof state.zoom, 'number');
    assert.equal(typeof state.panOffset.x, 'number');
    assert.deepEqual(errors, [], 'real bundle executes without browser errors');

    await browser.close();
    console.log('PASS: real XYFlow bundle, portable API, handles, click and palette drop');
}

run().catch(error => {
    console.error(error);
    process.exitCode = 1;
});
