'use strict';

// Dependency-free URL and consumer regressions. The DOM double models properties/events;
// real-browser rendering and JavaScript execution remain separate integration checks.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const staticRoot = path.join(__dirname, '../public/static');
const origin = 'https://factory.example';
const hostileText = '<img src=x onerror="globalThis.executed=true">';

class Element {
    constructor(tagName = 'div') {
        this.tagName = tagName.toUpperCase();
        this.attributes = new Map();
        this.queries = new Map();
        this.listeners = new Map();
        this.children = [];
        this.value = '';
        this.textContent = '';
        this.files = [];
        this.hidden = false;
        this._html = '';
        const classes = new Set();
        this.classList = {
            add: (...names) => names.forEach(name => classes.add(name)),
            remove: (...names) => names.forEach(name => classes.delete(name)),
            contains: name => classes.has(name),
            toggle: (name, active) => {
                active = active === undefined ? !classes.has(name) : active;
                active ? classes.add(name) : classes.delete(name);
                return active;
            },
        };
    }
    get innerHTML() { return this._html; }
    set innerHTML(value) { this._html = value; this.children = []; }
    setAttribute(name, value) { this.attributes.set(name, String(value)); }
    getAttribute(name) { return this.attributes.has(name) ? this.attributes.get(name) : null; }
    hasAttribute(name) { return this.attributes.has(name); }
    removeAttribute(name) { this.attributes.delete(name); if (name === 'src') this.src = ''; }
    querySelector(selector) { return this.queries.get(selector) || null; }
    querySelectorAll(selector) { return this.queries.get(selector) || []; }
    addEventListener(name, callback) {
        this.listeners.set(name, [...(this.listeners.get(name) || []), callback]);
    }
    emit(name, event = {}) {
        (this.listeners.get(name) || []).forEach(callback => callback({target: this, preventDefault() {}, ...event}));
    }
    appendChild(child) { this.children.push(child); child.parent = this; return child; }
    prepend(child) { this.children.unshift(child); child.parent = this; }
    remove() { this.parent.children.splice(this.parent.children.indexOf(this), 1); }
    get lastElementChild() { return this.children[this.children.length - 1]; }
    closest() { return this.closestElement || null; }
    focus() { this.focused = true; }
    scrollIntoView() {}
}

function environment() {
    const document = new Element('document');
    document.body = new Element('body');
    document.createElement = name => new Element(name);
    const revoked = [];
    let nextBlob = 1;
    class TestURL extends URL {
        static createObjectURL() { return 'blob:' + origin + '/local-preview-' + nextBlob++; }
        static revokeObjectURL(value) { revoked.push(value); }
    }
    class DataTransfer {
        constructor() { this.files = []; this.items = {add: file => this.files.push(file)}; }
    }
    const window = {
        location: new URL(origin + '/workspace/tools'), innerWidth: 1200,
        addEventListener() {}, localStorage: {getItem() { return null; }, setItem() {}},
        lucide: {createIcons() {}},
    };
    const context = vm.createContext({
        document, window, URL: TestURL, URLSearchParams, DataTransfer,
        navigator: {clipboard: {writeText: () => Promise.resolve()}},
        fetch: () => Promise.reject(new Error('No network is permitted in this test.')),
        Event: class { constructor(type) { this.type = type; } },
        console,
    });
    return {document, window, context, revoked};
}

function source(name) { return fs.readFileSync(path.join(staticRoot, name), 'utf8'); }
function run(env, name) { vm.runInContext(source(name), env.context, {filename: name}); }
function exposeFunction(env, name, fn) {
    const match = source(name).match(new RegExp('(?:^|\\n)( *)function ' + fn + '\\([^]*?\\n\\1}'));
    assert.ok(match, name + ' exposes the expected local policy for testing');
    vm.runInContext(match[0], env.context);
    return env.context[fn];
}
function mapped(root, selector, tag) {
    const element = new Element(tag);
    root.queries.set(selector, element);
    return element;
}
function nodes(root, selectors) {
    return Object.fromEntries(selectors.map(selector => [selector, mapped(root, '[' + selector + ']')]));
}
function assertLiteral(element, text) {
    assert.equal(element.textContent, text);
    assert.equal(element.innerHTML, '', 'Untrusted text must not become markup');
}
const settle = () => new Promise(resolve => setImmediate(resolve));

async function main() {
    // Every independently loaded script must preserve local previews/data images while
    // rejecting active documents, executable schemes, foreign blobs and malformed URLs.
    for (const name of ['console.js', 'canvas-workflow.js', 'image-tools.js', 'landing.js', 'experience.js']) {
        const env = environment();
        const safe = exposeFunction(env, name, 'safeImageUrl');
        for (const value of ['javascript:alert(1)', 'JaVaScRiPt:alert(1)', 'java\nscript:alert(1)',
            'vbscript:msgbox(1)', 'data:text/html,' + hostileText, 'file:///tmp/image.png',
            'blob:https://attacker.example/preview', 'https://[invalid', '', null]) {
            assert.equal(safe(value), '', name + ': rejected image URL ' + value);
        }
        for (const value of ['/uploads/商品.png', 'https://cdn.example/image.png?signature=abc&part=2',
            'blob:' + origin + '/preview', 'data:image/png;base64,aGVsbG8=',
            'data:image/gif;base64,R0lGODlhAQABAAD/ACwAAAAAAQABAAACADs=',
            'data:image/svg+xml,%3Csvg%20xmlns="http://www.w3.org/2000/svg"/%3E']) {
            assert.equal(safe(value), new URL(value, env.window.location.href).href, name + ': supported image preserved');
        }
        assert.equal(new URL(safe('/image.png" onerror="alert(1)')).origin, origin);
    }
    for (const name of ['console.js', 'image-tools.js']) {
        const env = environment();
        const safe = exposeFunction(env, name, 'safeWorkspaceUrl');
        const fallback = '/workspace/free-create';
        for (const value of ['javascript:alert(1)', 'data:text/html,evil', '//attacker.example/workspace/create',
            'https://user:pass@factory.example/workspace/create', '/workspace/../admin', '/admin', '', null]) {
            assert.equal(safe(value, fallback), fallback, name + ': unsafe internal destination rejected');
        }
        assert.equal(safe('/workspace/free-create?prompt=a%26b', fallback), origin + '/workspace/free-create?prompt=a%26b');
    }

    // Asset details use image-only policy for the preview and stricter HTTP(S) links.
    {
        const env = environment();
        const panel = mapped(env.document, '[data-asset-detail-panel]');
        const view = nodes(panel, ['data-asset-detail-image', 'data-asset-detail-title', 'data-asset-detail-meta',
            'data-asset-detail-open', 'data-asset-detail-download', 'data-asset-detail-close']);
        const button = new Element('button');
        env.document.queries.set('[data-asset-detail]', [button]);
        run(env, 'experience.js'); env.document.emit('DOMContentLoaded');
        button.setAttribute('data-asset-detail-src', 'javascript:globalThis.executed=true');
        button.setAttribute('data-asset-detail-download-url', 'data:text/html,' + hostileText);
        button.setAttribute('data-asset-detail-title', hostileText);
        button.setAttribute('data-asset-detail-meta', hostileText);
        button.emit('click');
        assert.equal(view['data-asset-detail-image'].src, '');
        assert.equal(view['data-asset-detail-open'].href, '#');
        assert.equal(view['data-asset-detail-download'].href, '#');
        assertLiteral(view['data-asset-detail-title'], hostileText);
        assertLiteral(view['data-asset-detail-meta'], hostileText);
        assert.equal(panel.hidden, false);
        assert.equal(view['data-asset-detail-close'].focused, true);
        button.setAttribute('data-asset-detail-src', 'https://cdn.example/product.png?x=1&y=2');
        button.setAttribute('data-asset-detail-download-url', '/workspace/assets/7/download');
        button.emit('click');
        assert.equal(view['data-asset-detail-image'].src, 'https://cdn.example/product.png?x=1&y=2');
        assert.equal(view['data-asset-detail-open'].href, 'https://cdn.example/product.png?x=1&y=2');
        assert.equal(view['data-asset-detail-download'].href, origin + '/workspace/assets/7/download');
        view['data-asset-detail-close'].emit('click'); assert.equal(panel.hidden, true);
    }

    // Image tools exercise upload, history, navigation, successful reverse-prompt and failure.
    {
        const env = environment();
        const shell = mapped(env.document, '[data-image-tools]');
        const view = nodes(shell, ['data-image-tools-file', 'data-image-tools-preview', 'data-image-tools-preview-image',
            'data-image-tools-file-label', 'data-image-tools-local-status', 'data-image-tools-result',
            'data-image-tools-result-image', 'data-image-tools-result-title', 'data-image-tools-result-meta',
            'data-image-tools-download', 'data-image-tools-asset', 'data-image-tools-asset-preview',
            'data-image-tools-prompt-result', 'data-image-tools-prompt-output', 'data-image-tools-prompt-empty',
            'data-image-tools-prompt-status', 'data-image-tools-prompt-history', 'data-image-tools-use-prompt', 'data-image-tools-clear']);
        const reverse = mapped(shell, '[data-image-tools-ai="reverse"]', 'button');
        reverse.setAttribute('data-image-tools-ai', 'reverse');
        const edit = new Element('button'); edit.setAttribute('data-image-tools-ai', 'regional');
        shell.queries.set('[data-image-tools-ai]', [reverse, edit]);
        const option = new Element('option'); option.textContent = hostileText;
        const select = view['data-image-tools-asset']; select.value = '7&tool=evil'; select.options = [option]; select.selectedIndex = 0;
        run(env, 'image-tools.js'); env.document.emit('DOMContentLoaded');
        const input = view['data-image-tools-file'];
        input.files = [{name: hostileText + '.png', type: 'image/png'}]; input.emit('change');
        assert.match(view['data-image-tools-preview-image'].src, /^blob:https:\/\/factory\.example\//);
        assertLiteral(view['data-image-tools-file-label'], hostileText + '.png');
        option.setAttribute('data-image', 'javascript:alert(1)'); select.emit('change');
        assert.equal(view['data-image-tools-asset-preview'].src, '');
        option.setAttribute('data-image', '/uploads/normal.png'); select.emit('change');
        assert.equal(view['data-image-tools-asset-preview'].src, origin + '/uploads/normal.png');
        const history = new Element('button'); history.closestElement = history;
        history.setAttribute('data-prompt', hostileText); history.setAttribute('data-use-url', 'javascript:alert(1)');
        view['data-image-tools-prompt-history'].emit('click', {target: history});
        assert.equal(view['data-image-tools-prompt-output'].value, hostileText);
        assert.equal(view['data-image-tools-prompt-output'].innerHTML, '');
        assert.equal(view['data-image-tools-use-prompt'].href, '/workspace/free-create');
        shell.setAttribute('data-canvas-url', 'javascript:alert(1)'); edit.emit('click');
        const destination = new URL(env.window.location.href);
        assert.equal(destination.origin, origin); assert.equal(destination.pathname, '/workspace/canvas');
        assert.equal(destination.searchParams.get('asset_id'), select.value);
        assert.equal(destination.searchParams.get('tool'), 'regional');
        env.context.fetch = () => Promise.resolve({ok: true, json: () => Promise.resolve({ok: true, prompt: hostileText,
            useUrl: 'javascript:alert(1)', createdAt: hostileText})});
        reverse.emit('click'); assert.equal(reverse.disabled, true); assert.equal(reverse.classList.contains('is-loading'), true);
        await settle();
        assert.equal(reverse.disabled, false); assert.equal(reverse.classList.contains('is-loading'), false);
        assert.equal(view['data-image-tools-use-prompt'].href, '/workspace/free-create');
        const entry = view['data-image-tools-prompt-history'].children[0];
        assertLiteral(entry.children[0].children[0], hostileText);
        assertLiteral(entry.children[0].children[1], hostileText);
        assert.equal(entry.children[1].getAttribute('data-lucide'), 'chevron-right');
        env.context.fetch = () => Promise.reject(new Error(hostileText)); reverse.emit('click'); await settle();
        assertLiteral(view['data-image-tools-prompt-status'], hostileText);
        assert.equal(view['data-image-tools-prompt-status'].classList.contains('error'), true);
        assert.equal(reverse.disabled, false); assert.equal(reverse.classList.contains('is-loading'), false);
        view['data-image-tools-clear'].emit('click');
        assert.equal(view['data-image-tools-preview'].hidden, true); assert.equal(env.revoked.length, 1);
    }

    // Detail generation keeps IDs in their path segments, and never trusts an action template's scheme.
    {
        const env = environment();
        const workspace = mapped(env.document, '[data-detail-generation-workspace]');
        const view = nodes(workspace, ['data-detail-generation-form', 'data-detail-plan-create-form',
            'data-detail-project-link', 'data-detail-project-hint', 'data-detail-state-title', 'data-detail-state-copy']);
        const form = view['data-detail-generation-form'];
        const create = view['data-detail-plan-create-form'];
        mapped(form, 'button[type="submit"]', 'button'); mapped(create, 'button[type="submit"]', 'button');
        const sku = mapped(workspace, '#detail-sku-id', 'select');
        const projects = mapped(workspace, '#detail-project-id', 'select');
        const skuOption = new Element('option'); skuOption.value = '7/../../admin?x="';
        skuOption.setAttribute('data-image', '/uploads/product.png');
        const projectOption = new Element('option'); projectOption.value = '8/../../admin?x="';
        projectOption.setAttribute('data-sku-id', skuOption.value);
        sku.value = skuOption.value; sku.options = [skuOption]; sku.selectedIndex = 0;
        projects.value = projectOption.value; projects.options = [projectOption]; projects.selectedIndex = 0;
        projects.queries.set('option[data-sku-id]', [projectOption]);
        create.setAttribute('data-action-template', 'javascript:alert(__SKU__)');
        run(env, 'console.js'); env.context.setupDetailGeneration();
        assert.equal(form.getAttribute('action'), '/workspace/detail-pages/' + encodeURIComponent(projectOption.value) + '/generate');
        assert.equal(view['data-detail-project-link'].href, '/workspace/detail-pages/' + encodeURIComponent(projectOption.value));
        assert.equal(create.action, '');
        create.setAttribute('data-action-template', '/workspace/skus/__SKU__/detail-report'); sku.emit('change');
        assert.equal(create.action, origin + '/workspace/skus/' + encodeURIComponent(skuOption.value) + '/detail-report');
        skuOption.value = '7'; projectOption.value = '8'; projectOption.setAttribute('data-sku-id', '7');
        sku.value = '7'; projects.value = '8'; sku.emit('change');
        assert.equal(form.getAttribute('action'), '/workspace/detail-pages/8/generate');
        assert.equal(create.action, origin + '/workspace/skus/7/detail-report');
        assert.equal(form.querySelector('button[type="submit"]').disabled, false);
    }

    // Canvas's required HTML integration still escapes attributes and text, retaining its controls.
    {
        const env = environment();
        exposeFunction(env, 'canvas-workflow.js', 'safeImageUrl');
        exposeFunction(env, 'canvas-workflow.js', 'escapeHtml');
        const nodeHtml = exposeFunction(env, 'canvas-workflow.js', 'nodeHtml');
        const html = nodeHtml({id: 7, fileUrl: 'https://cdn.example/image.png" onerror="alert(1)',
            backupFileUrl: 'javascript:alert(1)', assetTypeLabel: hostileText, skuCode: hostileText, skuName: hostileText});
        assert.equal(html.includes('" onerror="'), false);
        assert.equal(html.includes('<img src=x'), false);
        assert.equal(html.includes('javascript:'), false);
        assert.ok(html.includes('&lt;img src=x'));
        assert.ok(html.includes('data-node-preview')); assert.ok(html.includes('data-node-edit')); assert.ok(html.includes('data-node-remove'));
        assert.ok(html.includes('data-lucide="maximize-2"'));
    }

    // Console preview/fallback consumers retain local object URLs and one-time fallbacks.
    {
        const env = environment();
        const input = mapped(env.document, '[data-sku-analyzer] #source_image');
        const preview = mapped(env.document, '[data-sku-upload-preview]');
        const empty = mapped(env.document, '[data-sku-upload-empty]');
        run(env, 'console.js'); env.context.setupSkuImagePreview();
        input.files = [{name: hostileText, type: 'image/png'}]; input.emit('change');
        assert.match(preview.src, /^blob:https:\/\/factory\.example\//); assert.equal(preview.hidden, false); assert.equal(empty.hidden, true);
        const fallback = new Element('img'); fallback.setAttribute('data-fallback', 'javascript:alert(1)');
        env.document.queries.set('img[data-fallback]', [fallback]); env.context.setupImageFallbacks(); fallback.emit('error');
        assert.equal(fallback.src, ''); assert.equal(fallback.getAttribute('data-fallback-used'), '1');
        fallback.setAttribute('data-fallback', '/uploads/backup.png'); fallback.emit('error');
        assert.equal(fallback.src, '', 'Failed images do not recurse indefinitely');
        fallback.removeAttribute('data-fallback-used'); fallback.emit('error');
        assert.equal(fallback.src, origin + '/uploads/backup.png');
    }

    // Landing upload/reorder/remove keeps image nodes and icon buttons, with no filename markup.
    {
        const env = environment();
        const form = mapped(env.document, '[data-guest-creation-console]');
        const view = nodes(form, ['data-guest-image-input', 'data-guest-upload-zone', 'data-guest-upload-empty',
            'data-guest-upload-previews', 'data-guest-brief', 'data-guest-brief-count', 'data-guest-plan',
            'data-guest-platform', 'data-guest-language', 'data-guest-submit', 'data-guest-message', 'data-guest-local-helper']);
        run(env, 'landing.js'); env.document.emit('DOMContentLoaded');
        const input = view['data-guest-image-input'];
        input.files = [{name: hostileText + '1.png', type: 'image/png', size: 10, lastModified: 1},
            {name: hostileText + '2.png', type: 'image/png', size: 10, lastModified: 2}];
        input.emit('change');
        const previews = view['data-guest-upload-previews'];
        assert.equal(previews.children.length, 2); assert.equal(view['data-guest-submit'].disabled, false);
        assert.match(previews.children[0].children[0].src, /^blob:https:\/\/factory\.example\//);
        assert.equal(previews.children[0].children[2].children[1].innerHTML, '<i data-lucide="arrow-right"></i>');
        previews.children[0].children[2].children[1].emit('click');
        assert.equal(input.files[0].lastModified, 2, 'Reference order is retained after rebuilding safe previews');
        previews.children[0].children[2].children[2].emit('click');
        assert.equal(previews.children.length, 1); assert.equal(input.files[0].lastModified, 1);
        previews.children[0].children[2].children[2].emit('click');
        assert.equal(previews.children.length, 0); assert.equal(view['data-guest-submit'].disabled, true);
        assert.ok(env.revoked.length >= 3, 'Rebuilt previews release their previous object URLs');
    }
    console.log('DOM security regressions passed: five URL policies, asset details, image tools, console previews/fallbacks and landing reference controls.');
}

main().catch(error => { console.error(error); process.exitCode = 1; });
