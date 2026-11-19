// Estrutura da página: menu lateral (abrir no celular, recolher no computador) e menus do cabeçalho.
(() => {
  'use strict';
  const shell = document.querySelector('[data-shell]');
  const sidebar = document.querySelector('.sidebar');
  const scrim = document.querySelector('[data-close-side]');
  const collapsedKey = 'ativa-menu-collapsed';

  // Menu lateral no celular: abre sobre a página e fecha ao tocar fora ou apertar Esc.
  const setSidebar = (open) => {
    if (!sidebar || !scrim) return;
    sidebar.classList.toggle('open', open);
    scrim.hidden = !open;
  };
  document.querySelector('[data-open-side]')?.addEventListener('click', () => setSidebar(true));
  scrim?.addEventListener('click', () => setSidebar(false));

  // Menu recolhido no computador: a escolha fica salva neste navegador.
  try {
    try { if (window.localStorage.getItem(collapsedKey) === '1') shell?.classList.add('collapsed'); } catch (_) { /* armazenamento indisponível: começa expandido */ }
  } catch (_) { /* sem armazenamento, o menu abre expandido */ }
  document.querySelector('[data-toggle-collapse]')?.addEventListener('click', () => {
    if (!shell) return;
    const collapsed = shell.classList.toggle('collapsed');
    try { window.localStorage.setItem(collapsedKey, collapsed ? '1' : '0'); } catch (_) { /* vale só nesta página */ }
  });

  // Menus suspensos do cabeçalho (Aparência e usuário): um aberto por vez.
  const menus = [
    [document.querySelector('[data-appear-btn]'), document.querySelector('[data-appear-menu]')],
    [document.querySelector('[data-menu-btn]'), document.querySelector('[data-menu]')],
  ].filter(([button, menu]) => button && menu);
  const setMenu = (button, menu, open) => {
    menu.hidden = !open;
    button.setAttribute('aria-expanded', String(open));
  };
  const closeMenus = (except) => menus.forEach(([button, menu]) => { if (menu !== except) setMenu(button, menu, false); });
  menus.forEach(([button, menu]) => {
    button.addEventListener('click', (event) => {
      event.stopPropagation();
      const open = menu.hidden;
      closeMenus(menu);
      setMenu(button, menu, open);
    });
    menu.addEventListener('click', (event) => event.stopPropagation());
  });
  document.addEventListener('click', () => closeMenus(null));
  document.addEventListener('keydown', (event) => {
    if (event.key !== 'Escape') return;
    closeMenus(null);
    setSidebar(false);
  });
})();
