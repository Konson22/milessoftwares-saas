/**
 * The field app runs the same rules engine as the web and the server
 * (ADR-0012 §4): src/collect/rules.ts and definition.ts must stay
 * byte-for-byte copies of mel-app's. CI runs this; to update, copy them over.
 */
const fs = require('fs');
const path = require('path');

const pairs = ['rules.ts', 'definition.ts'].map((file) => [
  path.join(__dirname, '..', 'src', 'collect', file),
  path.join(__dirname, '..', '..', 'mel-app', 'resources', 'js', 'mel', 'collect', file),
]);

let failed = false;
for (const [copy, source] of pairs) {
  if (!fs.existsSync(source)) {
    console.warn(`skip: ${source} not found (checked out alone?)`);
    continue;
  }
  if (fs.readFileSync(copy, 'utf8') !== fs.readFileSync(source, 'utf8')) {
    console.error(`${path.basename(copy)} differs from mel-app's copy. Copy it over.`);
    failed = true;
  }
}
process.exit(failed ? 1 : 0);
