// =============================================================================
// compile-scss.js — Compile chaque assets/scss/ds-*.scss vers public/css/
//                   et met à jour les URLs de polices dans public/assets/ds-icons.css
// -----------------------------------------------------------------------------
// node scripts/compile-scss.js               développement : CSS lisible + source maps
// node scripts/compile-scss.js --mode build  production : CSS minifié, échec = code 1
// node scripts/compile-scss.js --watch       recompile les seuls points d'entrée
//                                            touchés par le fichier modifié
// =============================================================================

const sass = require('sass');
const fs = require('fs');
const path = require('path');
const util = require('util');
const { fileURLToPath } = require('url');

const args = process.argv.slice(2);
const isBuild =
  args.includes('--mode') && args[args.indexOf('--mode') + 1] === 'build';
const isWatch = args.includes('--watch');

const inputDir = path.resolve('assets/scss');
const outDir = path.resolve('public/css');
const iconsFile = path.resolve('public/assets/ds-icons.css');

// Dépendances de chaque point d'entrée (fichiers chargés lors de la dernière
// compilation) : en watch, seuls les points d'entrée concernés sont recompilés
const dependencies = new Map();

const time = () => new Date().toLocaleTimeString();
const kb = (bytes) => `${(bytes / 1024).toFixed(1)} KB`;

// Lecture sans dépendance externe de la variable DEFAULT_URI depuis .env / .env.local
const parseEnvFile = (filePath) => {
  if (!fs.existsSync(filePath)) return {};
  const content = fs.readFileSync(filePath, 'utf8');
  if (typeof util.parseEnv === 'function') {
    return util.parseEnv(content);
  }
  const env = {};
  for (const line of content.split(/\r?\n/)) {
    const trimmed = line.trim();
    if (!trimmed || trimmed.startsWith('#')) continue;
    const match = trimmed.match(
      /^(?:export\s+)?([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*)$/,
    );
    if (!match) continue;
    let [, key, value] = match;
    value = value.trim();
    if (
      (value.startsWith('"') && value.endsWith('"')) ||
      (value.startsWith("'") && value.endsWith("'"))
    ) {
      value = value.slice(1, -1);
    } else {
      value = value.replace(/\s+#.*$/, '');
    }
    env[key] = value;
  }
  return env;
};

const getDefaultUri = () => {
  const dotEnvUri = parseEnvFile(path.resolve('.env')).DEFAULT_URI?.trim();
  const dotEnvLocalUri = parseEnvFile(
    path.resolve('.env.local'),
  ).DEFAULT_URI?.trim();
  const envUri = process.env.DEFAULT_URI?.trim();

  const rawUri =
    envUri ||
    (dotEnvLocalUri && dotEnvLocalUri !== 'http://localhost'
      ? dotEnvLocalUri
      : dotEnvUri || dotEnvLocalUri) ||
    '';
  return rawUri.replace(/\/+$/, '');
};

// Relus à chaque passe : un nouveau ds-*.scss est pris en compte sans redémarrer
const getEntryPoints = () =>
  fs
    .readdirSync(inputDir)
    .filter((file) => /^ds-.+\.scss$/.test(file))
    .map((file) => path.join(inputDir, file));

// N'écrit que si le contenu a changé (évite les rechargements inutiles)
const writeIfChanged = (file, content) => {
  if (fs.existsSync(file) && fs.readFileSync(file, 'utf8') === content) return;
  fs.writeFileSync(file, content);
};

// Préfixe les URLs des polices d'icônes avec DEFAULT_URI pour l'usage via CDN externe
const compileIcons = () => {
  if (!fs.existsSync(iconsFile)) return true;
  const start = performance.now();

  try {
    const defaultUri = getDefaultUri();
    if (!defaultUri) {
      console.warn(
        `[${time()}] ⚠️ DEFAULT_URI non défini dans .env : URLs relatives conservées dans ds-icons.css`,
      );
    }

    const content = fs.readFileSync(iconsFile, 'utf8');
    const updated = content.replace(
      /url\((['"]?)(?:https?:\/\/[^)'"]*?)?(\/assets\/(?:fonts|images)\/[^)'"]+)\1\)/g,
      `url($1${defaultUri}$2$1)`,
    );

    writeIfChanged(iconsFile, updated);

    const duration = Math.round(performance.now() - start);
    console.log(
      `[${time()}] ✅ ds-icons.css (${kb(Buffer.byteLength(updated))}, ${duration} ms)`,
    );
    return true;
  } catch (error) {
    console.error(`[${time()}] ❌ ds-icons.css\n${error.message}\n`);
    return false;
  }
};

const compileEntry = (entry) => {
  const name = path.basename(entry, '.scss');
  const cssFile = path.join(outDir, `${name}.css`);
  const mapFile = `${cssFile}.map`;
  const start = performance.now();

  try {
    const result = sass.compile(entry, {
      style: isBuild ? 'compressed' : 'expanded',
      sourceMap: !isBuild,
      sourceMapIncludeSources: !isBuild,
    });

    dependencies.set(
      entry,
      new Set(
        result.loadedUrls
          .filter((url) => url.protocol === 'file:')
          .map((url) => fileURLToPath(url)),
      ),
    );

    let css = result.css;
    if (result.sourceMap) {
      writeIfChanged(mapFile, JSON.stringify(result.sourceMap));
      css += `\n\n/*# sourceMappingURL=${path.basename(mapFile)} */\n`;
    } else if (fs.existsSync(mapFile)) {
      fs.rmSync(mapFile); // Pas de source map obsolète en production
    }
    writeIfChanged(cssFile, css);

    const duration = Math.round(performance.now() - start);
    console.log(
      `[${time()}] ✅ ${name}.css (${kb(Buffer.byteLength(css))}, ${duration} ms)`,
    );
    return true;
  } catch (error) {
    console.error(`[${time()}] ❌ ${name}.scss\n${error.message}\n`);
    return false;
  }
};

const compileAll = () => {
  const entries = getEntryPoints();
  if (entries.length === 0) {
    console.warn(
      `[${time()}] ⚠️ Aucun fichier ds-*.scss dans ${path.relative('.', inputDir)}`,
    );
  }
  const scssOk = entries.map(compileEntry).every(Boolean);
  const iconsOk = compileIcons();
  return scssOk && iconsOk;
};

fs.mkdirSync(outDir, { recursive: true });
const ok = compileAll();

if (!isWatch) {
  process.exitCode = ok ? 0 : 1;
} else {
  const watched = path.relative('.', inputDir).split(path.sep).join('/');
  console.log(`👀 Watching ${watched} for changes...`);
  const changed = new Set();
  let timeout;

  fs.watch(inputDir, { recursive: true }, (_event, filename) => {
    if (!filename || !filename.endsWith('.scss')) return;
    changed.add(path.join(inputDir, filename));

    // Debounce : regroupe les écritures rapprochées (sauvegarde multiple, git…)
    clearTimeout(timeout);
    timeout = setTimeout(() => {
      const files = [...changed];
      changed.clear();

      // Un partial importé par aucun point d'entrée n'a pas d'effet : il sera
      // pris en compte dès que le fichier qui l'importe sera modifié
      getEntryPoints()
        .filter((entry) => {
          const deps = dependencies.get(entry);
          // Point d'entrée nouveau ou jamais compilé avec succès : on le recompile
          return !deps || files.some((file) => file === entry || deps.has(file));
        })
        .forEach(compileEntry);
    }, 100);
  });
}
