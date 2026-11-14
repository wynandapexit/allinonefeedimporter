const { execFileSync } = require('child_process');
const vm = require('vm');
const assert = require('assert');

const html = execFileSync('php', ['-d', 'xdebug.mode=off', __dirname + '/job-ui-fixture.php'], { encoding: 'utf8' });
const script = html.match(/<script>([\s\S]*?)<\/script>/)[1];
const status = { textContent: 'running' };
const progress = { textContent: '' };
const counts = { textContent: '' };
const text = { textContent: '' };
const bar = { value: 0 };
const row = {
    dataset: { a1fiJobRow: '8' },
    querySelector(selector) {
        return {
            '.a1fi-job-status': status,
            '.a1fi-job-progress span': progress,
            '.a1fi-job-progress small': counts,
        }[selector] || null;
    },
};
const live = {
    dataset: { jobId: '8' },
    querySelector(selector) { return selector === 'progress' ? bar : text; },
};
const template = { content: { cloneNode() { return {}; } } };
const document = {
    querySelector(selector) { return selector === '.wrap' ? { appendChild() {} } : null; },
    querySelectorAll() { return [row]; },
    getElementById(id) { return id === 'a1fi-jobs-template' ? template : live; },
    addEventListener() {},
};
const requests = [];
const job = { id: 8, status: 'running', processed: 10, total: 20, created: 8, updated: 2, skipped: 0, failed: 0, message: '', errors: [] };
const timers = [];
const context = {
    document, URL, FormData, console,
    window: { location: { origin: 'https://example.test' } },
    ajaxurl: '/wp-admin/admin-ajax.php',
    setTimeout(callback) { timers.push(callback); },
    fetch: async (url, options) => {
        const action = options.body.get('action');
        requests.push(action);
        return { json: async () => ({ success: true, data: action === 'a1fi_job_status' ? { ...job } : { status: job.status } }) };
    },
};
(async () => {
    vm.runInNewContext(script, context);
    await new Promise(resolve => setImmediate(resolve));
    assert(requests.includes('a1fi_job_status'));
    assert.strictEqual(progress.textContent, '10 / 20 (50%)');
    assert.strictEqual(bar.value, 50);
    assert(text.textContent.includes('10 / 20 (50%)'));
    job.status = 'completed';
    job.processed = 20;
    const refresh = timers.pop();
    refresh();
    await new Promise(resolve => setImmediate(resolve));
    assert.strictEqual(status.textContent, 'completed');
    assert.strictEqual(bar.value, 100);
    console.log('Job UI checks passed.');
})().catch(error => { console.error(error); process.exitCode = 1; });
