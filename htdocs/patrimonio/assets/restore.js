(function exposeCategoryNormalizer(global) {
    const aliases = new Map([
        ['energia fotovoltaico', 'Sistema Fotovoltaico'],
        ['sistema fotovotaico', 'Sistema Fotovoltaico'],
    ]);

    function canonicalCategory(value) {
        if (typeof value !== 'string') return value;
        const key = value.trim().replace(/\s+/g, ' ').toLocaleLowerCase('pt-BR');
        return aliases.get(key) || value;
    }

    function normalizeBackupCategories(assets, invoices, categories) {
        let updatedRecords = 0;
        for (const asset of assets) {
            if (!asset || typeof asset !== 'object') continue;
            const next = canonicalCategory(asset.category);
            if (next !== asset.category) {
                asset.category = next;
                updatedRecords++;
            }
        }
        for (const invoice of invoices) {
            if (!invoice || !Array.isArray(invoice.items)) continue;
            for (const item of invoice.items) {
                if (!item || typeof item !== 'object') continue;
                const next = canonicalCategory(item.category);
                if (next !== item.category) {
                    item.category = next;
                    updatedRecords++;
                }
            }
        }
        const normalizedCategories = [];
        const seen = new Set();
        for (const value of categories) {
            const next = canonicalCategory(value);
            const key = String(next ?? '');
            if (seen.has(key)) continue;
            seen.add(key);
            normalizedCategories.push(next);
        }
        return {
            assets,
            invoices,
            categories: normalizedCategories,
            updatedRecords,
            mergedCatalogEntries: categories.length - normalizedCategories.length,
        };
    }

    function analyzePatrimony(assets) {
        const firstAssetByToken = new Map();
        const duplicatedAssets = new Set();
        let repeatedOccurrences = 0;
        let assetsWithoutPatrimony = 0;
        let assetsWithMultipleTokens = 0;
        let tokenCount = 0;
        for (let index = 0; index < assets.length; index++) {
            const value = assets[index] && typeof assets[index].patrimony === 'string' ? assets[index].patrimony : String(assets[index]?.patrimony ?? '');
            const tokens = value.split(/[\/,\n\r]+/).map((token) => token.trim()).filter(Boolean);
            if (!tokens.length) assetsWithoutPatrimony++;
            if (tokens.length > 1) assetsWithMultipleTokens++;
            const localTokens = new Set();
            for (const token of tokens) {
                tokenCount++;
                if (localTokens.has(token)) {
                    repeatedOccurrences++;
                    duplicatedAssets.add(index);
                } else if (firstAssetByToken.has(token)) {
                    repeatedOccurrences++;
                    duplicatedAssets.add(index);
                    duplicatedAssets.add(firstAssetByToken.get(token));
                } else {
                    firstAssetByToken.set(token, index);
                }
                localTokens.add(token);
            }
        }
        return {
            tokenCount,
            uniqueTokenCount: firstAssetByToken.size,
            assetsWithoutPatrimony,
            assetsWithMultipleTokens,
            repeatedOccurrences,
            assetsWithRepeatedTokens: duplicatedAssets.size,
        };
    }

    global.AtivaBackupNormalization = Object.freeze({ canonicalCategory, normalizeBackupCategories, analyzePatrimony });
})(window);

document.addEventListener('DOMContentLoaded', () => {
    const fileInput = document.getElementById('restore-file');
    const startButton = document.getElementById('restore-start');
    const preview = document.getElementById('restore-preview');
    const errorBox = document.getElementById('restore-error');
    const doneBox = document.getElementById('restore-done');
    const progressBox = document.getElementById('restore-progress');
    const progressText = document.getElementById('restore-status');
    const progressBar = document.getElementById('restore-bar');
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    let parsed = null;
    let phase = 'check';

    const showError = (message) => {
        errorBox.textContent = message;
        errorBox.hidden = false;
        doneBox.hidden = true;
    };
    const setProgress = (percent, message) => {
        progressBox.hidden = false;
        progressText.textContent = message;
        progressBar.style.width = Math.max(0, Math.min(100, percent)) + '%';
    };
    const findFile = (zip, name) => zip.file(name) || Object.values(zip.files).find((entry) => !entry.dir && entry.name.toLowerCase() === name.toLowerCase()) || null;
    async function api(action, payload = {}) {
        const response = await fetch('index.php?api=' + encodeURIComponent(action), {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
            body: JSON.stringify(payload),
        });
        const result = await response.json().catch(() => ({ error: 'Resposta inesperada do servidor.' }));
        if (!response.ok || result.error) throw new Error(result.error || 'Não foi possível completar a restauração.');
        return result;
    }
    async function jsonEntry(zip, path) {
        const entry = findFile(zip, path);
        if (!entry) throw new Error('O arquivo ' + path + ' não existe no ZIP.');
        return JSON.parse(await entry.async('string'));
    }
    async function optionalJsonEntry(zip, path, fallback) {
        const entry = findFile(zip, path);
        return entry ? JSON.parse(await entry.async('string')) : fallback;
    }
    function splitPayloads(kind, rows, token, maxRows) {
        const chunks = [];
        let current = [];
        for (const row of rows) {
            const candidate = [...current, row];
            const body = JSON.stringify({ token, kind, chunkNo: chunks.length, records: candidate });
            const bytes = new TextEncoder().encode(body).length;
            if (current.length && (candidate.length > maxRows || bytes > 1200000)) {
                chunks.push(current);
                current = [row];
            } else {
                current = candidate;
            }
            const singleBytes = new TextEncoder().encode(JSON.stringify({ token, kind, chunkNo: chunks.length, records: current })).length;
            if (singleBytes > 1200000) throw new Error('Um registro individual excedeu o limite seguro de envio. Reduza o campo de observações ou faça uma restauração em lotes menores.');
        }
        if (current.length) chunks.push(current);
        return chunks;
    }

    fileInput?.addEventListener('change', () => {
        parsed = null;
        phase = 'check';
        startButton.disabled = !fileInput.files?.length;
        startButton.textContent = 'Conferir arquivo';
        preview.hidden = true;
        errorBox.hidden = true;
        doneBox.hidden = true;
        progressBox.hidden = true;
    });

    startButton?.addEventListener('click', async () => {
        errorBox.hidden = true;
        doneBox.hidden = true;
        if (phase === 'restore' && parsed) {
            const duplicateNotice = parsed.patrimony.repeatedOccurrences
                ? ' Foram identificadas ' + parsed.patrimony.repeatedOccurrences.toLocaleString('pt-BR') + ' ocorrências de código repetido, envolvendo ' + parsed.patrimony.assetsWithRepeatedTokens.toLocaleString('pt-BR') + ' ativos; esses dados serão preservados.'
                : '';
            if (!window.confirm('Confirma restaurar ' + parsed.counts.assets.toLocaleString('pt-BR') + ' ativos, ' + parsed.counts.invoices.toLocaleString('pt-BR') + ' NF-es e ' + parsed.counts.assetDeletionAudits.toLocaleString('pt-BR') + ' registros de exclusão?' + duplicateNotice + ' A ação só funciona em uma base vazia.')) return;
            startButton.disabled = true;
            startButton.textContent = 'Restaurando…';
            try {
                const started = await api('restore-start', { counts: parsed.counts, source: parsed.source });
                const maxRows = Math.min(100, Number(started.chunkSize) || 100);
                const groups = [['assets', parsed.assets], ['invoices', parsed.invoices], ['catalogs', parsed.catalogRows], ['deletions', parsed.deletionAudits]];
                const chunks = [];
                for (const [kind, rows] of groups) {
                    for (const records of splitPayloads(kind, rows, started.token, maxRows)) chunks.push({ kind, records });
                }
                const totalChunks = chunks.length || 1;
                let done = 0;
                for (let chunkNo = 0; chunkNo < chunks.length; chunkNo++) {
                    const chunk = chunks[chunkNo];
                    await api('restore-chunk', { token: started.token, kind: chunk.kind, chunkNo: Number(chunkNo), records: chunk.records });
                    done++;
                    setProgress(20 + Math.round(done / totalChunks * 70), 'Enviando ' + chunk.kind + ' · lote ' + done.toLocaleString('pt-BR') + ' de ' + totalChunks.toLocaleString('pt-BR') + '…');
                }
                setProgress(94, 'Validando e aplicando os dados em transação…');
                const result = await api('restore-finish', { token: started.token });
                setProgress(100, 'Restauração concluída.');
                doneBox.textContent = result.assets.toLocaleString('pt-BR') + ' ativos, ' + result.invoices.toLocaleString('pt-BR') + ' NF-es, ' + result.invoiceItems.toLocaleString('pt-BR') + ' itens e ' + Number(result.assetDeletionAudits || 0).toLocaleString('pt-BR') + ' registros de exclusão restaurados. Entradas redundantes de contas patrimoniais consolidadas: ' + Number(result.mergedCatalogEntries || 0).toLocaleString('pt-BR') + '.';
                doneBox.hidden = false;
                startButton.hidden = true;
                const link = document.createElement('a');
                link.className = 'button primary';
                link.href = 'index.php';
                link.textContent = 'Abrir painel';
                doneBox.after(link);
            } catch (error) {
                showError(error instanceof Error ? error.message : 'Falha ao restaurar o backup.');
                startButton.disabled = false;
                startButton.textContent = 'Tentar novamente';
            }
            return;
        }

        startButton.disabled = true;
        try {
            if (!window.JSZip) throw new Error('Não foi possível carregar a biblioteca ZIP local.');
            const file = fileInput.files?.[0];
            if (!file) throw new Error('Escolha um arquivo ZIP.');
            if (file.size > 20 * 1024 * 1024) throw new Error('O ZIP ultrapassa 20 MB.');
            setProgress(2, 'Lendo o ZIP no navegador…');
            const zip = await JSZip.loadAsync(file, { checkCRC32: true });
            const entries = Object.values(zip.files);
            if (entries.length > 120) throw new Error('O ZIP contém mais de 120 entradas.');
            let expanded = 0;
            for (const entry of entries) {
                const size = entry?._data?.uncompressedSize || 0;
                expanded += size;
                if (size > 20 * 1024 * 1024 || expanded > 35 * 1024 * 1024) throw new Error('O conteúdo descompactado excede o limite de segurança.');
            }
            const manifest = await jsonEntry(zip, 'manifesto.json');
            if (!manifest || typeof manifest !== 'object' || !manifest.counts || !Array.isArray(manifest.xmlEntries)) throw new Error('O manifesto não tem o formato de backup esperado.');
            const assets = await jsonEntry(zip, 'dados/ativos.json');
            const invoices = await jsonEntry(zip, 'dados/notas-fiscais.json');
            const catalogExport = await jsonEntry(zip, 'dados/padroes-de-cadastro.json');
            const deletionAudits = await optionalJsonEntry(zip, 'dados/historico-exclusoes-ativos.json', []);
            let categories = Array.isArray(catalogExport.categories) ? catalogExport.categories : catalogExport.category;
            const locations = Array.isArray(catalogExport.locations) ? catalogExport.locations : catalogExport.location;
            if (!Array.isArray(assets) || !Array.isArray(invoices) || !Array.isArray(categories) || !Array.isArray(locations) || !Array.isArray(deletionAudits)) throw new Error('Os dados do ZIP não correspondem ao formato esperado.');

            for (const link of manifest.xmlEntries) {
                if (!link || link.invoiceId === undefined || !link.path) continue;
                const invoice = invoices.find((item) => String(item.id) === String(link.invoiceId));
                if (invoice && !invoice.xml) {
                    const xmlFile = findFile(zip, link.path);
                    if (xmlFile) invoice.xml = await xmlFile.async('string');
                }
            }

            const itemCount = invoices.reduce((sum, note) => sum + (Array.isArray(note.items) ? note.items.length : 0), 0);
            const expected = manifest.counts;
            if (Number(expected.assets) !== assets.length || Number(expected.invoices) !== invoices.length) throw new Error('As contagens dos dados não conferem com o manifesto.');
            if (expected.invoiceItems !== undefined && Number(expected.invoiceItems) !== itemCount) throw new Error('A quantidade de itens fiscais não confere com o manifesto.');
            if (Number(expected.categories) !== categories.length || Number(expected.locations) !== locations.length) throw new Error('As contas patrimoniais e os locais não conferem com o manifesto.');
            if (Number(expected.assetDeletionAudits ?? 0) !== deletionAudits.length) throw new Error('A quantidade do histórico de exclusões não confere com o manifesto.');

            const normalization = window.AtivaBackupNormalization.normalizeBackupCategories(assets, invoices, categories);
            const normalizedAssets = normalization.assets;
            const normalizedInvoices = normalization.invoices;
            categories = normalization.categories;
            const patrimony = window.AtivaBackupNormalization.analyzePatrimony(normalizedAssets);
            const status = await api('restore-check');
            if (!status.status?.empty) throw new Error('A base não está vazia. A restauração foi bloqueada para proteger os dados atuais.');

            parsed = {
                assets: normalizedAssets,
                invoices: normalizedInvoices,
                deletionAudits,
                catalogRows: [...categories.map((value) => ({ kind: 'category', value })), ...locations.map((value) => ({ kind: 'location', value }))],
                counts: { assets: normalizedAssets.length, invoices: normalizedInvoices.length, invoiceItems: itemCount, categories: categories.length, locations: locations.length, assetDeletionAudits: deletionAudits.length },
                source: file.name,
                normalization,
                patrimony,
            };
            let normalizationNote = '';
            if (normalization.updatedRecords || normalization.mergedCatalogEntries) {
                normalizationNote = '<br><strong>Padronização aprovada:</strong> ' + normalization.updatedRecords.toLocaleString('pt-BR') + ' registros terão a conta patrimonial “Sistema Fotovoltaico”; ' + normalization.mergedCatalogEntries.toLocaleString('pt-BR') + ' entrada(s) redundante(s) do catálogo serão unificadas. A planilha e o ZIP de origem não são alterados.';
            }
            let patrimonyNote = '';
            if (patrimony.repeatedOccurrences) {
                patrimonyNote += '<br><strong>Patrimônio repetido:</strong> ' + patrimony.repeatedOccurrences.toLocaleString('pt-BR') + ' ocorrências em ' + patrimony.assetsWithRepeatedTokens.toLocaleString('pt-BR') + ' ativos. Os registros serão preservados sem alterar os números históricos; cada código distinto terá uma única reserva para bloquear novas duplicações.';
            }
            if (patrimony.assetsWithoutPatrimony) {
                patrimonyNote += '<br><strong>Sem patrimônio:</strong> ' + patrimony.assetsWithoutPatrimony.toLocaleString('pt-BR') + ' ativos continuarão sem código patrimonial.';
            }
            preview.innerHTML = '<strong>Arquivo conferido.</strong><br>' + parsed.counts.assets.toLocaleString('pt-BR') + ' ativos · ' + parsed.counts.invoices.toLocaleString('pt-BR') + ' NF-es · ' + parsed.counts.invoiceItems.toLocaleString('pt-BR') + ' itens fiscais · ' + parsed.counts.categories.toLocaleString('pt-BR') + ' contas patrimoniais · ' + parsed.counts.locations.toLocaleString('pt-BR') + ' locais · ' + parsed.counts.assetDeletionAudits.toLocaleString('pt-BR') + ' registros de exclusão.' + normalizationNote + patrimonyNote + '<br>Os dados permanecem no seu navegador até a confirmação.';
            preview.hidden = false;
            phase = 'restore';
            startButton.textContent = 'Confirmar e restaurar';
            startButton.disabled = false;
            setProgress(100, 'Backup conferido; aguardando sua confirmação.');
        } catch (error) {
            showError(error instanceof Error ? error.message : 'Não foi possível ler o backup.');
            startButton.disabled = false;
            startButton.textContent = 'Tentar novamente';
            progressBox.hidden = true;
        }
    });
});
