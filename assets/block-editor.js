/* Dendrila Simple Edit — one visual block editor for newsletters, pages and articles.
 * All save actions remain explicit. No email is sent from this script. */
(function () {
    'use strict';
    var dendrilaI18n = window.dendrilaSimpleEditStrings || {};
    function t(phrase) { return Object.prototype.hasOwnProperty.call(dendrilaI18n, phrase) ? dendrilaI18n[phrase] : phrase; }
    var types = {
        title:   {label: t('Titre principal'), icon: 'heading', hint: t('Le titre qui ouvre le message')},
        heading: {label: t('Intertitre'), icon: 'editor-textcolor', hint: t('Pour séparer deux parties')},
        text:    {label: t('Texte'), icon: 'editor-paragraph', hint: t('Un ou plusieurs paragraphes')},
        image:   {label: t('Image'), icon: 'format-image', hint: t('Photo ou illustration')},
        button:  {label: t('Bouton'), icon: 'arrow-right-alt2', hint: t('Un lien bien visible')},
        link:    {label: t('Lien'), icon: 'admin-links', hint: t('Un lien dans le texte')}
    };
    var colors = [
        {key: 'default', name: t('Automatique'), value: ''},
        {key: 'green', name: t('Vert'), value: '#24543b'},
        {key: 'blue', name: t('Bleu'), value: '#245185'},
        {key: 'plum', name: t('Prune'), value: '#713e6d'},
        {key: 'brick', name: t('Brique'), value: '#8c3832'},
        {key: 'slate', name: t('Ardoise'), value: '#394758'}
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
    function editor(root, editorIndex) {
        var source = root.querySelector('[data-dendrila-simple-edit-editor-source]');
        if (!source) {return;}
        var form = root.closest('form');
        if (!form) {return;}
        var allowTitle = root.getAttribute('data-allow-title') !== '0';
        var addBar = node('div', '', 'dendrila-simple-edit-block-palette');
        addBar.setAttribute('aria-label', t('Ajouter un élément'));
        var canvas = node('div', '', 'dendrila-simple-edit-block-canvas');
        var empty = node('p', t('Choisissez un élément ci-dessus pour commencer.'), 'dendrila-simple-edit-block-empty');
        var status = node('p', '', 'screen-reader-text');
        status.setAttribute('role', 'status');
        var previewPanel = node('section', '', 'dendrila-simple-edit-live-preview');
        var previewBody = node('div', '', 'dendrila-simple-edit-live-preview-body');
        previewPanel.append(node('h4', t('Aperçu du contenu')), previewBody);
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
            var id = 'dendrila-simple-edit-editor-field-' + editorIndex + '-' + (++serial);
            el.id = id; label.htmlFor = id;
            if (isArea) {el.rows = 4;} else {el.type = inputType || 'text';}
            el.value = value || '';
            parent.append(label, el);
            return el;
        }
        function addBlock(type, values, before) {
            if (!types[type] || (type === 'title' && (!allowTitle || blocks().some(function (b) {return b.dataset.block === 'title';})))) {return null;}
            values = values || {};
            var box = node('article', '', 'dendrila-simple-edit-newsletter-block');
            box.dataset.block = type;
            box.dataset.align = ['left', 'center', 'right'].indexOf(values.align) !== -1 ? values.align : 'left';
            var bar = node('div', '', 'dendrila-simple-edit-block-bar');
            var grip = node('span', '⠿', 'dendrila-simple-edit-block-grip');
            grip.draggable = true;
            grip.setAttribute('aria-hidden', 'true');
            grip.addEventListener('dragstart', function (event) {
                moving = box;
                event.dataTransfer.setData('text/plain', type);
            });
            grip.addEventListener('dragend', function () {moving = null;});
            var controls = node('div', '', 'dendrila-simple-edit-block-controls');
            var up = control('↑', function () {
                var sibling = box.previousElementSibling;
                if (sibling && sibling.dataset.block) {canvas.insertBefore(box, sibling); repaint(t('Élément remonté.'));}
            });
            up.dataset.up = ''; up.setAttribute('aria-label', t('Monter : ') + types[type].label);
            var down = control('↓', function () {
                var sibling = box.nextElementSibling;
                if (sibling && sibling.dataset.block) {canvas.insertBefore(sibling, box); repaint(t('Élément descendu.'));}
            });
            down.dataset.down = ''; down.setAttribute('aria-label', t('Descendre : ') + types[type].label);
            var toggle = control('⌄', function () {
                var nowOpen = fields.hidden;
                fields.hidden = !nowOpen;
                toggle.setAttribute('aria-expanded', nowOpen ? 'true' : 'false');
                toggle.setAttribute('aria-label',(nowOpen ? t('Replier') : t('Déplier')) + ' : ' + types[type].label);
                box.classList.toggle('is-collapsed', !nowOpen);
            }, 'dendrila-simple-edit-block-toggle');
            toggle.setAttribute('aria-label', t('Replier : ') + types[type].label);
            toggle.setAttribute('aria-expanded', 'true');
            controls.append(toggle,up, down, control(t('Retirer'), function () {box.remove(); repaint(t('Élément retiré.'));}));
            var heading = node('strong', types[type].label);
            var summary = node('small', '', 'dendrila-simple-edit-block-summary');
            bar.append(grip, heading, summary, controls);
            box.appendChild(bar);
            var fields = node('div', '', 'dendrila-simple-edit-block-fields');
            box.appendChild(fields);
            // Adding an element collapses older ones; manually opened elements stay independent.
            blocks().forEach(function (other) {
                var fieldset = other.querySelector('.dendrila-simple-edit-block-fields');
                var button = other.querySelector('.dendrila-simple-edit-block-toggle');
                if (fieldset) {fieldset.hidden = true; other.classList.add('is-collapsed');}
                if (button) {button.setAttribute('aria-expanded','false');
                    button.setAttribute('aria-label',t('Déplier le bloc'));}
            });
            var value, other;
            if (type === 'image') {
                value = inputField(fields, t('Adresse de l’image'), values.url, false, 'url');
                other = inputField(fields, t('Description de l’image'), values.text, false);
                if (window.wp && window.wp.media) {
                    fields.appendChild(control(t('Choisir dans la médiathèque'), function () {
                        var media = window.wp.media({title: t('Choisir une image'), library: {type: 'image'}, multiple: false});
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
                value = inputField(fields, t('Contenu'), values.text, type === 'text');
                if (type === 'button' || type === 'link') {other = inputField(fields, t('Adresse du lien'), values.url, false, 'url');}
            }
            box.dataset.color = values.color && (names[values.color] !== undefined || colorValue(values.color)) ? values.color : 'default';
            var colorWrap = node('fieldset', '', 'dendrila-simple-edit-block-color');
            var legend = node('legend', type === 'image' ? t('Couleur du cadre') : t('Couleur du texte ou du bouton'));
            var palette = node('div', '', 'dendrila-simple-edit-color-palette');
            colorWrap.append(legend, palette);
            var pickerLabel = node('label', t('Personnalisée :'), 'dendrila-simple-edit-color-custom-label');
            var picker = node('input');
            picker.type = 'color';
            picker.value = colorValue(box.dataset.color) || '#24543b';
            picker.setAttribute('aria-label', t('Choisir une couleur personnalisée'));
            pickerLabel.appendChild(picker);
            var colorStatus = node('span', '', 'dendrila-simple-edit-color-label');
            colorStatus.setAttribute('aria-live', 'polite');
            function updateColor() {
                var chosen = colorValue(box.dataset.color);
                box.style.setProperty('--dendrila-simple-edit-block-ink', chosen || '#23462a');
                colorStatus.textContent = box.dataset.color === 'default' ? t('Couleur automatique') :
                    (colors.find(function (c) {return c.key === box.dataset.color;}) || {}).name || chosen;
                Array.from(palette.children).forEach(function (b) {
                    b.setAttribute('aria-pressed', b.dataset.value === box.dataset.color ? 'true' : 'false');
                });
                pickerLabel.classList.toggle('is-selected', /^#/.test(box.dataset.color));
                if (!/^#/.test(box.dataset.color)) { picker.value = chosen || '#24543b'; }
                updatePreview();
            }
            colors.forEach(function (c) {
                var swatch = control('', function () {box.dataset.color = c.key; updateColor();}, 'dendrila-simple-edit-color-choice');
                swatch.dataset.value = c.key;
                swatch.title = c.name;
                swatch.setAttribute('aria-label', c.name);
                swatch.setAttribute('aria-pressed', 'false');
                if (c.value) {swatch.style.backgroundColor = c.value;} else {swatch.classList.add('dendrila-simple-edit-color-default');}
                palette.appendChild(swatch);
            });
            picker.addEventListener('input', function () {box.dataset.color = picker.value; updateColor();});
            colorWrap.append(pickerLabel, colorStatus);
            fields.appendChild(colorWrap);
            var alignment = node('fieldset', '', 'dendrila-simple-edit-block-alignment');
            alignment.appendChild(node('legend', t('Alignement de cet élément')));
            var alignGroup = node('div', '', 'dendrila-simple-edit-alignment-options');
            alignGroup.setAttribute('role', 'group');
            alignGroup.setAttribute('aria-label', 'Alignement : ' + types[type].label);
            ['left', 'center', 'right'].forEach(function (option, index) {
                var labels = [t('À gauche'), t('Centré'), t('À droite')];
                var choice = control(labels[index], function () {
                    box.dataset.align = option;
                    Array.from(alignGroup.children).forEach(function (child) {
                        child.setAttribute('aria-pressed', child.dataset.align === option ? 'true' : 'false');
                    });
                    updatePreview();
                }, 'dendrila-simple-edit-align-choice');
                choice.dataset.align = option;
                choice.setAttribute('aria-pressed', option === box.dataset.align ? 'true' : 'false');
                alignGroup.appendChild(choice);
            });
            alignment.appendChild(alignGroup);
            fields.appendChild(alignment);
            var preview = node('div', '', 'dendrila-simple-edit-block-preview');
            preview.setAttribute('aria-label', t('Aperçu de cet élément'));
            fields.appendChild(preview);
            function updatePreview() {
                while (preview.firstChild) {preview.removeChild(preview.firstChild);}
                var v = value.value || types[type].hint;
                var display = type === 'image' ? node('span', t('Aperçu du cadre de l’image')) : node('span', v.slice(0, 100));
                preview.appendChild(display);
                summary.textContent = (value.value || types[type].hint).replace(/\s+/g,' ').slice(0,74);
                if (type === 'button') {display.classList.add('dendrila-simple-edit-preview-button');}
                if (type === 'heading' || type === 'title') {display.classList.add('dendrila-simple-edit-preview-heading');}
                if (type === 'image') {display.classList.add('dendrila-simple-edit-preview-image');}
                preview.style.setProperty('--dendrila-simple-edit-preview-color', colorValue(box.dataset.color) || '#24543b');
                preview.style.textAlign = box.dataset.align;
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
            var wizard = root.querySelector('[data-dendrila-simple-edit-article-import]');
            if (!wizard) {return;}
            var selector = wizard.querySelector('[data-dendrila-simple-edit-article-select]');
            var label = wizard.querySelector('[data-dendrila-simple-edit-article-label]');
            var insertButton = wizard.querySelector('[data-dendrila-simple-edit-article-insert]');
            var preview = wizard.querySelector('[data-dendrila-simple-edit-article-preview]');
            var previewTitle = wizard.querySelector('[data-dendrila-simple-edit-article-preview-title]');
            var previewText = wizard.querySelector('[data-dendrila-simple-edit-article-preview-text]');
            var previewAction = wizard.querySelector('[data-dendrila-simple-edit-article-preview-action]');
            var feedback = wizard.querySelector('[data-dendrila-simple-edit-article-feedback]');
            var choice = wizard.querySelectorAll('input[type="radio"]');
            if (!selector || !label || !insertButton || !preview || !feedback || !previewTitle ||
                !previewText || !previewAction || !choice.length) {return;}
            function selectedArticle() {
                var option = selector.options[selector.selectedIndex];
                if (!option || !option.value) {return null;}
                var title = option.getAttribute('data-dendrila-simple-edit-article-title') || '';
                var paragraph = option.getAttribute('data-dendrila-simple-edit-article-paragraph') || '';
                var url = option.getAttribute('data-dendrila-simple-edit-article-url') || '';
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
                        'dendrila-simple-edit-example-action' : 'dendrila-simple-edit-example-link-text';
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
                    feedback.textContent = t('L’insertion n’a pas pu être terminée. Vérifiez le contenu.');
                    return;
                }
                repaint(t('Article inséré dans la newsletter.'));
                feedback.textContent = t('Article ajouté. Vous pouvez maintenant modifier chaque élément dans la newsletter, sans changer l’article original.');
                var input = heading.querySelector('.dendrila-simple-edit-block-fields input');
                if (input) {input.focus();}
            });
            wizard.hidden = false;
            if (selector.options.length <= 1) {
                feedback.textContent = t('Aucun article publié avec un paragraphe n’est disponible pour le moment.');
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
            }, 'dendrila-simple-edit-add-block');
            b.dataset.add = type;
            b.draggable = true;
            b.addEventListener('dragstart', function (event) {moving = type; event.dataTransfer.setData('text/plain', type);});
            b.addEventListener('dragend', function () {moving = null;});
            b.setAttribute('aria-label', 'Ajouter : ' + types[type].label);
            // A miniature of the actual output is clearer than 'Aa' or technical heading codes.
            var visual = node('span', '', 'dendrila-simple-edit-add-block-icon dendrila-simple-edit-example-' + type);
            visual.setAttribute('aria-hidden', 'true');
            if (type === 'title') {
                visual.appendChild(node('span', t('Grand titre'), 'dendrila-simple-edit-example-main-title'));
            } else if (type === 'heading') {
                visual.appendChild(node('span', t('Titre de section'), 'dendrila-simple-edit-example-section-title'));
                visual.appendChild(node('span', '', 'dendrila-simple-edit-example-rule'));
            } else if (type === 'text') {
                visual.appendChild(node('span', '', 'dendrila-simple-edit-example-lines'));
            } else if (type === 'image') {
                var landscape = node('span', '', 'dendrila-simple-edit-example-landscape');
                landscape.appendChild(node('span', '', 'dendrila-simple-edit-example-sun'));
                visual.appendChild(landscape);
            } else if (type === 'button') {
                visual.appendChild(node('span', 'Découvrir', 'dendrila-simple-edit-example-action'));
            } else if (type === 'link') {
                visual.appendChild(node('span', 'En savoir plus', 'dendrila-simple-edit-example-link-text'));
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
            moving = null; repaint(t('Ordre mis à jour.'));
        });
        var holder = node('template'); holder.innerHTML = source.value;
        // Never silently flatten layouts or rich HTML from an earlier editor.
        // An existing unsupported document remains editable in its original form.
        var incompatible = Array.from(holder.content.childNodes).some(function (child) {
            if (child.nodeType === 3) {return false;}
            if (child.nodeType !== 1) {return true;}
            if (child.hasAttribute('data-dendrila-simple-edit-block') || child.hasAttribute('data-dse-block')) {
                var kind = child.getAttribute('data-dendrila-simple-edit-block') || child.getAttribute('data-dse-block');
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
            var kind = child.nodeType === 1 ? (child.getAttribute('data-dendrila-simple-edit-block') || child.getAttribute('data-dse-block')) : null;
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
                    url: inner.getAttribute(kind === 'image' ? 'src' : 'href'), color: inner.getAttribute('data-dendrila-simple-edit-color') || inner.getAttribute('data-dse-color') || 'green',
                    align: child.getAttribute('data-dendrila-simple-edit-align') || child.getAttribute('data-dse-align') || inner.getAttribute('data-dendrila-simple-edit-align') || inner.getAttribute('data-dse-align') || 'left'});
            } else if (child.nodeType === 1 && /^(P|H1|H2|IMG)$/.test(child.tagName)) {
                flushLegacy();
                var kind2 = child.tagName === 'H1' ? 'title' : child.tagName === 'H2' ? 'heading' : child.tagName === 'IMG' ? 'image' : 'text';
                if (kind2 === 'title' && !allowTitle) {kind2 = 'heading';}
                child.querySelectorAll('br').forEach(function (br) {br.replaceWith(document.createTextNode('\n'));});
                addBlock(kind2, {text: child.tagName === 'IMG' ? child.getAttribute('alt') : child.textContent,
                    url: child.tagName === 'IMG' ? child.getAttribute('src') : '', color: child.getAttribute('data-dendrila-simple-edit-color') || 'green',
                    align: child.getAttribute('data-dendrila-simple-edit-align') || 'left'});
            } else {legacy.push(child.nodeType === 1 ? child.outerHTML : child.textContent);}
        });
        flushLegacy();
        function compose() {
            return blocks().map(function (item) {
                var type = item.dataset.block, fields = item.querySelectorAll('.dendrila-simple-edit-block-fields > input, .dendrila-simple-edit-block-fields > textarea');
                if (!fields.length) {return null;}
                var content = fields[0].value || '', other = fields[1] ? fields[1].value : '';
                var wrap = node('div');wrap.dataset.dendrilaSimpleEditBlock = type;
                wrap.dataset.dendrilaSimpleEditAlign = item.dataset.align || 'left';
                var tag = {title: 'h1', heading: 'h2', text: 'p', image: 'img', button: 'a', link: 'a'}[type];
                var inner = node(tag, type === 'image' ? '' : content);
                if (type === 'text') {
                    inner.replaceChildren();
                    content.split('\n').forEach(function (line, idx) {if (idx) {inner.appendChild(node('br'));}inner.appendChild(document.createTextNode(line));});
                } else if (type === 'image') {inner.setAttribute('src', content);inner.alt = other;}
                else if (type === 'link' || type === 'button') {
                    inner.setAttribute('href', other);
                    if (type === 'button') {inner.className = 'dendrila-simple-edit-newsletter-cta';}
                }
                if (item.dataset.color !== 'default') {inner.dataset.dendrilaSimpleEditColor = item.dataset.color;}
                inner.dataset.dendrilaSimpleEditAlign = item.dataset.align || 'left';
                wrap.appendChild(inner);
                return wrap;
            }).filter(Boolean);
        }

        function refreshPreview() {
            var fragment = document.createDocumentFragment();
            compose().forEach(function (wrap) {
                var content = wrap.firstElementChild;
                var align = ['left','center','right'].indexOf(wrap.dataset.dendrilaSimpleEditAlign) !== -1 ? wrap.dataset.dendrilaSimpleEditAlign : 'left';
                wrap.style.textAlign = align;
                if (content.tagName === 'IMG') {
                    content.style.marginLeft = align === 'right' || align === 'center' ? 'auto' : '0';
                    content.style.marginRight = align === 'left' || align === 'center' ? 'auto' : '0';
                }
                var color = colorValue(content.dataset.dendrilaSimpleEditColor);
                if (content.classList.contains('dendrila-simple-edit-newsletter-cta')) {
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
                    if (color) { content.style.borderColor = color; content.classList.add('dendrila-simple-edit-preview-image-frame'); }
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
        root.append(node('h4', t('Ajouter un élément'), 'dendrila-simple-edit-builder-heading'), addBar, canvas, status, previewPanel);
        // Move the assistant beneath the six insertion tiles; native <details> keeps it
        // collapsed by default, with keyboard and screen-reader support.
        var articleImport = root.querySelector('[data-dendrila-simple-edit-article-import]');
        if (articleImport) { root.insertBefore(articleImport, canvas); }
        initArticleImport();
        repaint();
    }
    function init() {
        document.querySelectorAll('[data-dendrila-simple-edit-block-editor]').forEach(function (root, index) {
            // Hundreds of eligible posts may be listed. Build a visual editor only
            // when its accordion is opened, to keep the admin page responsive.
            var details = root.closest('details');
            if (!details || details.open) { editor(root, index); return; }
            function onToggle() {
                if (!details.open) return;
                details.removeEventListener('toggle', onToggle);
                editor(root, index);
            }
            details.addEventListener('toggle', onToggle);
        });
    }
    if (document.readyState === 'loading') {document.addEventListener('DOMContentLoaded', init, {once: true});}
    else {init();}
}());
