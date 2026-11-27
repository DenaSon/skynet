import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

const source = readFileSync(new URL('../../resources/js/crm.js', import.meta.url), 'utf8')
    .replace(/^import .*;$/gm, '');

test('CRM charts clone Livewire settings and destroy the exact canvas on removal', () => {
    const charts = new Map();
    const directives = new Map();
    const window = {};
    let started = false;
    let destroyed = 0;
    class Chart {
        static defaults = { font: {} };
        static getChart(canvas) { return charts.get(canvas); }
        constructor(canvas, settings) {
            this.settings = settings;
            settings.data.datasets[0].data.push(99);
            charts.set(canvas, this);
            this.destroy = () => { charts.delete(canvas); destroyed++; };
        }
    }
    vm.runInNewContext(source, {
        Chart, window,
        Alpine: { directive(name, callback) { assert.equal(started, false); directives.set(name, callback); } },
        Livewire: { start() { started = true; } },
    });
    assert.equal(started, true);
    assert.equal(Chart.defaults.locale, 'fa-IR');
    const config = { data: { datasets: [{ data: [1, 2] }] } };
    const cleanup = directives.get('crm-chart-cleanup');
    for (let i = 0; i < 10; i++) {
        const canvas = {};
        let release;
        cleanup({ querySelector: () => canvas }, {}, { cleanup(fn) { release = fn; } });
        new window.Chart(canvas, config);
        assert.equal(charts.size, 1);
        assert.deepEqual(config.data.datasets[0].data, [1, 2]);
        release();
        assert.equal(charts.size, 0);
    }
    assert.equal(destroyed, 10);
});
