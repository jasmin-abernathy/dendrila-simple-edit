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

    function init() {
        revealSelectedTab();
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
