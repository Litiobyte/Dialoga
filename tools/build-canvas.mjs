import { build, context } from 'esbuild';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const watch = process.argv.includes('--watch');
const aliases = new Map([
    ['react', path.join(root, 'src/canvas/shims/react.js')],
    ['react-dom', path.join(root, 'src/canvas/shims/react-dom.js')],
    ['react-dom/client', path.join(root, 'src/canvas/shims/react-dom.js')],
    ['react/jsx-runtime', path.join(root, 'src/canvas/shims/jsx-runtime.js')],
    ['react/jsx-dev-runtime', path.join(root, 'src/canvas/shims/jsx-runtime.js')],
]);

const options = {
    entryPoints: [path.join(root, 'src/canvas/index.jsx')],
    outdir: path.join(root, 'assets/js/build'),
    entryNames: 'flow-canvas',
    bundle: true,
    format: 'iife',
    platform: 'browser',
    target: ['es2020'],
    minify: true,
    sourcemap: false,
    legalComments: 'external',
    metafile: true,
    define: {
        'process.env.NODE_ENV': '"production"',
    },
    plugins: [{
        name: 'wordpress-react-globals',
        setup(buildApi) {
            buildApi.onResolve({ filter: /^(react|react-dom)(\/.*)?$/ }, args => {
                const replacement = aliases.get(args.path);
                return replacement ? { path: replacement } : null;
            });
        },
    }],
};

if (watch) {
    const buildContext = await context(options);
    await buildContext.watch();
    console.log('Watching DialogaWP canvas sources...');
} else {
    const result = await build(options);
    await writeMetafile(result.metafile);
    console.log('Built assets/js/build/flow-canvas.js and flow-canvas.css');
}

async function writeMetafile(metafile) {
    const { writeFile } = await import('node:fs/promises');
    await writeFile(
        path.join(root, 'assets/js/build/flow-canvas.meta.json'),
        JSON.stringify(metafile, null, 2) + '\n'
    );
}
