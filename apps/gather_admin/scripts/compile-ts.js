// =============================================================================
// compile-ts.js — Bundle assets/ts vers public/js/ avec esbuild
// -----------------------------------------------------------------------------
// node scripts/compile-ts.js               développement : source maps
// node scripts/compile-ts.js --mode build  production : minifié, échec = code 1
// node scripts/compile-ts.js --watch       rebuild incrémental à chaque modification
//
// Points d'entrée : main.ts (chargé partout) et pages/*.ts (un script par page).
// Les autres modules (components/, interactions/) sont importés par ceux-ci.
// Le code partagé (core-ts…) est extrait dans public/js/chunks/ : il n'est
// téléchargé et exécuté qu'une fois, même si une page charge plusieurs scripts.
// =============================================================================

const esbuild = require('esbuild');
const fs = require('fs');
const path = require('path');

const args = process.argv.slice(2);
const isBuild =
  args.includes('--mode') && args[args.indexOf('--mode') + 1] === 'build';
const isWatch = args.includes('--watch');

const srcDir = 'assets/ts';
const outDir = 'public/js';

const time = () => new Date().toLocaleTimeString();
const kb = (bytes) => `${(bytes / 1024).toFixed(1)} KB`;

// Supprime les fichiers de public/js absents du dernier build (anciens chunks
// hachés, source maps d'un build de dev, bundles d'anciens points d'entrée)
const cleanStaleOutputs = (metafile) => {
  const outputs = new Set(
    Object.keys(metafile.outputs).map((file) => path.resolve(file)),
  );
  // Du plus profond au moins profond : un dossier vidé peut être supprimé
  const entries = fs
    .readdirSync(outDir, { recursive: true })
    .map((entry) => path.resolve(outDir, entry))
    .sort((a, b) => b.length - a.length);

  for (const entry of entries) {
    if (fs.statSync(entry).isDirectory()) {
      if (fs.readdirSync(entry).length === 0) fs.rmdirSync(entry);
    } else if (!outputs.has(entry)) {
      fs.rmSync(entry);
    }
  }
};

// Journalise chaque build (y compris les rebuilds du mode watch)
const reporter = {
  name: 'reporter',
  setup(build) {
    let start;
    build.onStart(() => {
      start = performance.now();
    });
    build.onEnd((result) => {
      const duration = Math.round(performance.now() - start);
      if (result.errors.length > 0) {
        console.error(
          `[${time()}] ❌ TS : ${result.errors.length} erreur(s) (${duration} ms)`,
        );
        return;
      }
      cleanStaleOutputs(result.metafile);
      const bundles = Object.entries(result.metafile.outputs)
        .filter(([file, output]) => output.entryPoint && file.endsWith('.js'))
        .map(([file, output]) => {
          const name = path.relative(outDir, file).split(path.sep).join('/');
          return `${name} ${kb(output.bytes)}`;
        });
      console.log(`[${time()}] ✅ TS (${duration} ms) : ${bundles.join(', ')}`);
    });
  },
};

const buildOptions = {
  entryPoints: [`${srcDir}/main.ts`, `${srcDir}/pages/*.ts`],
  outbase: srcDir,
  outdir: outDir,
  bundle: true,
  splitting: true,
  chunkNames: 'chunks/[name]-[hash]',
  format: 'esm',
  platform: 'browser',
  target: 'es2020',
  tsconfig: 'tsconfig.json',
  sourcemap: !isBuild,
  minify: isBuild,
  legalComments: isBuild ? 'none' : 'eof',
  drop: isBuild ? ['debugger'] : [],
  metafile: true,
  logLevel: 'warning', // esbuild affiche lui-même les erreurs et avertissements
  plugins: [reporter],
};

const run = async () => {
  fs.mkdirSync(outDir, { recursive: true });

  if (!isWatch) {
    try {
      await esbuild.build(buildOptions);
    } catch {
      process.exitCode = 1; // Erreurs déjà affichées par esbuild
    }
    return;
  }

  const ctx = await esbuild.context(buildOptions);
  await ctx.watch();
  console.log(`👀 Watching ${srcDir} for changes...`);

  const stop = async () => {
    await ctx.dispose();
    process.exit(0);
  };
  process.on('SIGINT', stop);
  process.on('SIGTERM', stop);
};

run();
