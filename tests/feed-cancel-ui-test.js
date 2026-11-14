const { execFileSync } = require('child_process');
const vm = require('vm');
const assert = require('assert');

const html = execFileSync('php', ['-d', 'xdebug.mode=off', __dirname + '/feed-cancel-ui-fixture.php'], { encoding: 'utf8' });
assert(html.includes('Cancel and clear imports for this feed'));
const script = html.match(/<script>([\s\S]*?)<\/script>/)[1];
let click;
let confirmation;
let alertMessage;
let reloaded = false;
let request;
const button = { dataset: { feedId: '5' }, textContent: 'Cancel and clear', disabled: false };
const context = {
    URL, FormData,
    document: {
        getElementById() { return { content: { firstElementChild: {} } }; },
        querySelector() { return { insertAdjacentElement() {} }; },
        addEventListener(event, callback) { click = callback; },
    },
    window: {
        location: { origin: 'https://example.test', reload() { reloaded = true; } },
        confirm(message) { confirmation = message; return true; },
    },
    ajaxurl: '/wp-admin/admin-ajax.php',
    alert(message) { alertMessage = message; },
    fetch: async (url, options) => {
        request = options.body;
        return { json: async () => ({ success: true, data: { message: 'Cleared' } }) };
    },
};
(async () => {
    vm.runInNewContext(script, context);
    await click({ target: { closest() { return button; } } });
    assert(confirmation.includes('permanently delete'));
    assert.strictEqual(request.get('action'), 'a1fi_cancel_and_clear_feed_jobs');
    assert.strictEqual(request.get('feed_id'), '5');
    assert.strictEqual(request.get('_ajax_nonce'), 'a1fi_cancel_and_clear_feed');
    assert.strictEqual(alertMessage, 'Cleared');
    assert(reloaded);
    reloaded = false;
    context.fetch = async () => ({ json: async () => ({ success: false, data: 'Database failed' }) });
    await click({ target: { closest() { return button; } } });
    assert.strictEqual(alertMessage, 'Database failed');
    assert.strictEqual(button.disabled, false);
    assert.strictEqual(reloaded, false);
    console.log('Cancellation UI checks passed.');
})().catch(error => { console.error(error); process.exitCode = 1; });
