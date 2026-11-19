const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const read = (file) => fs.readFileSync(new URL(file, `file://${__filename}`), 'utf8');
const source = read('../assets/theme.js');

function createButton(dataset, withChecked = false) {
  const attrs = withChecked ? { 'aria-checked': 'false' } : {};
  const listeners = {};
  return {
    attrs,
    dataset,
    hasAttribute: (name) => name in attrs,
    setAttribute: (name, value) => { attrs[name] = value; },
    addEventListener: (name, listener) => { listeners[name] = listener; },
    click: () => listeners.click(),
  };
}

function boot({ theme = null, size = null, prefersDark = false, storageBlocked = false } = {}) {
  const store = { 'ativa-color-theme': theme, 'ativa-text-size': size };
  const themeButtons = { light: createButton({ themeSet: 'light' }, true), dark: createButton({ themeSet: 'dark' }, true), auto: createButton({ themeSet: 'auto' }, true) };
  const sizeButtons = { sm: createButton({ sizeSet: 'sm' }), md: createButton({ sizeSet: 'md' }), lg: createButton({ sizeSet: 'lg' }) };
  const handlers = {};
  const media = { matches: prefersDark, listeners: [], addEventListener: (_name, listener) => media.listeners.push(listener) };
  const document = {
    documentElement: { dataset: {} },
    readyState: 'loading',
    addEventListener: (name, callback) => { handlers[name] = callback; },
    querySelectorAll: (selector) => selector === '[data-theme-set]' ? Object.values(themeButtons) : selector === '[data-size-set]' ? Object.values(sizeButtons) : [],
  };
  const localStorage = {
    getItem: (key) => { if (storageBlocked) throw new Error('storage disabled'); return store[key] ?? null; },
    setItem: (key, value) => { if (storageBlocked) throw new Error('storage disabled'); store[key] = value; },
    removeItem: (key) => { if (storageBlocked) throw new Error('storage disabled'); store[key] = null; },
  };
  const window = { localStorage, matchMedia: () => media };
  vm.runInNewContext(source, { document, window });
  return { root: document.documentElement, themeButtons, sizeButtons, store, handlers, media };
}

let total = 0;
const check = (condition, message) => {
  assert.ok(condition, message);
  total += 1;
  console.log(`OK - ${message}`);
};

const first = boot({ prefersDark: true });
check(first.root.dataset.theme === 'dark' && first.root.dataset.themePref === 'auto', 'sem escolha salva, o modo automático segue o tema escuro do sistema');
check(first.root.dataset.size === undefined, 'o tamanho médio é o padrão e não marca a página');
first.handlers.DOMContentLoaded();
check(first.themeButtons.auto.attrs['aria-pressed'] === 'true' && first.themeButtons.auto.attrs['aria-checked'] === 'true' && first.themeButtons.dark.attrs['aria-pressed'] === 'false', 'expõe a opção Automático como selecionada para tecnologia assistiva');
check(first.sizeButtons.md.attrs['aria-pressed'] === 'true' && first.sizeButtons.lg.attrs['aria-pressed'] === 'false', 'expõe o tamanho médio como selecionado');

first.themeButtons.light.click();
check(first.root.dataset.theme === 'light' && first.store['ativa-color-theme'] === 'light', 'escolher Claro aplica e salva o tema');
check(first.themeButtons.light.attrs['aria-checked'] === 'true' && first.themeButtons.auto.attrs['aria-checked'] === 'false', 'atualiza o estado acessível dos botões de tema');

const nextPage = boot({ theme: first.store['ativa-color-theme'], prefersDark: true });
check(nextPage.root.dataset.theme === 'light', 'mantém a escolha manual ao abrir outra página mesmo com o sistema escuro');
nextPage.handlers.DOMContentLoaded();
nextPage.themeButtons.dark.click();
check(nextPage.root.dataset.theme === 'dark' && nextPage.store['ativa-color-theme'] === 'dark', 'permite escolher Escuro');
nextPage.themeButtons.auto.click();
check(nextPage.root.dataset.theme === 'dark' && nextPage.store['ativa-color-theme'] === null, 'voltar ao Automático apaga a escolha salva e segue o sistema');
nextPage.media.matches = false;
nextPage.media.listeners.forEach((listener) => listener());
check(nextPage.root.dataset.theme === 'light', 'no Automático, acompanha a troca de tema do sistema com a página aberta');

first.sizeButtons.lg.click();
check(first.root.dataset.size === 'lg' && first.store['ativa-text-size'] === 'lg' && first.sizeButtons.lg.attrs['aria-pressed'] === 'true', 'escolher texto grande aplica, salva e marca o botão');
const sized = boot({ size: 'sm' });
check(sized.root.dataset.size === 'sm', 'mantém o tamanho do texto ao abrir outra página');
sized.handlers.DOMContentLoaded();
sized.sizeButtons.md.click();
check(sized.root.dataset.size === undefined && sized.store['ativa-text-size'] === null, 'voltar ao tamanho médio remove a marca e a escolha salva');

const blocked = boot({ storageBlocked: true, prefersDark: true });
blocked.handlers.DOMContentLoaded();
blocked.themeButtons.light.click();
blocked.sizeButtons.lg.click();
check(blocked.root.dataset.theme === 'light' && blocked.root.dataset.size === 'lg', 'continua funcionando se o navegador bloquear o armazenamento');

const invalid = boot({ theme: 'roxo', size: 'xxl' });
check(invalid.root.dataset.theme === 'light' && invalid.root.dataset.size === undefined, 'ignora valores salvos inválidos');

// Marcação: as três páginas usam o mesmo cabeçalho e oferecem o controle de Aparência.
const ui = read('../app/ui.php');
check(ui.indexOf("asset_url('theme.js')") > -1 && ui.indexOf("asset_url('theme.js')") < ui.indexOf("asset_url('theme.css')"), 'o tema é aplicado antes de as folhas de estilo carregarem');
check(ui.includes('data-theme-set="') && ui.includes('data-size-set="') && ui.includes("'light'") && ui.includes("'dark'") && ui.includes("'auto'"), 'os controles oferecem tema claro, escuro e automático e três tamanhos de texto');
for (const [file, label, control] of [
  ['../templates/layout.php', 'layout autenticado', 'appearance_button()'],
  ['../templates/login.php', 'login', 'appearance_panel()'],
  ['../install.php', 'instalação', 'appearance_panel()'],
]) {
  const markup = read(file);
  check(markup.includes('head_assets()') && markup.includes(control), `${label} carrega o cabeçalho comum e o controle de Aparência`);
}

// Tokens: o tema escuro e os tamanhos existem na folha de tema; nenhuma cor da identidade antiga permanece.
const themeCss = read('../assets/theme.css');
check(themeCss.includes(':root[data-theme="dark"]') && themeCss.includes(':root[data-size="sm"]') && themeCss.includes(':root[data-size="lg"]'), 'a folha de tema define o escuro e os tamanhos de texto');
const legacy = /#003566|--green-dark|--paper|--card\b|Manrope|assets\/brand/;
check(!legacy.test(themeCss) && !legacy.test(read('../assets/app.css')) && !legacy.test(read('../assets/permissions.css')), 'os estilos não usam mais cores, variáveis ou fontes da identidade antiga');

console.log(`Total: ${total} verificações de tema aprovadas.`);
