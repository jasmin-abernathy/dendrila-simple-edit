/* Dendrila Simple Edit — accessible tabs and native WordPress media selection.
 * URLs keep tabs working without JavaScript. Saving the form is always explicit. */
(function () {
    'use strict';

    function revealSelectedTab() {
        var nav = document.querySelector('.dse-nav-tabs');
        if (!nav) { return; }
        var selected = nav.querySelector('[aria-current="page"]');
        if (!selected) { return; }
        var desired = selected.offsetLeft - Math.max(0, (nav.clientWidth - selected.offsetWidth) / 2);
        nav.scrollLeft = Math.max(0, desired);
    }

    function initMediaPicker() {
        var form = document.querySelector('[data-dse-media-picker]');
        if (!form) { return; }
        var field = form.querySelector('#dse-media-ids');
        var list = form.querySelector('[data-dse-media-list]');
        var openButton = form.querySelector('[data-dse-media-open]');
        var clearButton = form.querySelector('[data-dse-media-clear]');
        var count = form.querySelector('[data-dse-media-count]');
        var empty = form.querySelector('[data-dse-media-empty]');
        var feedback = form.querySelector('[data-dse-media-feedback]');
        var advanced = form.querySelector('.dse-media-advanced');
        if (!field || !list || !openButton || !clearButton || !count || !empty || !feedback) {
            return;
        }

        // If WordPress has not supplied its native picker, the advanced text field
        // still permits managing the allowlist and the old form keeps working.
        if (!window.wp || !window.wp.media || typeof window.wp.media !== 'function') {
            if (advanced) { advanced.open = true; }
            return;
        }

        var limit = 60;
        var records = {};
        var ids = [];
        Array.prototype.forEach.call(list.querySelectorAll('[data-dse-media-id]'), function (node) {
            var id = Number(node.getAttribute('data-dse-media-id'));
            if (!Number.isSafeInteger(id) || id < 1 || ids.indexOf(id) !== -1) { return; }
            ids.push(id);
            records[id] = {
                title: node.getAttribute('data-dse-media-title') || ('Image ' + id),
                url: node.getAttribute('data-dse-media-url') || ''
            };
        });

        function setFeedback(message) {
            feedback.hidden = !message;
            feedback.textContent = message || '';
        }
        function fillRecord(model) {
            var id = Number(model.id);
            if (!Number.isSafeInteger(id) || id < 1) { return null; }
            if (model.get('type') && model.get('type') !== 'image') { return null; }
            var sizes = model.get('sizes') || {};
            records[id] = {
                title: model.get('title') || model.get('filename') || ('Image ' + id),
                url: (sizes.thumbnail && sizes.thumbnail.url) || model.get('icon') || model.get('url') || ''
            };
            return id;
        }
        function syncForm() {
            field.value = ids.join(', ');
        }
        function render() {
            while (list.firstChild) { list.removeChild(list.firstChild); }
            ids.forEach(function (id) {
                var data = records[id] || {title: 'Image ' + id, url: ''};
                var item = document.createElement('li');
                item.className = 'dse-media-item';
                if (data.url) {
                    var img = document.createElement('img');
                    img.src = data.url;
                    img.alt = '';
                    img.loading = 'lazy';
                    img.width = 88;
                    img.height = 88;
                    item.appendChild(img);
                } else {
                    var placeholder = document.createElement('span');
                    placeholder.className = 'dse-media-placeholder dashicons dashicons-format-image';
                    placeholder.setAttribute('aria-hidden', 'true');
                    item.appendChild(placeholder);
                }
                var name = document.createElement('span');
                name.className = 'dse-media-name';
                name.textContent = data.title;
                item.appendChild(name);
                var remove = document.createElement('button');
                remove.type = 'button';
                remove.className = 'button-link-delete dse-media-remove';
                remove.textContent = 'Retirer';
                remove.setAttribute('aria-label', 'Retirer ' + data.title + ' de la sélection');
                remove.addEventListener('click', function () {
                    ids = ids.filter(function (itemId) { return itemId !== id; });
                    syncForm();
                    setFeedback('');
                    render();
                    // Keep keyboard focus on the next available action.
                    var next = list.querySelector('.dse-media-remove');
                    (next || openButton).focus();
                });
                item.appendChild(remove);
                list.appendChild(item);
            });
            count.textContent = ids.length + (ids.length === 1 ? ' image sélectionnée' : ' images sélectionnées');
            empty.hidden = ids.length !== 0;
            clearButton.hidden = ids.length === 0;
        }

        openButton.hidden = false;
        clearButton.hidden = ids.length === 0;
        clearButton.addEventListener('click', function () {
            ids = [];
            syncForm();
            setFeedback('');
            render();
            openButton.focus();
        });
        var frame;
        openButton.addEventListener('click', function () {
            setFeedback('');
            if (!frame) {
                frame = window.wp.media({
                    title: 'Choisir les images autorisées',
                    button: {text: 'Utiliser ces images'},
                    library: {type: 'image'},
                    multiple: true
                });
                frame.on('open', function () {
                    var selection = frame.state().get('selection');
                    selection.reset();
                    ids.forEach(function (id) {
                        var attachment = window.wp.media.attachment(id);
                        attachment.fetch();
                        selection.add(attachment);
                    });
                });
                frame.on('select', function () {
                    var models = frame.state().get('selection').models;
                    var nextIds = [];
                    models.forEach(function (model) {
                        var id = fillRecord(model);
                        if (id && nextIds.indexOf(id) === -1) { nextIds.push(id); }
                    });
                    if (nextIds.length > limit) {
                        setFeedback('Vous pouvez choisir 60 images maximum. Réduisez votre sélection.');
                        return;
                    }
                    ids = nextIds;
                    syncForm();
                    render();
                });
            }
            frame.open();
        });
        // When a technically experienced admin edits the fallback field, reflect
        // valid IDs without silently replacing invalid input before submission.
        field.addEventListener('change', function () {
            var raw = field.value.trim();
            var chunks = raw ? raw.split(/[\s,;]+/) : [];
            if (chunks.length > limit || chunks.some(function (chunk) { return !/^[1-9][0-9]*$/.test(chunk); })) {
                setFeedback('Vérifiez la liste : numéros valides, 60 images maximum.');
                return;
            }
            var parsed = [];
            chunks.forEach(function (chunk) {
                var id = Number(chunk);
                if (Number.isSafeInteger(id) && parsed.indexOf(id) === -1) { parsed.push(id); }
            });
            if (parsed.length !== chunks.length) {
                // Duplicate IDs are harmless; keep the visible list and input aligned.
                field.value = parsed.join(', ');
            }
            ids = parsed;
            render();
            setFeedback('');
        });
        render();
    }

    /** Uploads always belong to the logged-in editor; there is no admin approval step. */
    function initPersonalUploads() {
        var trigger = document.querySelector('[data-dse-upload-open]');
        if (!trigger || !window.wp || !window.wp.media) { return; }
        trigger.hidden = false;
        var status = document.querySelector('[data-dse-upload-status]');
        var frame;
        trigger.addEventListener('click', function () {
            if (!frame) {
                frame = window.wp.media({
                    title: 'Ajouter des images',
                    button: {text: 'Terminer'},
                    library: {type: 'image'},
                    multiple: true
                });
                frame.on('close', function () {
                    if (status) {
                        status.textContent = 'Les images importées sont disponibles. Rechargez la page pour voir les nouvelles miniatures.';
                    }
                });
            }
            frame.open();
        });
    }

    /** Single image attached to one editable shortcode; use the native picker. */
    function initContentPhotoPickers() {
        if (!window.wp || !window.wp.media) { return; }
        Array.prototype.forEach.call(document.querySelectorAll('[data-dse-photo-picker]'), function (panel) {
            var select = panel.querySelector('[data-dse-photo-select]');
            var trigger = panel.querySelector('[data-dse-photo-open]');
            var clear = panel.querySelector('[data-dse-photo-clear]');
            var preview = panel.querySelector('[data-dse-photo-preview]');
            if (!select || !trigger || !clear || !preview) { return; }
            trigger.hidden = false;
            // JS-enhanced UI is image-first; the HTML select remains a fallback.
            select.hidden = true;
            var frame;
            function showThumbnail(url, title) {
                while (preview.firstChild) { preview.removeChild(preview.firstChild); }
                if (url) {
                    var img = document.createElement('img');
                    img.src = url;
                    img.alt = title || 'Image sélectionnée';
                    img.loading = 'lazy';
                    preview.appendChild(img);
                }
                clear.hidden = !select.value || select.value === '0';
            }
            function setSelection(model) {
                if (!model) { return; }
                var id = String(model.id || '');
                if (!/^\d+$/.test(id) || !id || model.get('type') !== 'image') { return; }
                var title = model.get('title') || model.get('filename') || 'Image choisie';
                var option = Array.prototype.find.call(select.options, function (item) {
                    return item.value === id;
                });
                if (!option) {
                    option = document.createElement('option');
                    option.value = id;
                    select.appendChild(option);
                }
                option.textContent = title;
                select.value = id;
                var sizes = model.get('sizes') || {};
                showThumbnail((sizes.thumbnail && sizes.thumbnail.url) || model.get('url') || '', title);
            }
            trigger.addEventListener('click', function () {
                if (!frame) {
                    frame = window.wp.media({
                        title: 'Choisir ou importer une image',
                        button: {text: 'Utiliser cette image'},
                        library: {type: 'image'},
                        multiple: false
                    });
                    frame.on('select', function () {
                        setSelection(frame.state().get('selection').first());
                    });
                }
                frame.open();
            });
            clear.addEventListener('click', function () {
                select.value = Array.prototype.some.call(select.options, function (item) {
                    return item.value === '0';
                }) ? '0' : '';
                showThumbnail('', '');
                trigger.focus();
            });
            // A previously selected image may be shown by server-rendered markup.
            clear.hidden = !select.value || select.value === '0';
        });
    }

    function initAcfSelector() {
        var select = document.getElementById('dse-acf-content-select');
        if (!select || !select.form) { return; }
        var button = select.form.querySelector('[data-dse-acf-open]');
        select.addEventListener('change', function () {
            if (!select.value) { return; }
            // Keep the normal GET form as a no-JavaScript fallback.
            if (typeof select.form.requestSubmit === 'function') {
                select.form.requestSubmit();
            } else {
                select.form.submit();
            }
        });
        if (button) { button.hidden = true; }
    }

    function initNavigationSorter() {
        Array.prototype.forEach.call(document.querySelectorAll('[data-dse-nav-sort]'), function (list) {
            var status = list.parentNode.querySelector('[data-dse-nav-status]');
            var drag = null;
            var frame = null;
            function rows() {
                return Array.prototype.slice.call(list.querySelectorAll(':scope > .dse-nav-sort-item'));
            }
            function announce(message) {
                if (status) { status.textContent = message; }
            }
            function announcePosition(row) {
                var all = rows();
                var title = row.querySelector('.dse-nav-sort-label');
                announce((title ? title.textContent.trim() : 'Élément') + ' : position ' +
                    (all.indexOf(row) + 1) + ' sur ' + all.length +
                    '. Pensez à enregistrer.');
            }
            function reorderAt(x, y) {
                if (!drag) { return; }
                var hit = document.elementFromPoint(x, y);
                var target = hit && hit.closest('.dse-nav-sort-item');
                if (!target || target === drag.row || target.parentNode !== list) { return; }
                var rect = target.getBoundingClientRect();
                if (y < rect.top + rect.height / 2) {
                    list.insertBefore(drag.row, target);
                } else {
                    list.insertBefore(drag.row, target.nextElementSibling);
                }
            }
            function autoScroll() {
                if (!drag) { frame = null; return; }
                var margin = 72;
                var speed = 0;
                if (drag.y < margin) {
                    speed = -Math.max(2, (margin - drag.y) / 5);
                } else if (drag.y > window.innerHeight - margin) {
                    speed = Math.max(2, (drag.y - (window.innerHeight - margin)) / 5);
                }
                if (speed) {
                    window.scrollBy(0, speed);
                    reorderAt(drag.x, drag.y);
                }
                frame = window.requestAnimationFrame(autoScroll);
            }
            function finish(cancelled) {
                if (!drag) { return; }
                var selected = drag;
                drag = null;
                if (frame !== null) {
                    window.cancelAnimationFrame(frame);
                    frame = null;
                }
                if (cancelled) {
                    selected.original.forEach(function (row) { list.appendChild(row); });
                    announce('Déplacement annulé.');
                } else {
                    announcePosition(selected.row);
                }
                selected.row.classList.remove('is-dragging');
                list.classList.remove('is-dragging');
                if (selected.handle.hasPointerCapture &&
                    selected.handle.hasPointerCapture(selected.pointerId)) {
                    selected.handle.releasePointerCapture(selected.pointerId);
                }
            }
            list.addEventListener('pointerdown', function (event) {
                var handle = event.target.closest('[data-dse-nav-handle]');
                if (!handle || !list.contains(handle) || drag ||
                    (event.pointerType === 'mouse' && event.button !== 0)) { return; }
                var row = handle.closest('.dse-nav-sort-item');
                if (!row) { return; }
                event.preventDefault();
                handle.focus({preventScroll: true});
                drag = {row: row, handle: handle, pointerId: event.pointerId,
                    x: event.clientX, y: event.clientY, original: rows()};
                row.classList.add('is-dragging');
                list.classList.add('is-dragging');
                try { handle.setPointerCapture(event.pointerId); }
                catch (error) { finish(true); return; }
                frame = window.requestAnimationFrame(autoScroll);
            });
            list.addEventListener('pointermove', function (event) {
                if (!drag || event.pointerId !== drag.pointerId) { return; }
                drag.x = event.clientX;
                drag.y = event.clientY;
                reorderAt(drag.x, drag.y);
            });
            list.addEventListener('pointerup', function (event) {
                if (drag && event.pointerId === drag.pointerId) {
                    reorderAt(event.clientX, event.clientY);
                    finish(false);
                }
            });
            list.addEventListener('pointercancel', function (event) {
                if (drag && event.pointerId === drag.pointerId) { finish(true); }
            });
            list.addEventListener('lostpointercapture', function (event) {
                if (drag && event.pointerId === drag.pointerId) { finish(true); }
            });
            list.addEventListener('keydown', function (event) {
                var handle = event.target.closest('[data-dse-nav-handle]');
                if (!handle || !list.contains(handle) || drag ||
                    ['ArrowUp', 'ArrowDown', 'Home', 'End'].indexOf(event.key) === -1) { return; }
                var row = handle.closest('.dse-nav-sort-item');
                if (!row) { return; }
                event.preventDefault();
                if (event.key === 'ArrowUp' && row.previousElementSibling) {
                    list.insertBefore(row, row.previousElementSibling);
                } else if (event.key === 'ArrowDown' && row.nextElementSibling) {
                    list.insertBefore(row, row.nextElementSibling.nextElementSibling);
                } else if (event.key === 'Home') {
                    list.insertBefore(row, list.firstElementChild);
                } else if (event.key === 'End') {
                    list.appendChild(row);
                } else { return; }
                handle.focus({preventScroll: true});
                announcePosition(row);
            });
        });
    }

    function initListCreator() {
        Array.prototype.forEach.call(document.querySelectorAll('[data-dse-list-open]'), function (link) {
            link.addEventListener('click', function (event) {
                var details = document.getElementById('dse-add-list');
                if (!details) { return; }
                event.preventDefault();
                details.open = true;
                details.scrollIntoView({behavior: 'smooth', block: 'start'});
                var field = details.querySelector('[data-dse-new-list-name]');
                if (field) { field.focus({preventScroll: true}); }
            });
        });
    }

    /**
     * Show a second set of form actions at the top of every creation/edit form.
     * Both sets belong to the SAME HTML form: browser validation, nonce checks,
     * editor serialization and permission checks are therefore unchanged.
     *
     * Never clone one-click destructive actions (remove, restore, unsubscribe).
     * Forms without editable controls also stay unchanged.
     */
    function initTopFormActions() {
        var excluded = [
            'dse_restore', 'dse_acf_remove', 'dse_acf_add', 'dse_newsletter_approve',
            'dse_newsletter_reject', 'dse_ml_remove', 'dse_ml_reinvite',
            'dse_ml_start', 'dse_ml_pause'
        ];
        var forms = document.querySelectorAll('.dse-wrap form[method="post"]');
        Array.prototype.forEach.call(forms, function (form) {
            if (form.querySelector('.dse-form-actions-top')) { return; }
            var actionField = form.querySelector('input[name="action"]');
            if (!actionField || actionField.value.indexOf('dse_') !== 0 ||
                excluded.indexOf(actionField.value) !== -1) { return; }
            var editable = form.querySelector('input:not([type="hidden"]):not([type="submit"]):not([type="button"]), select, textarea');
            if (!editable) { return; }
            var submits = Array.prototype.filter.call(
                form.querySelectorAll('button[type="submit"], input[type="submit"]'),
                function (button) {
                    return button.closest('form') === form &&
                        !button.closest('.dse-form-actions-top');
                }
            );
            if (!submits.length) { return; }
            var bar = document.createElement('div');
            bar.className = 'dse-form-actions-top';
            bar.setAttribute('role', 'group');
            bar.setAttribute('aria-label', 'Actions du formulaire (également disponibles en bas)');
            submits.forEach(function (original) {
                var copy = original.cloneNode(true);
                copy.removeAttribute('id');
                copy.removeAttribute('form');
                copy.classList.add('dse-form-top-submit');
                bar.appendChild(copy);
            });
            // Keep the heading and short description above the action bar.
            var before = null;
            Array.prototype.some.call(form.children, function (child) {
                var tag = child.tagName;
                if (tag === 'H2' || tag === 'H3' || tag === 'H4' ||
                    (tag === 'INPUT' && child.type === 'hidden') ||
                    (tag === 'P' && !child.querySelector('input, select, textarea, button'))) {
                    return false;
                }
                before = child;
                return true;
            });
            form.insertBefore(bar, before);
        });
    }

    function init() {
        revealSelectedTab();
        initListCreator();
        initTopFormActions();
        initNavigationSorter();
        initAcfSelector();
        initMediaPicker();
        initPersonalUploads();
        initContentPhotoPickers();
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init, {once: true});
    } else {
        init();
    }
}());
