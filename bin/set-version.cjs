#!/usr/bin/env node
// Sets the plugin version in every place it appears.
//
// Usage: npm run set-version -- <version>
//   1.0.0-rc.2   a release candidate (GitHub pre-release only)
//   1.0.0        a final release (GitHub release and WordPress.org)
//
// Updates the plugin header "Version", the NANOPOST_EMAIL_CHECK_VERSION constant and the
// readme "Stable tag". The Release workflow requires all three to equal the tag.
'use strict';

const fs = require('fs');
const path = require('path');

const version = process.argv[2];
if (!/^\d+\.\d+\.\d+(-[0-9A-Za-z.]+)?$/.test(version || '')) {
  console.error('Usage: npm run set-version -- <x.y.z or x.y.z-rc.N>');
  process.exit(1);
}

const root = path.resolve(__dirname, '..');
const edits = [
  ['nanopost-email-check.php', /^( \* Version:\s+)\S+()$/m],
  ['nanopost-email-check.php', /(define\( 'NANOPOST_EMAIL_CHECK_VERSION', ')[^']+(' \);)/],
  ['readme.txt', /^(Stable tag:\s*)\S+()$/m],
];

for (const [file, pattern] of edits) {
  const full = path.join(root, file);
  const text = fs.readFileSync(full, 'utf8');
  if (!pattern.test(text)) {
    console.error(`Not found in ${file}: ${pattern}`);
    process.exit(1);
  }
  // Every pattern has two groups: the text before the version and the text after it.
  fs.writeFileSync(full, text.replace(pattern, (m, before, after) => before + version + after));
}
console.log(`Version set to ${version}. Commit, wait for green CI, then tag ${version}.`);
