// CRM has its own Livewire runtime entry; do not reuse Live's recovery interceptor.
import { Alpine, Livewire } from '../../vendor/livewire/livewire/dist/livewire.esm';
import Chart from 'chart.js/auto';

Chart.defaults.locale = 'fa-IR';
Chart.defaults.font.family = 'Vazirmatn, sans-serif';
Chart.defaults.color = '#526477';

// Mary UI passes an Alpine-entangled config directly to the Chart constructor.
// Keep Chart.js's internal mutations out of the locked Livewire state.
window.Chart = function (canvas, settings) {
    return new Chart(canvas, JSON.parse(JSON.stringify(settings)));
};

// Mary 2.9.11 recreates the canvas on morph but does not destroy its chart.
Alpine.directive('crm-chart-cleanup', (el, _, { cleanup }) => {
    const canvas = el.querySelector('canvas');
    cleanup(() => {
        if (canvas) Chart.getChart(canvas)?.destroy();
    });
});

Livewire.start();
