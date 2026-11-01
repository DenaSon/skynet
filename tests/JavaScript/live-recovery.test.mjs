import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

const source = readFileSync(new URL('../../resources/js/live.js', import.meta.url), 'utf8')
    .replace(/^import .*;$/m, '');

for (const status of [401, 403, 419, 422, 429, 503, 500, 200]) {
    test(`Livewire error ${status} preserves safe recovery and never retries an action`, () => {
        let handler;
        let started = false;
        const locations = [];
        let prevented = false;
        vm.runInNewContext(source, {
            Livewire: {
                interceptRequest(register) {
                    assert.equal(started, false);
                    register({ onError(callback) { handler = callback; } });
                },
                start() { started = true; },
            },
            window: { location: { assign(url) { locations.push(url); } } },
        });
        assert.equal(started, true);
        handler({ response: { status }, preventDefault() { prevented = true; } });
        const recover = [401, 403, 419, 422, 429, 503].includes(status);
        assert.equal(prevented, recover);
        assert.deepEqual(locations, recover ? [`/?notice=${status}`] : []);
    });
}
