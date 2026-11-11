// Version-bound dev dependency compatibility; never changes product or installed site files.
'use strict';
const fs = require('node:fs');
const path = require('node:path');
const manifest = require.resolve('@wordpress/env/package.json');
const directory = path.dirname(manifest);
const project = fs.realpathSync(path.join(__dirname, '..'));
const expectedDirectory = path.join(project, 'node_modules', '@wordpress', 'env');
if (directory !== expectedDirectory || fs.realpathSync(directory) !== expectedDirectory) {
  throw new Error('wp-env must be installed in this project, without dependency directory symlinks.');
}
if (JSON.parse(fs.readFileSync(manifest, 'utf8')).version !== '12.0.0') {
  throw new Error('Review wp-env compatibility for the newly installed version.');
}
// wp-env 12 already imports simple-git through its named export, so this
// check proves the reviewed shape instead of rewriting the dependency.
const from = "const SimpleGit = require( 'simple-git' );";
const to = "const { simpleGit } = require( 'simple-git' );";
const files = [
  'lib/download-sources.js',
  'lib/runtime/docker/download-wp-phpunit.js',
];
for (const relative of files) {
  const target = path.join(directory, relative);
  if (fs.lstatSync(target).isSymbolicLink() || fs.realpathSync(target) !== target) throw new Error('Unexpected wp-env dependency symlink.');
  const content = fs.readFileSync(target, 'utf8');
  if (!content.includes(to) || content.includes(from)) {
    throw new Error('Unexpected wp-env dependency import shape; review the compatibility boundary.');
  }
}
process.stdout.write('Pinned wp-env compatibility verified.\n');
