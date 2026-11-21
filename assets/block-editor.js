/* Dendrila Simple Edit — one visual block editor for newsletters, pages and articles.
 * All save actions remain explicit. No email is sent from this script. */
(function () {
    'use strict';
    var types = {
        title:   {label: 'Titre principal', icon: 'heading', hint: 'Le titre qui ouvre le message'},
        heading: {label: 'Intertitre', icon: 'editor-textcolor', hint: 'Pour séparer deux parties'},
        text:    {label: 'Texte', icon: 'editor-paragraph', hint: 'Un ou plusieurs paragraphes'},
        image:   {label: 'Image', icon: 'format-image', hint: 'Photo ou illustration'},
        button:  {label: 'Bouton', icon: 'arrow-right-alt2', hint: 'Un lien bien visible'},
        link:    {label: 'Lien', icon: 'admin-links', hint: 'Un lien dans le texte'}
    };
    var colors = [
        {key: 'default', name: 'Automatique', value: ''},
        {key: 'green', name: 'Vert', value: '#24543b'},
        {key: 'blue', name: 'Bleu', value: '#245185'},
        {key: 'plum', name: 'Prune', value: '#713e6d'},
        {key: 'brick', name: 'Brique', value: '#8c3832'},
        {key: 'slate', name: 'Ardoise', value: '#394758'}
    ];
    var names = {};
    colors.forEach(function (c) {names[c.key] = c.value;});
    function node(tag, label, className) {
        var el = document.createElement(tag);
        if (label) {el.textContent = label;}
        if (className) {el.className = className;}
        return el;
    }
    function control(label, handler, className) {
        var el = node('button', label, className || 'button');
        el.type = 'button'; el.addEventListener('click', handler); return el;
    }
    function colorValue(code) {
        if (Object.prototype.hasOwnProperty.call(names, code)) {return names[code];}
        if (/^#(?:[a-f0-9]{3}|[a-f0-9]{6})$/i.test(code || '')) {return code;}
        return '';
    }
    function editor(root) {
        var source = root.querySelector('[data-dse-editor-source]');
        if (!source) {return;}
        var form = root.closest('form');
        if (!form) {return;}
        var allowTitle = root.getAttribute('data-allow-title') !== '0';
        var addBar = node('div', '', 'dse-block-palette');
        addBar.setAttribute('aria-label', 'Ajouter un élément');
        var canvas = node('div', '', 'dse-block-canvas');
        var empty = node('p', 'Choisissez un élément ci-dessus pour commencer.', 'dse-block-empty');
        var status = node('p', '', 'screen-reader-text');
        status.setAttribute('role', 'status');
        var previewPanel = node('section', '', 'dse-live-preview');
        var previewBody = node('div', '', 'dse-live-preview-body');
        previewPanel.append(node('h4', 'Aperçu du contenu'), previewBody);
        var titleButton = null, moving = null, serial = 0;
        canvas.appendChild(empty);
        function blocks() {return Array.from(canvas.querySelectorAll(':scope > [data-block]'));}
        function repaint(message) {
            var all = blocks();
            empty.hidden = all.length !== 0;
            if (titleButton) {titleButton.disabled = all.some(function (b) {return b.dataset.block === 'title';});}
            all.forEach(function (b, i) {
                b.querySelector('[data-up]').disabled = i === 0;
                b.querySelector('[data-down]').disabled = i === all.length - 1;
            });
            status.textContent = message || '';
            if (previewPanel.isConnected) { refreshPreview(); }
        }
        function inputField(parent, text, value, isArea, inputType) {
            var label = node('label', text);
            var el = node(isArea ? 'textarea' : 'input');
            var id = 'dse-editor-field-' + (++serial);
            el.id = id; label.htmlFor = id;
            if (isArea) {el.rows = 4;} else {el.type = inputType || 'text';}
            el.value = value || '';
            parent.append(label, el);
            return el;
        }
        function addBlock(type, values, before) {
            if (!types[type] || (type === 'title' && (!allowTitle || blocks().some(function (b) {return b.dataset.block === 'title';})))) {return null;}
            values = values || {};
            var box = node('article', '', 'dse-newsletter-block');
            box.dataset.block = type;
            var bar = node('div', '', 'dse-block-bar');
            var grip = node('span', '⠿', 'dse-block-grip');
            grip.draggable = true;
            grip.setAttribute('aria-hidden', 'true');
            grip.addEventListener('dragstart', function (event) {
                moving = box;
                event.dataTransfer.setData('text/plain', type);
            });
            grip.addEventListener('dragend', function () {moving = null;});
            var controls = node('div', '', 'dse-block-controls');
            var up = control('↑', function () {
                var sibling = box.previousElementSibling;
                if (sibling && sibling.dataset.block) {canvas.insertBefore(box, sibling); repaint('Élément remonté.');}
            });
            up.dataset.up = ''; up.setAttribute('aria-label', 'Monter : ' + types[type].label);
            var down = control('↓', function () {
                var sibling = box.nextElementSibling;
                if (sibling && sibling.dataset.block) {canvas.insertBefore(sibling, box); repaint('Élément descendu.');}
            });
            down.dataset.down = ''; down.setAttribute('aria-label', 'Descendre : ' + types[type].label);
            controls.append(up, down, control('Retirer', function () {box.remove(); repaint('Élément retiré.');}));
            bar.append(grip, node('strong', types[type].label), controls);
            box.appendChild(bar);
            var fields = node('div', '', 'dse-block-fields');
            box.appendChild(fields);
            var value, other;
            if (type === 'image') {
                value = inputField(fields, 'Adresse de l’image', values.url, false, 'url');
                other = inputField(fields, 'Description de l’image', values.text, false);
                if (window.wp && window.wp.media) {
                    fields.appendChild(control('Choisir dans la médiathèque', function () {
                        var media = window.wp.media({title: 'Choisir une image', library: {type: 'image'}, multiple: false});
                        media.on('select', function () {
                            var selected = media.state().get('selection').first();
                            if (!selected) {return;}
                            var img = selected.toJSON();
                            value.value = img.url || '';
                            other.value = img.alt || '';
                            updatePreview();
                        });
                        media.open();
                    }));
                }
            } else {
                value = inputField(fields, 'Contenu', values.text, type === 'text');
                if (type === 'button' || type === 'link') {other = inputField(fields, 'Adresse du lien', values.url, false, 'url');}
            }
            box.dataset.color = values.color && (names[values.color] !== undefined || colorValue(values.color)) ? values.color : 'default';
            var colorWrap = node('fieldset', '', 'dse-block-color');
            var legend = node('legend', type === 'image' ? 'Couleur du cadre' : 'Couleur du texte ou du bouton');
            var palette = node('div', '', 'dse-color-palette');
            colorWrap.append(legend, palette);
            var pickerLabel = node('label', 'Personnalisée :', 'dse-color-custom-label');
            var picker = node('input');
            picker.type = 'color';
            picker.value = /^#[a-f0-9]{6}$/i.test(box.dataset.color) ? box.dataset.color : '#3b82f6';
            picker.setAttribute('aria-label', 'Choisir une couleur personnalisée');
            pickerLabel.appendChild(picker);
            var colorStatus = node('span', '', 'dse-color-label');
            colorStatus.setAttribute('aria-live', 'polite');
            function updateColor() {
                var chosen = colorValue(box.dataset.color);
                box.style.setProperty('--dse-block-ink', chosen || '#23462a');
                colorStatus.textContent = box.dataset.color === 'default' ? 'Couleur automatique' :
                    (colors.find(function (c) {return c.key === box.dataset.color;}) || {}).name || chosen;
                Array.from(palette.children).forEach(function (b) {
                    b.setAttribute('aria-pressed', b.dataset.value === box.dataset.color ? 'true' : 'false');
                });
                pickerLabel.classList.toggle('is-selected', /^#/.test(box.dataset.color));
                updatePreview();
            }
            colors.forEach(function (c) {
                var swatch = control('', function () {box.dataset.color = c.key; updateColor();}, 'dse-color-choice');
                swatch.dataset.value = c.key;
                swatch.title = c.name;
                swatch.setAttribute('aria-label', c.name);
                swatch.setAttribute('aria-pressed', 'false');
                if (c.value) {swatch.style.backgroundColor = c.value;} else {swatch.classList.add('dse-color-default');}
                palette.appendChild(swatch);
            });
            picker.addEventListener('input', function () {box.dataset.color = picker.value; updateColor();});
            colorWrap.append(pickerLabel, colorStatus);
            fields.appendChild(colorWrap);
            var preview = node('div', '', 'dse-block-preview');
            preview.setAttribute('aria-label', 'Aperçu de cet élément');
            fields.appendChild(preview);
            function updatePreview() {
                while (preview.firstChild) {preview.removeChild(preview.firstChild);}
                var v = value.value || types[type].hint;
                var display = type === 'image' ? node('span', 'Aperçu du cadre de l’image') : node('span', v.slice(0, 100));
                preview.appendChild(display);
                if (type === 'button') {display.classList.add('dse-preview-button');}
                if (type === 'heading' || type === 'title') {display.classList.add('dse-preview-heading');}
                if (type === 'image') {display.classList.add('dse-preview-image');}
                preview.style.setProperty('--dse-preview-color', colorValue(box.dataset.color) || '#24543b');
                if (previewPanel.isConnected) { refreshPreview(); }
            }
            value.addEventListener('input', updatePreview);
            if (other) { other.addEventListener('input', updatePreview); }
            updateColor();
            canvas.insertBefore(box, before || null);
            repaint();
            return box;
        }
        function initArticleImport() {
            var wizard = root.querySelector('[data-dse-article-import]');
            if (!wizard) {return;}
            var selector = wizard.querySelector('[data-dse-article-select]');
            var label = wizard.querySelector('[data-dse-article-label]');
            var insertButton = wizard.querySelector('[data-dse-article-insert]');
            var preview = wizard.querySelector('[data-dse-article-preview]');
            var previewTitle = wizard.querySelector('[data-dse-article-preview-title]');
            var previewText = wizard.querySelector('[data-dse-article-preview-text]');
            var previewAction = wizard.querySelector('[data-dse-article-preview-action]');
            var feedback = wizard.querySelector('[data-dse-article-feedback]');
            var choice = wizard.querySelectorAll('input[type="radio"]');
            if (!selector || !label || !insertButton || !preview || !feedback || !previewTitle ||
                !previewText || !previewAction || !choice.length) {return;}
            function selectedArticle() {
                var option = selector.options[selector.selectedIndex];
                if (!option || !option.value) {return null;}
                var title = option.getAttribute('data-dse-article-title') || '';
                var paragraph = option.getAttribute('data-dse-article-paragraph') || '';
                var url = option.getAttribute('data-dse-article-url') || '';
                if (!title.trim() || !paragraph.trim() || !/^https?:\/\/[^ ]+$/i.test(url)) {
                    return null;
                }
                return {title: title, paragraph: paragraph, url: url};
            }
            function linkType() {
                return Array.from(choice).some(function (radio) {
                    return radio.checked && radio.value === 'link';
                }) ? 'link' : 'button';
            }
            function linkLabel() {
                return label.value.trim() || 'Lire la suite';
            }
            function refreshWizard() {
                var article = selectedArticle();
                insertButton.disabled = !article;
                preview.hidden = !article;
                if (article) {
                    previewTitle.textContent = article.title;
                    previewText.textContent = article.paragraph;
                    previewAction.textContent = linkLabel();
                    previewAction.className = linkType() === 'button' ?
                        'dse-example-action' : 'dse-example-link-text';
                }
                feedback.textContent = '';
            }
            selector.addEventListener('change', refreshWizard);
            label.addEventListener('input', refreshWizard);
            Array.from(choice).forEach(function (radio) {
                radio.addEventListener('change', refreshWizard);
            });
            insertButton.addEventListener('click', function () {
                var article = selectedArticle();
                if (!article) {return;}
                // Three ordinary, independent blocks: no article ID or source reference
                // is stored in the newsletter, and the published article is never changed.
                var heading = addBlock('heading', {text: article.title});
                var paragraph = addBlock('text', {text: article.paragraph});
                var action = addBlock(linkType(), {text: linkLabel(), url: article.url});
                if (!heading || !paragraph || !action) {
                    feedback.textContent = 'L’insertion n’a pas pu être terminée. Vérifiez le contenu.';
                    return;
                }
                repaint('Article inséré dans la newsletter.');
                feedback.textContent = 'Article ajouté. Vous pouvez maintenant modifier chaque élément dans la newsletter, sans changer l’article original.';
                var input = heading.querySelector('.dse-block-fields input');
                if (input) {input.focus();}
            });
            wizard.hidden = false;
            if (selector.options.length <= 1) {
                feedback.textContent = 'Aucun article publié avec un paragraphe n’est disponible pour le moment.';
            }
            refreshWizard();
        }
        Object.keys(types).forEach(function (type) {
            if (type === 'title' && !allowTitle) {return;}
            var b = control('', function () {
                var result = addBlock(type);
                if (result) {
                    repaint(types[type].label + ' ajouté.');
                    var input = result.querySelector('input,textarea');
                    if (input) {input.focus();}
                }
            }, 'dse-add-block');
            b.dataset.add = type;
            b.draggable = true;
            b.addEventListener('dragstart', function (event) {moving = type; event.dataTransfer.setData('text/plain', type);});
            b.addEventListener('dragend', function () {moving = null;});
            b.setAttribute('aria-label', 'Ajouter : ' + types[type].label);
            // A miniature of the actual output is clearer than 'Aa' or technical heading codes.
            var visual = node('span', '', 'dse-add-block-icon dse-example-' + type);
            visual.setAttribute('aria-hidden', 'true');
            if (type === 'title') {
                visual.appendChild(node('span', 'Grand titre', 'dse-example-main-title'));
            } else if (type === 'heading') {
                visual.appendChild(node('span', 'Titre de section', 'dse-example-section-title'));
                visual.appendChild(node('span', '', 'dse-example-rule'));
            } else if (type === 'text') {
                visual.appendChild(node('span', '', 'dse-example-lines'));
            } else if (type === 'image') {
                var landscape = node('span', '', 'dse-example-landscape');
                landscape.appendChild(node('span', '', 'dse-example-sun'));
                visual.appendChild(landscape);
            } else if (type === 'button') {
                visual.appendChild(node('span', 'Découvrir', 'dse-example-action'));
            } else if (type === 'link') {
                visual.appendChild(node('span', 'En savoir plus', 'dse-example-link-text'));
            }
            b.append(visual, node('strong', types[type].label), node('small', types[type].hint));
            if (type === 'title') {titleButton = b;}
            addBar.appendChild(b);
        });
        canvas.addEventListener('dragover', function (event) {if (moving) {event.preventDefault();}});
        canvas.addEventListener('drop', function (event) {
            if (!moving) {return;}
            event.preventDefault();
            var target = event.target.closest('[data-block]');
            if (target && event.clientY > target.getBoundingClientRect().top + target.offsetHeight / 2) {target = target.nextElementSibling;}
            if (typeof moving === 'string') {addBlock(moving, {}, target);}
            else if (target !== moving) {canvas.insertBefore(moving, target || null);}
            moving = null; repaint('Ordre mis à jour.');
        });
        var holder = node('template'); holder.innerHTML = source.value;
        // Never silently flatten layouts or rich HTML from an earlier editor.
        // An existing unsupported document remains editable in its original form.
        var incompatible = Array.from(holder.content.childNodes).some(function (child) {
            if (child.nodeType === 3) {return false;}
            if (child.nodeType !== 1) {return true;}
            if (child.hasAttribute('data-dse-block')) {
                var kind = child.getAttribute('data-dse-block');
                var expected = {title: 'H1', heading: 'H2', text: 'P', image: 'IMG', button: 'A', link: 'A'};
                var inner = child.firstElementChild;
                return !inner || child.children.length !== 1 || expected[kind] !== inner.tagName ||
                    Array.from(inner.querySelectorAll('*')).some(function (tag) {return tag.tagName !== 'BR';});
            }
            if (!/^(P|H1|H2|IMG)$/.test(child.tagName)) {return true;}
            if (Array.from(child.querySelectorAll('*')).some(function (tag) {return tag.tagName !== 'BR';})) {return true;}
            return false;
        });
        if (incompatible) {
            var warning = node('p', 'Ce contenu utilise une ancienne mise en forme. Il reste modifiable dans le champ ci-dessous, sans conversion automatique.', 'notice notice-warning');
            root.insertBefore(warning, source);
            return;
        }
        var legacy = [];
        function flushLegacy() {
            if (!legacy.length) {return;}
            var html = legacy.join('').trim(); legacy = [];
            if (!html) {return;}
            // An imported post may contain HTML that cannot be reconstructed safely.
            // Its access is filtered server-side before reaching this editor.
            var tmp = node('div');tmp.innerHTML = html;
            addBlock('text', {text: tmp.textContent.trim()});
        }
        Array.from(holder.content.childNodes).forEach(function (child) {
            if (child.nodeType === 3 && !child.textContent.trim()) {return;}
            var kind = child.nodeType === 1 ? child.getAttribute('data-dse-block') : null;
            if (kind && types[kind]) {
                flushLegacy();
                var inner = child.firstElementChild;
                if (!inner) {return;}
                var body = inner.textContent || '';
                if (kind === 'text') {
                    var breaks = inner.querySelectorAll('br');
                    breaks.forEach(function (b) {b.replaceWith(document.createTextNode('\n'));});
                    body = inner.textContent || '';
                }
                addBlock(kind, {text: kind === 'image' ? inner.getAttribute('alt') : body,
                    url: inner.getAttribute(kind === 'image' ? 'src' : 'href'), color: inner.getAttribute('data-dse-color') || 'default'});
            } else if (child.nodeType === 1 && /^(P|H1|H2|IMG)$/.test(child.tagName)) {
                flushLegacy();
                var kind2 = child.tagName === 'H1' ? 'title' : child.tagName === 'H2' ? 'heading' : child.tagName === 'IMG' ? 'image' : 'text';
                if (kind2 === 'title' && !allowTitle) {kind2 = 'heading';}
                child.querySelectorAll('br').forEach(function (br) {br.replaceWith(document.createTextNode('\n'));});
                addBlock(kind2, {text: child.tagName === 'IMG' ? child.getAttribute('alt') : child.textContent,
                    url: child.tagName === 'IMG' ? child.getAttribute('src') : '', color: child.getAttribute('data-dse-color') || 'default'});
            } else {legacy.push(child.nodeType === 1 ? child.outerHTML : child.textContent);}
        });
        flushLegacy();
        function compose() {
            return blocks().map(function (item) {
                var type = item.dataset.block, fields = item.querySelectorAll('.dse-block-fields > input, .dse-block-fields > textarea');
                if (!fields.length) {return null;}
                var content = fields[0].value || '', other = fields[1] ? fields[1].value : '';
                var wrap = node('div');wrap.dataset.dseBlock = type;
                var tag = {title: 'h1', heading: 'h2', text: 'p', image: 'img', button: 'a', link: 'a'}[type];
                var inner = node(tag, type === 'image' ? '' : content);
                if (type === 'text') {
                    inner.replaceChildren();
                    content.split('\n').forEach(function (line, idx) {if (idx) {inner.appendChild(node('br'));}inner.appendChild(document.createTextNode(line));});
                } else if (type === 'image') {inner.setAttribute('src', content);inner.alt = other;}
                else if (type === 'link' || type === 'button') {
                    inner.setAttribute('href', other);
                    if (type === 'button') {inner.className = 'dse-newsletter-cta';}
                }
                if (item.dataset.color !== 'default') {inner.dataset.dseColor = item.dataset.color;}
                wrap.appendChild(inner);
                return wrap;
            }).filter(Boolean);
        }

        function refreshPreview() {
            var fragment = document.createDocumentFragment();
            compose().forEach(function (wrap) {
                var content = wrap.firstElementChild;
                var color = colorValue(content.dataset.dseColor);
                if (content.classList.contains('dse-newsletter-cta')) {
                    var background = color || '#24543b';
                    var probe = background.slice(1);
                    if (probe.length === 3) { probe = probe.split('').map(function (c) { return c + c; }).join(''); }
                    var channels = probe.match(/../g).map(function (c) {
                        var channel = parseInt(c, 16) / 255;
                        return channel <= 0.04045 ? channel / 12.92 : Math.pow((channel + 0.055) / 1.055, 2.4);
                    });
                    content.style.backgroundColor = background;
                    content.style.color = 0.2126 * channels[0] + 0.7152 * channels[1] + 0.0722 * channels[2] > 0.179 ? '#171717' : '#fff';
                } else if (content.tagName === 'IMG') {
                    var url = content.getAttribute('src');
                    if (!/^https?:\/\//i.test(url)) { return; }
                    content.referrerPolicy = 'no-referrer';
                    if (color) { content.style.borderColor = color; content.classList.add('dse-preview-image-frame'); }
                } else if (color) {
                    content.style.color = color;
                }
                if (content.tagName === 'A') {content.removeAttribute('href');}
                if (content.tagName !== 'IMG' && !content.textContent.trim()) {return;}
                fragment.appendChild(wrap);
            });
            if (!fragment.childNodes.length) {fragment.appendChild(node('p', 'Le contenu apparaîtra ici pendant la saisie.'));}
            previewBody.replaceChildren(fragment);
        }

        form.addEventListener('submit', function () {
            source.value = compose().map(function (wrap) { return wrap.outerHTML; }).join('\n');
        });
        source.hidden = true;
        var label = root.querySelector('label[for="' + source.id + '"]');
        if (label) {label.hidden = true;}
        root.append(node('h4', 'Ajouter un élément', 'dse-builder-heading'), addBar, canvas, status, previewPanel);
        initArticleImport();
        repaint();
    }
    function init() {document.querySelectorAll('[data-dse-block-editor]').forEach(editor);}
    if (document.readyState === 'loading') {document.addEventListener('DOMContentLoaded', init, {once: true});}
    else {init();}
}());
