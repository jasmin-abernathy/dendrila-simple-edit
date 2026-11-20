(function () {
    'use strict';

    var builder = document.querySelector('[data-dse-newsletter-builder]');
    if (!builder) { return; }
    var source = builder.querySelector('textarea');
    var labels = {title: 'Titre principal', heading: 'Intertitre', text: 'Texte',
        image: 'Image', button: 'Bouton', link: 'Lien', legacy: 'Contenu existant'};
    var colors = {default: 'Par défaut', green: 'Vert', blue: 'Bleu',
        plum: 'Prune', brick: 'Brique', slate: 'Ardoise'};
    var dragged = null;
    var serial = 0;

    function element(tag, text, classes) {
        var node = document.createElement(tag);
        if (text) { node.textContent = text; }
        if (classes) { node.className = classes; }
        return node;
    }

    function button(text, callback, classes) {
        var node = element('button', text, classes || 'button');
        node.type = 'button';
        node.addEventListener('click', callback);
        return node;
    }

    var palette = element('div', '', 'dse-block-palette');
    var canvas = element('div', '', 'dse-block-canvas');
    var empty = element('p', 'Glissez un élément ici ou choisissez-le ci-dessus.', 'dse-block-empty');
    var status = element('p', '', 'screen-reader-text');
    status.setAttribute('role', 'status');
    var titleButton;

    function refresh(message) {
        var blocks = Array.from(canvas.children).filter(function (child) { return child !== empty; });
        empty.hidden = blocks.length > 0;
        titleButton.disabled = Boolean(canvas.querySelector('[data-block="title"]'));
        blocks.forEach(function (block, index) {
            block.querySelector('[data-up]').disabled = index === 0;
            block.querySelector('[data-down]').disabled = index === blocks.length - 1;
        });
        status.textContent = message || '';
    }

    function field(parent, name, value, multiline, type) {
        var label = element('label', name);
        var input = element(multiline ? 'textarea' : 'input');
        input.id = 'dse-block-field-' + (++serial);
        label.htmlFor = input.id;
        if (multiline) { input.rows = 4; } else { input.type = type || 'text'; }
        input.value = value || '';
        parent.appendChild(label);
        parent.appendChild(input);
        return input;
    }

    function block(type, values, before) {
        if (!Object.prototype.hasOwnProperty.call(labels, type) ||
            (type === 'title' && canvas.querySelector('[data-block="title"]'))) { return; }
        values = values || {};
        var item = element('article', '', 'dse-newsletter-block');
        item.dataset.block = type;
        var bar = element('div', '', 'dse-block-bar');
        var grip = element('span', '⠿', 'dse-block-grip');
        grip.draggable = true;
        grip.setAttribute('aria-hidden', 'true');
        grip.addEventListener('dragstart', function (event) {
            dragged = item;
            event.dataTransfer.setData('text/plain', type);
        });
        grip.addEventListener('dragend', function () { dragged = null; });
        var controls = element('div', '', 'dse-block-controls');
        var up = button('↑', function () {
            var previous = item.previousElementSibling;
            if (previous && previous !== empty) { canvas.insertBefore(item, previous); refresh('Bloc remonté.'); item.querySelector('input, textarea').focus(); }
        });
        up.dataset.up = '';
        up.setAttribute('aria-label', 'Monter le bloc ' + labels[type]);
        var down = button('↓', function () {
            var next = item.nextElementSibling;
            if (next) { canvas.insertBefore(next, item); refresh('Bloc descendu.'); item.querySelector('input, textarea').focus(); }
        });
        down.dataset.down = '';
        down.setAttribute('aria-label', 'Descendre le bloc ' + labels[type]);
        controls.append(up, down, button('Retirer', function () {
            item.remove();
            refresh('Bloc retiré.');
            palette.querySelector('[data-add="' + (type === 'legacy' ? 'text' : type) + '"]').focus();
        }));
        bar.append(grip, element('strong', labels[type]), controls);
        item.appendChild(bar);
        var fields = element('div', '', 'dse-block-fields');
        item.appendChild(fields);
        if (type === 'image') {
            var imageUrl = field(fields, 'Adresse de l’image', values.url, false, 'url');
            field(fields, 'Texte alternatif', values.text);
            if (window.wp && window.wp.media) {
                fields.appendChild(button('Choisir dans la médiathèque', function () {
                    var media = window.wp.media({title: 'Choisir une image', library: {type: 'image'}, multiple: false});
                    media.on('select', function () {
                        var image = media.state().get('selection').first().toJSON();
                        imageUrl.value = image.url;
                        fields.querySelectorAll('input')[1].value = image.alt || '';
                    });
                    media.open();
                }));
            }
        } else {
            field(fields, type === 'legacy' ? 'HTML du brouillon précédent' : 'Contenu', values.text,
                type === 'text' || type === 'legacy');
            if (type === 'button' || type === 'link') { field(fields, 'Adresse du lien', values.url, false, 'url'); }
        }
        if (type !== 'legacy') {
            var color = element('select');
            color.dataset.blockColor = '';
            color.id = 'dse-block-field-' + (++serial);
            var colorLabel = element('label', type === 'image' ? 'Couleur du cadre' : 'Couleur');
            colorLabel.htmlFor = color.id;
            Object.keys(colors).forEach(function (key) {
                var option = element('option', colors[key]);
                option.value = key;
                color.appendChild(option);
            });
            color.value = Object.prototype.hasOwnProperty.call(colors, values.color) ? values.color : 'default';
            item.dataset.color = color.value;
            color.addEventListener('change', function () { item.dataset.color = color.value; });
            var colorRow = element('div', '', 'dse-block-color');
            colorRow.append(element('span', '', 'dse-block-swatch'), colorLabel, color);
            fields.appendChild(colorRow);
        }
        canvas.insertBefore(item, before || null);
        return item;
    }

    Object.keys(labels).filter(function (type) { return type !== 'legacy'; }).forEach(function (type) {
        var add = button('+ ' + labels[type], function () {
            var item = block(type);
            if (item) {
                refresh('Bloc ajouté.');
                item.querySelector('input, textarea').focus();
            }
        }, 'button dse-add-block');
        add.dataset.add = type;
        add.draggable = true;
        add.addEventListener('dragstart', function (event) {
            dragged = type;
            event.dataTransfer.setData('text/plain', type);
        });
        add.addEventListener('dragend', function () { dragged = null; });
        if (type === 'title') { titleButton = add; }
        palette.appendChild(add);
    });

    canvas.addEventListener('dragover', function (event) {
        if (dragged) { event.preventDefault(); }
    });
    canvas.addEventListener('drop', function (event) {
        if (!dragged) { return; }
        event.preventDefault();
        var target = event.target.closest('[data-block]');
        if (target && event.clientY > target.getBoundingClientRect().top + target.offsetHeight / 2) {
            target = target.nextElementSibling;
        }
        if (typeof dragged === 'string') { block(dragged, {}, target); }
        else if (target !== dragged) { canvas.insertBefore(dragged, target || null); }
        dragged = null;
        refresh('Ordre des blocs mis à jour.');
    });

    var stored = element('template');
    stored.innerHTML = source.value;
    var legacy = element('div');
    function flushLegacy() {
        if (legacy.innerHTML.trim()) { block('legacy', {text: legacy.innerHTML}); }
        legacy.replaceChildren();
    }
    Array.from(stored.content.childNodes).forEach(function (node) {
        var type = node.nodeType === 1 ? node.dataset.dseBlock : '';
        if (!type || type === 'legacy' || !Object.prototype.hasOwnProperty.call(labels, type)) {
            legacy.appendChild(node.cloneNode(true));
            return;
        }
        flushLegacy();
        var content = node.firstElementChild;
        if (!content) { return; }
        if (type === 'text') {
            Array.from(content.querySelectorAll('br')).forEach(function (lineBreak) {
                lineBreak.replaceWith(document.createTextNode('\n'));
            });
        }
        block(type, {text: type === 'image' ? content.getAttribute('alt') : content.textContent,
            url: content.getAttribute(type === 'image' ? 'src' : 'href'), color: content.dataset.dseColor});
    });
    flushLegacy();

    builder.closest('form').addEventListener('submit', function () {
        source.value = Array.from(canvas.querySelectorAll('[data-block]')).map(function (item) {
            var type = item.dataset.block;
            var fields = item.querySelectorAll('input, textarea');
            var text = fields[0].value;
            var other = fields[1] ? fields[1].value : '';
            if (type === 'legacy') { return text; }
            var wrapper = element('div');
            wrapper.dataset.dseBlock = type;
            var tags = {title: 'h1', heading: 'h2', text: 'p', image: 'img', button: 'a', link: 'a'};
            var content = element(tags[type], type === 'image' ? '' : text);
            if (type === 'text') {
                content.replaceChildren();
                text.split('\n').forEach(function (line, index) {
                    if (index) { content.appendChild(element('br')); }
                    content.appendChild(document.createTextNode(line));
                });
            } else if (type === 'image') {
                content.setAttribute('src', text);
                content.alt = other;
            } else if (type === 'link' || type === 'button') {
                content.setAttribute('href', other);
                if (type === 'button') { content.className = 'dse-newsletter-cta'; }
            }
            var color = item.querySelector('[data-block-color]').value;
            if (color !== 'default') { content.dataset.dseColor = color; }
            wrapper.appendChild(content);
            return wrapper.outerHTML;
        }).join('\n');
    });

    source.hidden = true;
    builder.querySelector('label').hidden = true;
    canvas.prepend(empty);
    builder.append(element('h4', 'Composer la newsletter'), palette, canvas, status);
    refresh();
}());

