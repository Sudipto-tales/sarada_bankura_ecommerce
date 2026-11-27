// Set the app version everywhere it lives: node tools/set-version.mjs 1.2.0
//   android/app/version.properties  versionName + versionCode (major*10000 + minor*100 + patch)
//   capacitor.config.json           the MaaSaradaApp/<versionName> user-agent token (Store\AppMode)
// The code always grows with the version, so Play and Android accept every new release.
import { readFileSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const name = (process.argv[2] || '').replace(/^app-v/, '');
const m = /^(\d{1,2})\.(\d{1,2})\.(\d{1,2})$/.exec(name);
if (!m) {
  console.error('Usage: node tools/set-version.mjs <major.minor.patch>, each part 0–99 (e.g. 1.2.0)');
  process.exit(1);
}
const code = Number(m[1]) * 10000 + Number(m[2]) * 100 + Number(m[3]);
if (code < 1) {
  console.error('Version 0.0.0 is not allowed');
  process.exit(1);
}
const root = join(dirname(fileURLToPath(import.meta.url)), '..');

writeFileSync(join(root, 'android/app/version.properties'), `versionName=${name}\nversionCode=${code}\n`);

const cfgPath = join(root, 'capacitor.config.json');
const cfg = JSON.parse(readFileSync(cfgPath, 'utf8'));
cfg.android = { ...cfg.android, appendUserAgent: `MaaSaradaApp/${name}` };
writeFileSync(cfgPath, JSON.stringify(cfg, null, 2) + '\n');

console.log(`versionName ${name}, versionCode ${code}`);
