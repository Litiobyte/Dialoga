# Flow Transfer Browser Tests

Run `node tests/flow-transfer-browser-test.cjs` from the repository. No project
dependencies, web server, or WordPress installation are required. Requires PHP,
Playwright (or playwright-core), jQuery 3.x, and Chromium/Chrome. Missing tooling
prints `SKIP`; set `DIALOGAWP_BROWSER_REQUIRE=1` to fail instead (recommended for CI).

The runner resolves dependencies from the project, `DIALOGAWP_BROWSER_TOOLING`, or
the approved temporary tooling directory used during development. Example:

```sh
npm install --prefix /var/folders/2w/_41g_m7s1b3ds8bs1w06m15r0000gp/T/opencode/flow-transfer-browser-tooling --no-audit --no-fund --ignore-scripts playwright@1.56.1 jquery@3.7.1
DIALOGAWP_BROWSER_REQUIRE=1 node tests/flow-transfer-browser-test.cjs
```

Set `DIALOGAWP_BROWSER_EXECUTABLE` for a custom Chromium executable or `PHP_BINARY`
for a custom PHP executable. The default uses installed macOS Chrome when
available, otherwise Playwright's Chromium. For external dependencies elsewhere,
set `DIALOGAWP_BROWSER_TOOLING` to their parent directory containing `node_modules`.

## Fixture Boundaries

- Actual PHP builder markup and localized templates are rendered by the bridge.
- Actual `admin.css`, `screen-builder.css`, and `flow-transfer.js` run in Chrome.
- The actual admin builder object is evaluated unchanged, excluding only its
  document-ready initialization. `saveNodeEdits`, `loadFlows`, and
  `renderFlowList` remain production implementations. Autosave scheduling,
  modal notifications, and editor-switch actions are instrumented doubles.
- Real jQuery is loaded locally; no CDN or network dependency during test runs.
- AJAX is intercepted and sent through actual PHP transfer endpoints using the
  existing standalone suite's WordPress/database doubles. The PHP bridge first
  runs that suite silently to initialize and verify the shared doubles.
- React's graph API is a double matching production's full replacement
  `setFlow` semantics and asynchronous animation-frame update. Its fresh graph
  deliberately differs from stale builder state in positions, nodes, and edges.
- Downloads are actual browser downloads parsed from Playwright's temporary
  download path. They are removed with the browser context.
- WordPress admin chrome is representative fixture markup, not full WP core
  styles. Tests cover desktop and supported narrow laptop widths; the existing
  <=1024px mobile builder restriction is not changed or treated as a regression.

The runner keeps failures visible and continues through all cases; it does not
mark known production bugs as expected failures. PHP tests separately establish
server-side inactive-copy behavior and validation; this is browser integration
against a backend double, not a deployed WordPress end-to-end test.

## Recorded Results

2026-10-31, installed Chrome/Chromium 156.0.8078.80, Playwright 1.56.1,
 jQuery 3.7.1, PHP 8.5.7: **17 passed, 0 failed** across 17 cases after fixes.
`node --check` passed for the runner and transfer script; PHP bridge lint passed;
the standalone PHP suite passed all 233 assertions.

The first run detected three defects, now fixed and covered by passing cases:

1. `assets/js/flow-transfer.js:123` calls production
   `assets/js/admin.js:2806-2814` silent save, which replaces the graph using
   stale `builder.nodes`, `builder.edges`, and the selected node's stale
   position. Exporting with an open selected-node form reverted `(412,520)` to
   `(12,20)`, removed newly added `n2`, and removed `fresh-edge` in both the
   downloaded package and live graph. Preserve the current graph and merge only
   selected-node data; never replace its position or sibling node/edge state
   from stale builder snapshots.
2. `assets/js/admin.js:3304` calls `this.escapeHtml`, but the builder object has
   no such method. The explicit rendering case throws, and the post-import
   `loadFlows()` refresh (`assets/js/flow-transfer.js:225`) throws the same
   exception. Import itself creates inactive copies without switching editors,
   but the new copies never appear in the sidebar. Provide the missing escaping
   implementation or use an equivalent safe DOM text-rendering strategy.
3. `assets/css/admin.css:93` applies `min-height:400px !important` to the canvas;
   expanded mode's `assets/css/screen-builder.css:148` non-important
   `min-height:0` cannot override it. After resizing a supported laptop viewport
   to 1060x560, the canvas begins at y=196 and ends at y=596, overflowing the
   viewport by 36px inside an overflow-hidden main container. Ensure expanded
   mode actually overrides the existing important minimum.

Passing cases cover individual/latest graph export without a selected form,
selected batch export/current override inclusion, select-all and selection
limits, invalid files/packages, failed import recovery, busy state/duplicate
prevention/retry, malformed and network export responses, export scrub warnings,
desktop fullscreen/resize, dialog focus trapping and layered Escape handling,
focus/scroll restoration, auto-exit on builder hide, and the exit button.
