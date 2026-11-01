'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const root = path.resolve(__dirname, '..');
const jsPath = path.join(root, 'assets/js/build/flow-canvas.js');
const cssPath = path.join(root, 'assets/js/build/flow-canvas.css');
const metaPath = path.join(root, 'assets/js/build/flow-canvas.meta.json');
const pluginPath = path.join(root, 'dialogawp.php');

for (const file of [jsPath, cssPath, metaPath]) {
    assert.ok(fs.existsSync(file), `Missing build artifact: ${path.relative(root, file)}`);
}

const js = fs.readFileSync(jsPath, 'utf8');
const css = fs.readFileSync(cssPath, 'utf8');
const meta = JSON.parse(fs.readFileSync(metaPath, 'utf8'));
const plugin = fs.readFileSync(pluginPath, 'utf8');
const inputs = Object.keys(meta.inputs);

assert.match(js, /dialogawpCanvasComponent/, 'bundle exposes the canvas component');
assert.match(js, /dialogawpReactInstance/, 'bundle exposes the compatibility API');
assert.doesNotMatch(js, /require\(["']react(?:-dom)?["']\)/, 'bundle has no unresolved React require');
assert.ok(!inputs.some(file => /node_modules\/react(?:-dom)?\//.test(file)), 'bundle excludes React and ReactDOM implementations');
assert.ok(inputs.some(file => file.includes('@xyflow/react')), 'bundle includes @xyflow/react');
assert.match(css, /\.dialogawp-rf-node/, 'bundle includes scoped DialogaWP canvas styles');
assert.match(css, /\.react-flow/, 'bundle includes React Flow base styles');
assert.match(plugin, /Requires at least:\s*6\.2/, 'plugin requires WordPress with React 18');
assert.match(plugin, /array\('react', 'react-dom'\)/, 'canvas depends on WordPress React handles');
assert.match(plugin, /assets\/js\/build\/flow-canvas\.js/, 'builder enqueues the XYFlow bundle');
assert.doesNotMatch(plugin, /vendor\/react(?:-dom)?\.production/, 'plugin does not enqueue duplicate React builds');

console.log('PASS: XYFlow build artifacts, globals, CSS, React externals and WordPress integration');
