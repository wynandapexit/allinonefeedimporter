const { execFileSync } = require('child_process');
const vm = require('vm');
const assert = require('assert');

const html = execFileSync('php', ['-d', 'xdebug.mode=off', __dirname + '/job-ui-fixture.php'], { encoding: 'utf8' });
const script = html.match(/<script>([\s\S]*?)<\/script>/)[1];
const timers = [];
const events = [];
const monitorStatus = { textContent: '' };
const live = { dataset: { jobId: '8' }, querySelector() { return { textContent: '', value: 0 }; } };
const template = { content: { cloneNode() { return {}; } } };
const panels = {};
function panel(id, value, top) {
    const select = id === 'a1fi-import-logs' ? { value } : null;
    const scroll = [{ scrollTop: top, scrollLeft: 3 }];
    return {
        id, select, scroll,
        contains(node) { return node === this.focused; },
        querySelector() { return select; },
        querySelectorAll() { return scroll; },
        replaceWith(next) { panels[id] = next; },
    };
}
panels['a1fi-import-logs'] = panel('a1fi-import-logs', '9', 42);
panels['a1fi-job-error-details'] = panel('a1fi-job-error-details', '', 12);
let tableReplaced = 0;
const table = {
    querySelector() { return null; },
    contains() { return false; },
    replaceWith() { tableReplaced++; },
};
const document = {
    activeElement: null,
    querySelector(selector) {
        if (selector === '.wrap') return { appendChild() {} };
        if (selector === '.a1fi-jobs-table-wrap') return table;
        return null;
    },
    querySelectorAll() { return []; },
    getElementById(id) {
        if (id === 'a1fi-jobs-template') return template;
        if (id === 'a1fi-live-progress') return live;
        if (id === 'a1fi-monitor-refresh-status') return monitorStatus;
        return panels[id];
    },
    addEventListener() {},
    dispatchEvent(event) { events.push(event.type); },
};
let received;
let fail = false;
const context = {
    document, URL, URLSearchParams, FormData, console,
    Event: class { constructor(type) { this.type = type; } },
    DOMParser: class {
        parseFromString(value) {
            if (value === 'jobs') return { getElementById: () => ({ content: { querySelector: () => ({}) } }) };
            const id = value === 'logs' ? 'a1fi-import-logs' : 'a1fi-job-error-details';
            return { getElementById: () => panel(id, '7', 0) };
        }
    },
    window: {
        location: { origin: 'https://example.test', search: '?page=a1fi&a1fi_log_feed=7' },
        getSelection() { return { isCollapsed: true }; },
    },
    ajaxurl: '/wp-admin/admin-ajax.php',
    setTimeout(callback, delay) { timers.push({ callback, delay }); },
    fetch: async (url, options) => {
        const action = options.body.get('action');
        if (action === 'a1fi_refresh_monitor') {
            received = options.body;
            return { json: async () => fail ? { success: false, data: 'Database failed' } : { success: true, data: { logs: 'logs', errors: 'errors', jobs: 'jobs' } } };
        }
        return { json: async () => ({ success: true, data: action === 'a1fi_job_status' ? { id: 8, status: 'completed', total: 0, processed: 0, created: 0, updated: 0, skipped: 0, failed: 0, message: '', errors: [] } : { status: 'completed' } }) };
    },
};
(async () => {
    vm.runInNewContext(script, context);
    await new Promise(resolve => setImmediate(resolve));
    const first = timers.find(timer => timer.delay === 10000);
    assert(first, 'refresh must start after 10 seconds');
    await first.callback();
    assert.strictEqual(received.get('feed_id'), '7');
    assert.strictEqual(received.get('job_id'), '8');
    assert.strictEqual(received.get('_ajax_nonce'), 'a1fi_refresh_monitor');
    assert.strictEqual(panels['a1fi-import-logs'].select.value, '9');
    assert.strictEqual(panels['a1fi-import-logs'].scroll[0].scrollTop, 42);
    assert.strictEqual(tableReplaced, 1);
    assert(events.includes('a1fi-jobs-refreshed'));
    assert(monitorStatus.textContent.includes('10 seconds'));
    assert.strictEqual(timers.filter(timer => timer.delay === 10000).length, 2);
    fail = true;
    await timers.filter(timer => timer.delay === 10000)[1].callback();
    assert(monitorStatus.textContent.includes('Database failed'));
    assert.strictEqual(timers.filter(timer => timer.delay === 10000).length, 3);
    console.log('Monitoring refresh UI checks passed.');
})().catch(error => { console.error(error); process.exitCode = 1; });
