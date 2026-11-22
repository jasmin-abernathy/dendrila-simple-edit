/* Submenu defaults for navigations explicitly organized in Dendrila Simple Edit.
 * Never affect ordinary WordPress submenus or the desktop navigation. */
(function () {
    'use strict';
    var selector = '.dendrila-simple-edit-mobile-open, .dendrila-simple-edit-mobile-closed';
    function isOpenByDefault(item) { return item.classList.contains('dendrila-simple-edit-mobile-open'); }
    function isVisibleMobileMenu(item) {
        return !!item.closest('.wp-block-navigation__responsive-container.is-menu-open') ||
            (!!item.closest('.menu-item-has-children') && window.matchMedia('(max-width: 782px)').matches);
    }
    function setExpanded(item, opened) {
        item.classList.toggle('dendrila-simple-edit-submenu-expanded', opened);
        item.classList.toggle('dendrila-simple-edit-submenu-collapsed', !opened);
        var toggle = item.querySelector(':scope > .wp-block-navigation-submenu__toggle, :scope > .dendrila-simple-edit-classic-toggle');
        if (toggle) { toggle.setAttribute('aria-expanded', opened ? 'true' : 'false'); }
    }
    function prepare(item) {
        if (item.classList.contains('menu-item-has-children') &&
            !item.querySelector(':scope > .dendrila-simple-edit-classic-toggle')) {
            var ul = item.querySelector(':scope > .sub-menu');
            if (ul) {
                var button = document.createElement('button');
                button.type = 'button';
                button.className = 'dendrila-simple-edit-classic-toggle';
                button.textContent = (window.dendrilaSimpleEditNavigationStrings || {}).toggle || 'Afficher ou masquer le sous-menu';
                ul.before(button);
            }
        }
        setExpanded(item, isOpenByDefault(item));
    }
    function refresh(overlay) {
        if (!overlay.classList.contains('is-menu-open')) { return; }
        overlay.querySelectorAll(selector).forEach(prepare);
    }
    function init() {
        document.querySelectorAll(selector).forEach(prepare);
        document.querySelectorAll('.wp-block-navigation__responsive-container').forEach(function (overlay) {
            var observer = new MutationObserver(function () { refresh(overlay); });
            observer.observe(overlay, {attributes:true, attributeFilter:['class']});
            refresh(overlay);
        });
    }
    document.addEventListener('click', function (event) {
        var button = event.target.closest('.wp-block-navigation-submenu__toggle, .dendrila-simple-edit-classic-toggle');
        if (!button) { return; }
        var item = button.closest(selector);
        if (!item || !isVisibleMobileMenu(item)) { return; }
        // WordPress opens every submenu in some mobile overlays, regardless of
        // open-on-click. For these explicitly managed items our state wins.
        event.preventDefault();
        event.stopImmediatePropagation();
        setExpanded(item, item.classList.contains('dendrila-simple-edit-submenu-collapsed'));
    }, true);
    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', init); }
    else { init(); }
}());
