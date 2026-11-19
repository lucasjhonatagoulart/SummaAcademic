// Aparência: tema (claro, escuro ou automático) e tamanho do texto (pequeno, médio ou grande).
// Carregado no <head>, antes dos estilos, para a página já abrir com a aparência escolhida.
// As escolhas ficam salvas neste navegador; "automático" e "médio" são os padrões e não ocupam espaço.
(() => {
  const themeKey = 'ativa-color-theme';
  const sizeKey = 'ativa-text-size';
  const root = document.documentElement;

  const read = (key) => {
    try { return window.localStorage.getItem(key); } catch (_) { return null; }
  };
  const write = (key, value) => {
    try {
      if (value === null) window.localStorage.removeItem(key);
      else window.localStorage.setItem(key, value);
    } catch (_) {
      // A escolha continua valendo nesta página mesmo se o navegador bloquear o armazenamento.
    }
  };
  const systemDark = () => Boolean(window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches);

  let themePref = read(themeKey);
  if (themePref !== 'light' && themePref !== 'dark') themePref = 'auto';
  let sizePref = read(sizeKey);
  if (sizePref !== 'sm' && sizePref !== 'lg') sizePref = 'md';

  const applyTheme = () => {
    root.dataset.theme = themePref === 'auto' ? (systemDark() ? 'dark' : 'light') : themePref;
    root.dataset.themePref = themePref;
  };
  const applySize = () => {
    if (sizePref === 'md') delete root.dataset.size;
    else root.dataset.size = sizePref;
  };
  applyTheme();
  applySize();

  const initializeControls = () => {
    const themeButtons = Array.from(document.querySelectorAll('[data-theme-set]'));
    const sizeButtons = Array.from(document.querySelectorAll('[data-size-set]'));
    const update = () => {
      themeButtons.forEach((button) => {
        const on = String(button.dataset.themeSet === themePref);
        button.setAttribute('aria-pressed', on);
        if (button.hasAttribute('aria-checked')) button.setAttribute('aria-checked', on);
      });
      sizeButtons.forEach((button) => button.setAttribute('aria-pressed', String(button.dataset.sizeSet === sizePref)));
    };

    themeButtons.forEach((button) => button.addEventListener('click', () => {
      const choice = button.dataset.themeSet;
      themePref = choice === 'light' || choice === 'dark' ? choice : 'auto';
      write(themeKey, themePref === 'auto' ? null : themePref);
      applyTheme();
      update();
    }));
    sizeButtons.forEach((button) => button.addEventListener('click', () => {
      const choice = button.dataset.sizeSet;
      sizePref = choice === 'sm' || choice === 'lg' ? choice : 'md';
      write(sizeKey, sizePref === 'md' ? null : sizePref);
      applySize();
      update();
    }));

    // No modo automático, acompanha a troca de tema do computador ou celular com a página aberta.
    const media = window.matchMedia ? window.matchMedia('(prefers-color-scheme: dark)') : null;
    if (media && media.addEventListener) media.addEventListener('change', () => { if (themePref === 'auto') applyTheme(); });

    update();
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initializeControls, { once: true });
  } else {
    initializeControls();
  }
})();
