import { createHash } from 'node:crypto';
import { execFileSync } from 'node:child_process';
import { readFile, readdir, lstat, mkdir, realpath } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import { resolve, join, relative } from 'node:path';

const root = fileURLToPath(new URL('../', import.meta.url));
const plugin = join(root, 'limu-crm');
if (process.argv.length !== 4 || process.argv[2] !== '--output') {
  throw new Error('Usage: node scripts/package.mjs --output /path/to/artifacts');
}
const output = resolve(process.argv[3]);
if (output === plugin || output.startsWith(plugin + '/')) {
  throw new Error('Package output must be outside the plugin.');
}
const version = JSON.parse(await readFile(join(root, 'package.json'), 'utf8')).version;
const header = await readFile(join(plugin, 'limu-crm.php'), 'utf8');
const readme = await readFile(join(plugin, 'readme.txt'), 'utf8');
if (!/^\d+\.\d+\.\d+$/.test(version) || !header.includes(`Version: ${version}\n`) ||
    !header.includes(`define( 'LIMU_CRM_VERSION', '${version}' );`) || !readme.includes(`Stable tag: ${version}\n`)) {
  throw new Error('Package, plugin, constant, and stable tag versions must agree.');
}

// Only runtime files enter the installer. Documentation and source stay in Git.
const files = ['limu-crm.php', 'readme.txt'];
for (const directory of ['includes', 'templates']) {
  for (const file of await readdir(join(plugin, directory))) {
    if (/^[a-z][a-z-]*\.php$/.test(file)) files.push(`${directory}/${file}`);
  }
}
const manifest = await readFile(join(plugin, 'includes/assets.php'), 'utf8');
const paths = [...manifest.matchAll(/'((?:crm\.(?:css|js)|privacy\.js))'\s*=>\s*'(build\/([a-z]+)\.([a-f0-9]{12})\.min\.(js|css))'/g)];
if (paths.length !== 3 || new Set(paths.map(match => match[1])).size !== 3) {
  throw new Error('Build the three fingerprinted runtime assets before packaging.');
}
for (const match of paths) {
  const bytes = await readFile(join(plugin, 'assets', match[2]));
  const digest = createHash('sha256').update(bytes).digest('hex');
  if (!digest.startsWith(match[4])) throw new Error('Asset fingerprint mismatch.');
  files.push(`assets/${match[2]}`);
}
for (const directory of ['brand', 'fonts']) {
  for (const file of await readdir(join(plugin, 'assets', directory))) {
    if (!/^[a-z0-9-]+\.[a-f0-9]{12}\.(?:png|woff2)$/.test(file)) {
      throw new Error('Unexpected brand/font asset.');
    }
    files.push(`assets/${directory}/${file}`);
  }
}
for (const file of files) {
  const absolute = join(plugin, file);
  if (!(await lstat(absolute)).isFile() || relative(plugin, await realpath(absolute)) !== file) {
    throw new Error('Only regular, local plugin files may enter the installer.');
  }
}
await mkdir(output, { recursive: true });
const filename = `limu-crm-${version}.zip`;
const result = execFileSync('python3', ['-c', `
import hashlib, json, pathlib, sys, zipfile
spec = json.load(sys.stdin)
source = pathlib.Path(spec['source'])
target = pathlib.Path(spec['output']) / spec['filename']
files = sorted(spec['files'])
with zipfile.ZipFile(target, 'w', compression=zipfile.ZIP_DEFLATED, compresslevel=9) as archive:
    for name in files:
        entry = zipfile.ZipInfo('limu-crm/' + name, date_time=(1980, 1, 1, 0, 0, 0))
        entry.compress_type = zipfile.ZIP_DEFLATED
        entry.external_attr = 0o100644 << 16
        archive.writestr(entry, (source / name).read_bytes(), compresslevel=9)
with zipfile.ZipFile(target) as archive:
    if archive.testzip() is not None or archive.namelist() != ['limu-crm/' + name for name in files]:
        raise RuntimeError('Installer verification failed.')
    for name in files:
        if archive.read('limu-crm/' + name) != (source / name).read_bytes():
            raise RuntimeError('Installer bytes differ from the validated runtime.')
digest = hashlib.sha256(target.read_bytes()).hexdigest()
(target.parent / 'SHA256SUMS').write_text(digest + '  ' + target.name + '\\n')
print(json.dumps({'file': str(target), 'files': len(files), 'bytes': target.stat().st_size, 'sha256': digest}))
`], { input: JSON.stringify({ source: plugin, output, filename, files }), encoding: 'utf8' });
process.stdout.write(result);
