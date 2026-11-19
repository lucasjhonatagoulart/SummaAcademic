import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

const listeners = {};
const window = {};
const document = { addEventListener: (name, callback) => { listeners[name] = callback; } };
const restoreSource = readFileSync(new URL('../assets/restore.js', import.meta.url), 'utf8');
vm.runInNewContext(restoreSource, { window, document });
const normalizer = window.AtivaBackupNormalization;

assert.equal(normalizer.canonicalCategory('Energia Fotovoltaico'), 'Sistema Fotovoltaico');
assert.equal(normalizer.canonicalCategory('Sistema Fotovotaico'), 'Sistema Fotovoltaico');
assert.equal(normalizer.canonicalCategory('  sistema   fotovotaico  '), 'Sistema Fotovoltaico');
assert.equal(normalizer.canonicalCategory('Móveis e Utensílios'), 'Móveis e Utensílios');

const assets = [
    { category: 'Energia Fotovoltaico' },
    { category: 'Sistema Fotovotaico' },
    { category: 'Móveis e Utensílios' },
];
const invoices = [{ items: [{ category: 'Sistema Fotovotaico' }] }];
const result = normalizer.normalizeBackupCategories(assets, invoices, [
    'Energia Fotovoltaico', 'Sistema Fotovotaico', 'Software',
]);
assert.deepEqual(result.assets.map((asset) => asset.category), ['Sistema Fotovoltaico', 'Sistema Fotovoltaico', 'Móveis e Utensílios']);
assert.equal(result.invoices[0].items[0].category, 'Sistema Fotovoltaico');
assert.equal(Array.from(result.categories).join('|'), 'Sistema Fotovoltaico|Software');
assert.equal(result.updatedRecords, 3);
assert.equal(result.mergedCatalogEntries, 1);

assert.match(restoreSource, /optionalJsonEntry\(zip, 'dados\/historico-exclusoes-ativos\.json', \[\]\)/);
assert.match(restoreSource, /expected\.assetDeletionAudits \?\? 0/);

const patrimonyStats = normalizer.analyzePatrimony([
    { patrimony: 'COD-A/COD-B' },
    { patrimony: 'COD-A, COD-C' },
    { patrimony: 'COD-C' },
    { patrimony: '' },
    { patrimony: 'CÓDIGO COM ESPAÇO/COD-D/COD-D' },
]);
assert.equal(JSON.stringify(patrimonyStats), JSON.stringify({
    tokenCount: 8,
    uniqueTokenCount: 5,
    assetsWithoutPatrimony: 1,
    assetsWithMultipleTokens: 3,
    repeatedOccurrences: 3,
    assetsWithRepeatedTokens: 4,
}));
console.log('Normalização, patrimônio e auditoria de restauração conferidos (12 asserções).');
