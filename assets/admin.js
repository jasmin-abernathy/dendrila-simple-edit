/* Dendrila Simple Edit — accessible tabs and native WordPress media selection.
 * URLs keep tabs working without JavaScript. Saving the form is always explicit. */
(function () {
    'use strict';
    var dendrilaI18n = window.dendrilaSimpleEditStrings || {};
    function t(phrase) { return Object.prototype.hasOwnProperty.call(dendrilaI18n, phrase) ? dendrilaI18n[phrase] : phrase; }

    function revealSelectedTab() {
        var nav = document.querySelector('.dendrila-simple-edit-nav-tabs');
        if (!nav) { return; }
        var selected = nav.querySelector('[aria-current="page"]');
        if (!selected) { return; }
        var desired = selected.offsetLeft - Math.max(0, (nav.clientWidth - selected.offsetWidth) / 2);
        nav.scrollLeft = Math.max(0, desired);
    }

    function initMediaPicker() {
        var form = document.querySelector('[data-dendrila-simple-edit-media-picker]');
        if (!form) { return; }
        var field = form.querySelector('#dendrila-simple-edit-media-ids');
        var list = form.querySelector('[data-dendrila-simple-edit-media-list]');
        var openButton = form.querySelector('[data-dendrila-simple-edit-media-open]');
        var clearButton = form.querySelector('[data-dendrila-simple-edit-media-clear]');
        var count = form.querySelector('[data-dendrila-simple-edit-media-count]');
        var empty = form.querySelector('[data-dendrila-simple-edit-media-empty]');
        var feedback = form.querySelector('[data-dendrila-simple-edit-media-feedback]');
        var advanced = form.querySelector('.dendrila-simple-edit-media-advanced');
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
        Array.prototype.forEach.call(list.querySelectorAll('[data-dendrila-simple-edit-media-id]'), function (node) {
            var id = Number(node.getAttribute('data-dendrila-simple-edit-media-id'));
            if (!Number.isSafeInteger(id) || id < 1 || ids.indexOf(id) !== -1) { return; }
            ids.push(id);
            records[id] = {
                title: node.getAttribute('data-dendrila-simple-edit-media-title') || (t('Image ') + id),
                url: node.getAttribute('data-dendrila-simple-edit-media-url') || ''
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
                title: model.get('title') || model.get('filename') || (t('Image ') + id),
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
                var data = records[id] || {title: t('Image ') + id, url: ''};
                var item = document.createElement('li');
                item.className = 'dendrila-simple-edit-media-item';
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
                    placeholder.className = 'dendrila-simple-edit-media-placeholder dashicons dashicons-format-image';
                    placeholder.setAttribute('aria-hidden', 'true');
                    item.appendChild(placeholder);
                }
                var name = document.createElement('span');
                name.className = 'dendrila-simple-edit-media-name';
                name.textContent = data.title;
                item.appendChild(name);
                var remove = document.createElement('button');
                remove.type = 'button';
                remove.className = 'button-link-delete dendrila-simple-edit-media-remove';
                remove.textContent = 'Retirer';
                remove.setAttribute('aria-label', t('Retirer ') + data.title + t(' de la sélection'));
                remove.addEventListener('click', function () {
                    ids = ids.filter(function (itemId) { return itemId !== id; });
                    syncForm();
                    setFeedback('');
                    render();
                    // Keep keyboard focus on the next available action.
                    var next = list.querySelector('.dendrila-simple-edit-media-remove');
                    (next || openButton).focus();
                });
                item.appendChild(remove);
                list.appendChild(item);
            });
            count.textContent = ids.length + (ids.length === 1 ? t(' image sélectionnée') : t(' images sélectionnées'));
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
                    title: t('Choisir les images autorisées'),
                    button: {text: t('Utiliser ces images')},
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
                        setFeedback(t('Vous pouvez choisir 60 images maximum. Réduisez votre sélection.'));
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
                setFeedback(t('Vérifiez la liste : numéros valides, 60 images maximum.'));
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
        var trigger = document.querySelector('[data-dendrila-simple-edit-upload-open]');
        if (!trigger || !window.wp || !window.wp.media) { return; }
        trigger.hidden = false;
        var status = document.querySelector('[data-dendrila-simple-edit-upload-status]');
        var frame;
        trigger.addEventListener('click', function () {
            if (!frame) {
                frame = window.wp.media({
                    title: t('Ajouter des images'),
                    button: {text: 'Terminer'},
                    library: {type: 'image'},
                    multiple: true
                });
                frame.on('close', function () {
                    if (status) {
                        status.textContent = t('Les images importées sont disponibles. Rechargez la page pour voir les nouvelles miniatures.');
                    }
                });
            }
            frame.open();
        });
    }

    /** Single image attached to one editable shortcode; use the native picker. */
    function initContentPhotoPickers() {
        if (!window.wp || !window.wp.media) { return; }
        Array.prototype.forEach.call(document.querySelectorAll('[data-dendrila-simple-edit-photo-picker]'), function (panel) {
            var select = panel.querySelector('[data-dendrila-simple-edit-photo-select]');
            var trigger = panel.querySelector('[data-dendrila-simple-edit-photo-open]');
            var clear = panel.querySelector('[data-dendrila-simple-edit-photo-clear]');
            var preview = panel.querySelector('[data-dendrila-simple-edit-photo-preview]');
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
                    img.alt = title || t('Image sélectionnée');
                    img.loading = 'lazy';
                    preview.appendChild(img);
                }
                clear.hidden = !select.value || select.value === '0';
            }
            function setSelection(model) {
                if (!model) { return; }
                var id = String(model.id || '');
                if (!/^\d+$/.test(id) || !id || model.get('type') !== 'image') { return; }
                var title = model.get('title') || model.get('filename') || t('Image choisie');
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
                        title: t('Choisir ou importer une image'),
                        button: {text: t('Utiliser cette image')},
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
        var select = document.getElementById('dendrila-simple-edit-acf-content-select');
        if (!select || !select.form) { return; }
        var button = select.form.querySelector('[data-dendrila-simple-edit-acf-open]');
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

    // The list, unlike a moving row, stays in the DOM during pointer capture.
    // This is important on Android: capturing a row and then moving it in the
    // document can immediately fire lostpointercapture and cancel the gesture.
    function initNavigationSorter() {
        Array.prototype.forEach.call(document.querySelectorAll('[data-dendrila-simple-edit-nav-sort]'), function (list) {
            var status = list.parentNode.querySelector('[data-dendrila-simple-edit-nav-status]');
            var drag = null;
            var frame = null;
            function rows() {
                return Array.prototype.filter.call(list.children, function (row) {
                    return row.classList.contains('dendrila-simple-edit-nav-sort-item');
                });
            }
            function id(row) { return row.getAttribute('data-dendrila-simple-edit-nav-id'); }
            function parent(row) { return row.querySelector('[data-dendrila-simple-edit-nav-parent]').value; }
            function rowById(key) { return rows().find(function (r) { return id(r) === key; }) || null; }
            function childrenOf(row) {
                var all = rows(), start = all.indexOf(row), found = [];
                for (var i = start + 1; i < all.length && parent(all[i]) === id(row); i++) {
                    found.push(all[i]);
                }
                return found;
            }
            function blockOf(row) { return [row].concat(parent(row) === '0' ? childrenOf(row) : []); }
            function nextAfter(row) {
                var part = blockOf(row);
                return part[part.length - 1].nextElementSibling;
            }
            function announce(message) { if (status) { status.textContent = message; } }
            function title(row) {
                var label = row.querySelector('.dendrila-simple-edit-nav-sort-label');
                return label ? label.textContent.trim() : t('Élément');
            }
            function announcePosition(row) {
                announce(title(row) + ' : position ' + (rows().indexOf(row) + 1) +
                    ' sur ' + rows().length + t('. Pensez à enregistrer.'));
            }
            function refresh() {
                var all = rows();
                all.forEach(function (row) {
                    var isChild = parent(row) !== '0';
                    row.classList.toggle('is-child', isChild);
                    var hasChildren = all.some(function (item) { return parent(item) === id(row); });
                    row.classList.toggle('has-children', hasChildren);
                    var mobile = row.querySelector('[data-dendrila-simple-edit-nav-mobile-wrap]');
                    if (mobile) { mobile.hidden = isChild || !hasChildren; }
                });
                all.forEach(function (row) {
                    var select = row.querySelector('[data-dendrila-simple-edit-nav-parent]');
                    var hasChildren = all.some(function (item) { return parent(item) === id(row); });
                    Array.prototype.forEach.call(select.options, function (option) {
                        if (option.value === '0') { return; }
                        var candidate = rowById(option.value);
                        option.disabled = row.getAttribute('data-dendrila-simple-edit-nav-heading') === '1' ||
                            !candidate || parent(candidate) !== '0' ||
                            option.value === id(row) || hasChildren;
                    });
                });
            }
            function placeBlock(row, before) {
                var fragment = document.createDocumentFragment();
                blockOf(row).forEach(function (node) { fragment.appendChild(node); });
                list.insertBefore(fragment, before || null);
                refresh();
            }
            function assignParent(row, parentId) {
                var select = row.querySelector('[data-dendrila-simple-edit-nav-parent]');
                var group = rowById(parentId);
                var old = select.value;
                if (!group || parent(group) !== '0' || group === row || childrenOf(row).length ||
                    row.getAttribute('data-dendrila-simple-edit-nav-heading') === '1') {
                    select.value = old;
                    return false;
                }
                select.value = parentId;
                // Move the row *after* the new group's last child.
                var groupChildren = childrenOf(group).filter(function (item) { return item !== row; });
                var last = groupChildren.length ? groupChildren[groupChildren.length - 1] : group;
                list.insertBefore(row, last.nextElementSibling);
                refresh();
                announce(t('Sous-menu créé. Pensez à enregistrer.')); 
                return true;
            }
            function changeParent(row, parentId) {
                var select = row.querySelector('[data-dendrila-simple-edit-nav-parent]');
                var old = row.dataset.navOldParent || select.value;
                // The browser has already changed the SELECT. Restore its old value
                // temporarily so we can find the previous group's real last child.
                select.value = old;
                if (parentId === '0') {
                    var former = rowById(old);
                    var afterGroup = former ? nextAfter(former) : row.nextElementSibling;
                    select.value = '0';
                    list.insertBefore(row, afterGroup);
                    refresh();
                    announce(t('Lien replacé au premier niveau. Pensez à enregistrer.')); 
                    return;
                }
                if (!assignParent(row, parentId)) {
                    select.value = old;
                    refresh();
                    announce(t('Choisissez un parent du premier niveau, sans créer de troisième niveau.')); 
                }
            }
            function siblingMove(row, direction) {
                var all = rows(), pid = parent(row);
                var peers = all.filter(function (item) { return parent(item) === pid; });
                var index = peers.indexOf(row), other = peers[index + direction];
                if (!other) { return false; }
                if (direction < 0) { placeBlock(row, other); }
                else { placeBlock(row, nextAfter(other)); }
                announcePosition(row);
                return true;
            }
            function reorderOver(target, after) {
                if (!drag || !target || target === drag.row || blockOf(drag.row).indexOf(target) !== -1) { return; }
                var moved = drag.row;
                var targetGroup = (parent(target) !== '0' && parent(moved) === '0') ?
                    rowById(parent(target)) : target;
                if (!targetGroup || blockOf(moved).indexOf(targetGroup) !== -1) { return; }
                var destinationParent = parent(moved) === '0' ? '0' : parent(target);
                // Moving a child onto a root row makes it a root, unless the explicit
                // drop target (below) was used to enter that root's submenu.
                moved.querySelector('[data-dendrila-simple-edit-nav-parent]').value = destinationParent;
                placeBlock(moved, after ? nextAfter(targetGroup) : targetGroup);
            }
            function dragTarget(x, y, commit) {
                if (!drag) { return; }
                var hit = document.elementFromPoint(x, y);
                var target = hit && hit.closest('.dendrila-simple-edit-nav-sort-item');
                var drop = hit && hit.closest('[data-dendrila-simple-edit-nav-group-drop]');
                Array.prototype.forEach.call(rows(), function (row) {
                    row.classList.toggle('is-group-target', !!(drop && target === row &&
                        row !== drag.row && parent(row) === '0' && !childrenOf(drag.row).length));
                });
                if (!target || target.parentNode !== list || target === drag.row) { return; }
                if (drop && parent(target) === '0' && !childrenOf(drag.row).length) {
                    if (commit) { assignParent(drag.row, id(target)); }
                    return;
                }
                var box = target.getBoundingClientRect();
                reorderOver(target, y >= box.top + box.height / 2);
            }
            function scrollNearEdge() {
                if (!drag) { frame = null; return; }
                var margin = 66, speed = 0;
                if (drag.y < margin) { speed = -Math.max(2, (margin - drag.y) / 6); }
                else if (drag.y > window.innerHeight - margin) {
                    speed = Math.max(2, (drag.y - (window.innerHeight - margin)) / 6);
                }
                if (speed) { window.scrollBy(0, speed); dragTarget(drag.x, drag.y, false); }
                frame = window.requestAnimationFrame(scrollNearEdge);
            }
            function finish(cancelled, event) {
                if (!drag) { return; }
                var previous = drag;
                if (!cancelled && event) { dragTarget(event.clientX, event.clientY, true); }
                drag = null;
                if (frame !== null) { window.cancelAnimationFrame(frame); frame = null; }
                if (cancelled) {
                    previous.original.forEach(function (entry) {
                        entry.row.querySelector('[data-dendrila-simple-edit-nav-parent]').value = entry.parent;
                        list.appendChild(entry.row);
                    });
                    announce(t('Déplacement annulé.'));
                } else { announcePosition(previous.row); }
                previous.row.classList.remove('is-dragging');
                list.classList.remove('is-dragging');
                rows().forEach(function (row) { row.classList.remove('is-group-target'); });
                if (list.hasPointerCapture && list.hasPointerCapture(previous.pointerId)) {
                    list.releasePointerCapture(previous.pointerId);
                }
                refresh();
            }
            list.addEventListener('pointerdown', function (event) {
                var handle = event.target.closest('[data-dendrila-simple-edit-nav-handle]');
                if (!handle || !list.contains(handle) || drag ||
                    (event.pointerType === 'mouse' && event.button !== 0)) { return; }
                var row = handle.closest('.dendrila-simple-edit-nav-sort-item');
                if (!row) { return; }
                event.preventDefault();
                handle.focus({preventScroll:true});
                drag = {row:row, handle:handle, pointerId:event.pointerId,
                    x:event.clientX, y:event.clientY,
                    original:rows().map(function (item) { return {row:item, parent:parent(item)}; })};
                row.classList.add('is-dragging');
                list.classList.add('is-dragging');
                try { list.setPointerCapture(event.pointerId); }
                catch (error) { finish(true); return; }
                announce(t('Déplacez ce lien. Déposez sur « Sous-menu » pour grouper.')); 
                frame = window.requestAnimationFrame(scrollNearEdge);
            });
            list.addEventListener('pointermove', function (event) {
                if (!drag || event.pointerId !== drag.pointerId) { return; }
                drag.x=event.clientX; drag.y=event.clientY;
                dragTarget(drag.x, drag.y, false);
            });
            list.addEventListener('pointerup', function (event) {
                if (drag && event.pointerId === drag.pointerId) { finish(false, event); }
            });
            list.addEventListener('pointercancel', function (event) {
                if (drag && event.pointerId === drag.pointerId) { finish(true); }
            });
            list.addEventListener('lostpointercapture', function (event) {
                if (drag && event.pointerId === drag.pointerId) { finish(true); }
            });
            list.addEventListener('keydown', function (event) {
                if (drag && event.key === 'Escape') { event.preventDefault(); finish(true); return; }
                var handle = event.target.closest('[data-dendrila-simple-edit-nav-handle]');
                if (!handle || !list.contains(handle) || drag) { return; }
                if (['ArrowUp', 'ArrowDown'].indexOf(event.key) === -1) { return; }
                event.preventDefault();
                var row = handle.closest('.dendrila-simple-edit-nav-sort-item');
                siblingMove(row, event.key === 'ArrowUp' ? -1 : 1);
                handle.focus({preventScroll:true});
            });
            list.addEventListener('click', function (event) {
                var control = event.target.closest('[data-dendrila-simple-edit-nav-move]');
                if (!control || !list.contains(control)) { return; }
                event.preventDefault();
                siblingMove(control.closest('.dendrila-simple-edit-nav-sort-item'),
                    control.getAttribute('data-dendrila-simple-edit-nav-move') === 'up' ? -1 : 1);
                control.focus({preventScroll:true});
            });
            list.addEventListener('focusin', function (event) {
                var select = event.target.closest('[data-dendrila-simple-edit-nav-parent]');
                if (select) { select.closest('.dendrila-simple-edit-nav-sort-item').dataset.navOldParent = select.value; }
            });
            list.addEventListener('change', function (event) {
                var select = event.target.closest('[data-dendrila-simple-edit-nav-parent]');
                if (!select) { return; }
                changeParent(select.closest('.dendrila-simple-edit-nav-sort-item'), select.value);
            });
            refresh();
        });
    }

    function initListCreator() {
        Array.prototype.forEach.call(document.querySelectorAll('[data-dendrila-simple-edit-list-open]'), function (link) {
            link.addEventListener('click', function (event) {
                var details = document.getElementById('dendrila-simple-edit-add-list');
                if (!details) { return; }
                event.preventDefault();
                details.open = true;
                details.scrollIntoView({behavior: 'smooth', block: 'start'});
                var field = details.querySelector('[data-dendrila-simple-edit-new-list-name]');
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
            'dendrila_simple_edit_restore', 'dendrila_simple_edit_acf_remove', 'dendrila_simple_edit_acf_add', 'dendrila_simple_edit_newsletter_approve',
            'dendrila_simple_edit_newsletter_reject', 'dendrila_simple_edit_ml_remove', 'dendrila_simple_edit_ml_reinvite',
            'dendrila_simple_edit_ml_start', 'dendrila_simple_edit_ml_pause'
        ];
        var forms = document.querySelectorAll('.dendrila-simple-edit-wrap form[method="post"]');
        Array.prototype.forEach.call(forms, function (form) {
            if (form.querySelector('.dendrila-simple-edit-form-actions-top') ||
                form.querySelector('[data-dendrila-simple-edit-template-editor][hidden]')) { return; }
            var actionField = form.querySelector('input[name="action"]');
            if (!actionField || actionField.value.indexOf('dendrila_simple_edit_') !== 0 ||
                excluded.indexOf(actionField.value) !== -1) { return; }
            var editable = form.querySelector('input:not([type="hidden"]):not([type="submit"]):not([type="button"]), select, textarea');
            if (!editable) { return; }
            // Keep compact forms uncluttered. The extra action is useful only when
            // a form has several controls and takes meaningful scrolling to finish.
            var editableControls = form.querySelectorAll(
                'input:not([type="hidden"]):not([type="submit"]):not([type="button"]), select, textarea'
            );
            if (editableControls.length < 4) { return; }
            var submits = Array.prototype.filter.call(
                form.querySelectorAll('button[type="submit"], input[type="submit"]'),
                function (button) {
                    return button.closest('form') === form &&
                        !button.closest('.dendrila-simple-edit-form-actions-top');
                }
            );
            if (!submits.length) { return; }
            var bar = document.createElement('div');
            bar.className = 'dendrila-simple-edit-form-actions-top';
            bar.setAttribute('role', 'group');
            bar.setAttribute('aria-label', t('Actions du formulaire (également disponibles en bas)'));
            submits.forEach(function (original) {
                var copy = original.cloneNode(true);
                copy.removeAttribute('id');
                copy.removeAttribute('form');
                copy.classList.add('dendrila-simple-edit-form-top-submit');
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

    function initBulkRolePermissions() {
        document.addEventListener('click', function (event) {
            var button = event.target.closest('[data-dendrila-simple-edit-select-all], [data-dendrila-simple-edit-select-none]');
            if (!button) { return; }
            var groupId = button.getAttribute('data-dendrila-simple-edit-select-all') ||
                button.getAttribute('data-dendrila-simple-edit-select-none');
            var fieldset = document.getElementById(groupId);
            if (!fieldset) { return; }
            var selected = button.hasAttribute('data-dendrila-simple-edit-select-all');
            Array.prototype.forEach.call(fieldset.querySelectorAll('input[type="checkbox"]'), function (checkbox) {
                if (!checkbox.disabled) { checkbox.checked = selected; }
            });
        });
    }

    function init() {
        revealSelectedTab();
        initListCreator();
        initBulkRolePermissions();
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

// Native clipboard: visible feedback and fallback for browsers without Clipboard API.
document.addEventListener('click', async function(event) {
    const button = event.target.closest('[data-dendrila-simple-edit-copy]');
    if (!button) return;
    const target = document.getElementById(button.getAttribute('data-dendrila-simple-edit-copy'));
    if (!target) return;
    const value = 'value' in target ? target.value : target.textContent;
    try {
        if (navigator.clipboard && window.isSecureContext) {
            await navigator.clipboard.writeText(value);
        } else {
            const temporary = document.createElement('textarea');
            temporary.value = value;
            temporary.style.position = 'fixed';
            temporary.style.opacity = '0';
            document.body.appendChild(temporary);
            temporary.select();
            if (!document.execCommand('copy')) throw new Error('Copy unavailable');
            temporary.remove();
        }
        button.textContent = t('Copié !');
    } catch (error) {
        button.textContent = t('Copie impossible : sélectionnez le code');
        if (typeof target.select === 'function') target.select();
    }
});

// WooCommerce product subtabs. Native forms still work if JavaScript is disabled.
(function () {
    'use strict';
    function setupTabs(group) {
        const buttons = Array.from(group.querySelectorAll('[data-dendrila-simple-edit-product-tab]'));
        const panels = Array.from(group.querySelectorAll('[data-dendrila-simple-edit-product-panel]'));
        if (!buttons.length || !panels.length) return;
        const prefix = 'dendrila-simple-edit-product-panel-' + Array.from(document.querySelectorAll('[data-dendrila-simple-edit-product-tabs]')).indexOf(group);
        function activate(key, focus) {
            buttons.forEach(function (button) {
                const selected = button.dataset.dendrilaSimpleEditProductTab === key;
                button.setAttribute('aria-selected', selected ? 'true' : 'false');
                button.tabIndex = selected ? 0 : -1;
                if (selected && focus) button.focus();
            });
            panels.forEach(function (panel) {
                panel.hidden = panel.dataset.dendrilaSimpleEditProductPanel !== key;
            });
        }
        buttons.forEach(function (button, index) {
            const key = button.dataset.dendrilaSimpleEditProductTab;
            button.setAttribute('role', 'tab');
            button.id = prefix + '-tab-' + key;
            const panel = panels.find(function (item) { return item.dataset.dendrilaSimpleEditProductPanel === key; });
            if (panel) { panel.id = prefix + '-section-' + key; panel.setAttribute('role', 'tabpanel'); panel.setAttribute('aria-labelledby', button.id); button.setAttribute('aria-controls', panel.id); }
            button.addEventListener('click', function () { activate(key, false); });
            button.addEventListener('keydown', function (event) {
                let next = index;
                if (event.key === 'ArrowRight') next = (index + 1) % buttons.length;
                else if (event.key === 'ArrowLeft') next = (index + buttons.length - 1) % buttons.length;
                else if (event.key === 'Home') next = 0;
                else if (event.key === 'End') next = buttons.length - 1;
                else return;
                event.preventDefault(); activate(buttons[next].dataset.dendrilaSimpleEditProductTab, true);
            });
        });
        const nav = group.querySelector('.dendrila-simple-edit-product-subnav');
        if (nav) nav.setAttribute('role', 'tablist');
        activate(buttons[0].dataset.dendrilaSimpleEditProductTab, false);
        const form = group.closest('form');
        if (form) form.addEventListener('invalid', function (event) {
            const panel = event.target.closest('[data-dendrila-simple-edit-product-panel]');
            if (panel && panel.hidden) activate(panel.dataset.dendrilaSimpleEditProductPanel, false);
        }, true);
    }
    function init() { document.querySelectorAll('[data-dendrila-simple-edit-product-tabs]').forEach(setupTabs); }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init, {once:true});
    else init();
}());

/* Mobile layout, newsletter template steps and WooCommerce contextual panels. */
(function () {
    'use strict';
    function init() {
        document.querySelectorAll('.dendrila-simple-edit-newsletter-item-details').forEach(function (details) {
            var bar=details.closest('.dendrila-simple-edit-newsletter-item-bar');
            if (!bar) {return;}
            function sync() {bar.classList.toggle('is-open',details.open);}
            details.addEventListener('toggle',sync);
            sync();
        });
        document.querySelectorAll('[data-dendrila-simple-edit-template-step]').forEach(function(step){
            var parent=step.closest('form');
            if(!parent){return;}
            var compose=parent.querySelector('[data-dendrila-simple-edit-template-editor]');
            var next=step.querySelector('[data-dendrila-simple-edit-template-next]');
            if(next && compose){
                next.addEventListener('click',function(){
                    compose.hidden=false;
                    step.classList.add('is-finished');
                    next.hidden=true;
                    var subject=compose.querySelector('input[name="dendrila_simple_edit_nsubject"]');
                    if(subject){subject.focus({preventScroll:true});subject.scrollIntoView({behavior:'smooth',block:'center'});}
                });
            }
            function syncColors(){
                step.querySelectorAll('input[type="color"]').forEach(function(input){
                    var key=(input.name.match(/\[([^\]]+)\]$/)||[])[1];
                    if(key){step.style.setProperty('--template-'+key,input.value);parent.style.setProperty('--template-'+key,input.value);}
                });
            }
            step.addEventListener('input',syncColors);
            syncColors();
        });
        document.querySelectorAll('[data-dendrila-simple-edit-product-tabs]').forEach(function(group){
            var form=group.closest('form');
            if(!form){return;}
            var type=form.querySelector('[data-dendrila-simple-edit-type-select]');
            var stock=form.querySelector('input[name="dendrila_simple_edit_pfields[manage_stock]"][type="checkbox"]');
            function refresh(){
                var value=type?type.value:(form.querySelector('[data-dendrila-simple-edit-current-type]')?.value||'simple');
                form.querySelectorAll('[data-dendrila-simple-edit-for-type]').forEach(function(panel){
                    panel.hidden=panel.dataset.dendrilaSimpleEditForType!==value;
                    panel.querySelectorAll('input,select,textarea').forEach(function(el){el.disabled=panel.hidden;});
                });
                var manage=!!stock && stock.checked;
                form.querySelectorAll('[data-dendrila-simple-edit-stock-managed]').forEach(function(el){
                    el.hidden=!manage;
                    el.querySelectorAll('input,select').forEach(function(input){input.disabled=!manage;});
                });
                form.querySelectorAll('[data-dendrila-simple-edit-stock-simple]').forEach(function(el){
                    el.hidden=manage;
                    el.querySelectorAll('input,select').forEach(function(input){input.disabled=manage;});
                });
            }
            if(type){type.addEventListener('change',refresh);}
            if(stock){stock.addEventListener('change',refresh);}
            form.addEventListener('invalid',function(event){
                var panel=event.target.closest('[data-dendrila-simple-edit-product-panel]');
                if(!panel){return;}
                var key=panel.dataset.dendrilaSimpleEditProductPanel;
                var tab=group.querySelector('[data-dendrila-simple-edit-product-tab="'+key+'"]');
                if(tab && !tab.hidden && !panel.hidden){return;}
                if(tab){tab.click();}
            },true);
            refresh();
        });
        document.querySelectorAll('[data-dendrila-simple-edit-select-all]').forEach(function(btn){
            btn.addEventListener('click',function(){
                btn.closest('fieldset').querySelectorAll('input[type="checkbox"]').forEach(function(el){el.checked=true;});
            });
        });
        document.querySelectorAll('[data-dendrila-simple-edit-select-none]').forEach(function(btn){
            btn.addEventListener('click',function(){
                btn.closest('fieldset').querySelectorAll('input[type="checkbox"]').forEach(function(el){el.checked=false;});
            });
        });
    }
    if(document.readyState==='loading'){document.addEventListener('DOMContentLoaded',init,{once:true});}else{init();}
}());
