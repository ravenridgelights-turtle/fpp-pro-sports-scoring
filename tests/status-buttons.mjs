import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import vm from 'node:vm';
const page = readFileSync(new URL('../status.php', import.meta.url), 'utf8');
const script = [...page.matchAll(/<script>([\s\S]*?)<\/script>/g)].map(m => m[1]).find(s => s.includes('var triggerUrl'))
    .replace(/<\?=[\s\S]*?\?>/g, 'fpp-nfl');
async function scenario(data, reject = false) {
    const feedback = {textContent: '', classList: {add() {}, remove() {}}};
    const classes = new Set();
    let click, request;
    const button = {textContent: 'Touchdown', disabled: false,
        classList: {add: c => classes.add(c), remove: c => classes.delete(c), contains: c => classes.has(c)},
        closest: () => ({querySelector: () => feedback}),
        getAttribute: key => ({'data-league': 'nfl', 'data-slot': '1', 'data-trigger': 'touchdown'}[key]),
        addEventListener: (_, handler) => {click = handler;}};
    const sandbox = {document: {querySelectorAll: () => [button]}, URLSearchParams,
        fetch: async (url, options) => {request = {url, options}; if (reject) throw Error('offline'); return {ok: true, json: async () => data};}};
    vm.runInNewContext(script, sandbox);
    click.call(button);
    assert.equal(button.disabled, true);
    await new Promise(setImmediate);
    assert.equal(button.disabled, false);
    assert.equal(button.textContent, 'Touchdown');
    assert.equal(request.url, 'plugin.php?plugin=fpp-nfl&page=functions.inc.php&nopage=1');
    assert.equal(request.options.method, 'POST');
    assert.match(request.options.body, /action=manualTrigger/);
    return feedback.textContent;
}
assert.equal(await scenario({ok: true, message: 'Triggered.'}), 'Triggered.');
assert.equal(await scenario({ok: false, message: 'FPP is unavailable.'}), 'FPP is unavailable.');
assert.match(await scenario(null, true), /Unable to trigger/);
console.log('PASS status buttons: success, FPP failure, network failure, request route, busy/reset behavior');
