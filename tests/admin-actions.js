/* Exercise the whole admin script: separate initializers must share private helpers. */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const script = fs.readFileSync(__dirname + '/../assets/admin.js', 'utf8');

function fixture(reducedMotion, clipboardMode) {
    const listeners = {};
    const copies = [];
    const scrolls = [];
    const temporary = [];
    const subject = {focus() { this.focused = true; }, scrollIntoView(options) { scrolls.push(options); }};
    const composer = {hidden: true, querySelector() { return subject; }};
    const next = {hidden: false, addEventListener(name, callback) { this[name] = callback; }};
    const parent = {querySelector() { return composer; }, style: {setProperty() {}}};
    const step = {
        closest() { return parent; }, querySelector() { return next; }, querySelectorAll() { return []; },
        addEventListener() {}, classList: {add() {}}, style: {setProperty() {}}
    };
    const checkboxes = [{checked: false, disabled: false}, {checked: false, disabled: true}];
    const fieldset = {querySelectorAll() { return checkboxes; }};
    const target = {value: '[dse_text field="phone"]', select() { this.selected = true; }};
    const copyButton = {textContent: 'Copy', getAttribute() { return 'shortcode'; }};
    const document = {
        readyState: 'complete',
        querySelector() { return null; },
        querySelectorAll(selector) { return selector === '[data-dendrila-simple-edit-template-step]' ? [step] : []; },
        getElementById(id) { return id === 'shortcode' ? target : id === 'role-group' ? fieldset : null; },
        addEventListener(name, callback) { (listeners[name] ||= []).push(callback); },
        createElement() {
            const element = {style: {}, select() {}, remove() { this.removed = true; }};
            temporary.push(element);
            return element;
        },
        body: {appendChild() {}},
        execCommand() { return clipboardMode !== 'failure'; }
    };
    const window = {
        dendrilaSimpleEditStrings: {'Copié !': 'Copied!', 'Copie impossible : sélectionnez le code': 'Select the code'},
        matchMedia() { return {matches: reducedMotion}; },
        isSecureContext: clipboardMode === 'native'
    };
    const navigator = {clipboard: {async writeText(value) { copies.push(value); }}};
    vm.runInNewContext(script, {document, window, navigator}, {filename: 'admin.js'});
    async function click(button, kind) {
        const event = {target: {closest(selector) {
            if (kind === 'copy' && selector === '[data-dendrila-simple-edit-copy]') return button;
            if (kind === 'permissions' && selector.indexOf('data-dendrila-simple-edit-select-all') !== -1) return button;
            return null;
        }}};
        for (const listener of listeners.click || []) await listener(event);
    }
    function permissionButton(select, id) {
        return {
            getAttribute(name) { return name === (select ? 'data-dendrila-simple-edit-select-all' : 'data-dendrila-simple-edit-select-none') ? id : null; },
            hasAttribute(name) { return name === (select ? 'data-dendrila-simple-edit-select-all' : 'data-dendrila-simple-edit-select-none'); },
            closest() { return fieldset; }
        };
    }
    return {subject, composer, next, checkboxes, target, copyButton, copies, temporary, scrolls, click, permissionButton};
}

(async function () {
    for (const reduced of [false, true]) {
        const view = fixture(reduced, 'native');
        view.next.click();
        assert.equal(view.composer.hidden, false, 'newsletter editor opens');
        assert.equal(view.subject.focused, true, 'subject receives keyboard focus');
        assert.equal(view.next.hidden, true, 'next action only applies once');
        assert.equal(view.scrolls[0].behavior, reduced ? 'instant' : 'smooth', 'motion preference is respected');
        await view.click(view.copyButton, 'copy');
        assert.deepEqual(view.copies, ['[dse_text field="phone"]']);
        assert.equal(view.copyButton.textContent, 'Copied!', 'copy feedback uses translated private helper');
        for (const group of ['role-group', '']) {
            await view.click(view.permissionButton(true, group), 'permissions');
            assert.equal(view.checkboxes[0].checked, true, 'settings and setup permit bulk selection');
            assert.equal(view.checkboxes[1].checked, false, 'disabled permissions remain untouched');
            await view.click(view.permissionButton(false, group), 'permissions');
            assert.equal(view.checkboxes[0].checked, false, 'bulk deselection works');
        }
    }
    for (const mode of ['fallback', 'failure']) {
        const view = fixture(false, mode);
        await view.click(view.copyButton, 'copy');
        assert.equal(view.temporary[0].removed, true, 'temporary input is removed after success or error');
        assert.equal(view.copyButton.textContent, mode === 'failure' ? 'Select the code' : 'Copied!');
        if (mode === 'failure') assert.equal(view.target.selected, true, 'manual selection remains available');
    }
    console.log('OK: full admin script, newsletter focus, reduced motion, translated clipboard feedback, fallback cleanup and scoped permissions');
}()).catch(error => { console.error(error); process.exitCode = 1; });
