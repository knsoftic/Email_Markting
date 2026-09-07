import './bootstrap';
import blockBuilder from './block-builder';

import Alpine from 'alpinejs';
import {
    Chart,
    ArcElement,
    BarController,
    BarElement,
    CategoryScale,
    DoughnutController,
    Filler,
    Legend,
    LineController,
    LineElement,
    LinearScale,
    PointElement,
    Tooltip,
} from 'chart.js';

window.Alpine = Alpine;

// Only the controllers actually used are registered, which keeps the bundle
// well under the size a full `chart.js/auto` import would add.
Chart.register(
    ArcElement,
    BarController,
    BarElement,
    CategoryScale,
    DoughnutController,
    Filler,
    Legend,
    LineController,
    LineElement,
    LinearScale,
    PointElement,
    Tooltip,
);

Chart.defaults.font.family =
    'Figtree, Inter, ui-sans-serif, system-ui, -apple-system, "Segoe UI", sans-serif';
Chart.defaults.font.size = 12;
Chart.defaults.color = '#64748b';
Chart.defaults.plugins.legend.display = false;
Chart.defaults.plugins.tooltip.backgroundColor = '#0f172a';
Chart.defaults.plugins.tooltip.padding = 10;
Chart.defaults.plugins.tooltip.cornerRadius = 6;
Chart.defaults.maintainAspectRatio = false;

window.Chart = Chart;

/**
 * Reads the brand colour the layout wrote into :root, so charts follow the
 * admin-configured branding instead of a hard-coded blue.
 */
const brandColor = (shade = 600, alpha = 1) => {
    const triplet = getComputedStyle(document.documentElement)
        .getPropertyValue(`--brand-${shade}`)
        .trim();

    return triplet ? `rgb(${triplet} / ${alpha})` : `rgba(29, 78, 216, ${alpha})`;
};

window.knBrandColor = brandColor;

/**
 * Declarative chart binding: any <canvas data-chart='{...}'> in a Blade view
 * gets rendered, with no per-page script. The JSON holds
 * { type, labels, datasets: [{ label, data, color }], options }.
 */
const renderCharts = (root = document) => {
    root.querySelectorAll('canvas[data-chart]').forEach((canvas) => {
        if (canvas.dataset.chartRendered) return;

        let config;
        try {
            config = JSON.parse(canvas.dataset.chart);
        } catch (e) {
            console.error('Invalid chart config on', canvas, e);
            return;
        }

        const type = config.type || 'line';
        const palette = [
            brandColor(600),
            '#0ea5e9',
            '#10b981',
            '#f59e0b',
            '#ef4444',
            '#8b5cf6',
        ];

        const datasets = (config.datasets || []).map((set, index) => {
            const color = set.color || palette[index % palette.length];

            if (type === 'line') {
                return {
                    label: set.label || '',
                    data: set.data || [],
                    borderColor: color,
                    backgroundColor: brandColor(600, 0.08),
                    borderWidth: 2,
                    pointRadius: 0,
                    pointHoverRadius: 4,
                    tension: 0.35,
                    fill: true,
                };
            }

            if (type === 'doughnut') {
                return {
                    label: set.label || '',
                    data: set.data || [],
                    backgroundColor: set.colors || palette,
                    borderWidth: 0,
                };
            }

            return {
                label: set.label || '',
                data: set.data || [],
                backgroundColor: color,
                borderRadius: 4,
                maxBarThickness: 28,
            };
        });

        const scales =
            type === 'doughnut'
                ? {}
                : {
                      x: { grid: { display: false }, border: { display: false } },
                      y: {
                          beginAtZero: true,
                          border: { display: false },
                          grid: { color: '#e2e8f0' },
                          ticks: { precision: 0 },
                      },
                  };

        new Chart(canvas, {
            type,
            data: { labels: config.labels || [], datasets },
            options: {
                responsive: true,
                scales,
                plugins: {
                    legend: {
                        display: type === 'doughnut',
                        position: 'bottom',
                        labels: { boxWidth: 10, boxHeight: 10, usePointStyle: true },
                    },
                },
                ...(config.options || {}),
            },
        });

        canvas.dataset.chartRendered = '1';
    });
};

document.addEventListener('DOMContentLoaded', () => renderCharts());
window.knRenderCharts = renderCharts;

/**
 * Checkbox-table selection helper used by the contacts screens.
 *
 * Pairs with the x-bulk-bar Blade component: the wrapper element carries
 * x-data="knBulkSelect()", each row checkbox is
 *   <input type="checkbox" :value="id" @change="sync($event)">
 * and the header checkbox is
 *   <input type="checkbox" x-model="all" @change="toggleAll()">
 */
window.knBulkSelect = () => ({
    selected: [],
    all: false,

    rowBoxes() {
        return Array.from(this.$el.querySelectorAll('input[type=checkbox][data-row-id]'));
    },

    sync() {
        this.selected = this.rowBoxes().filter((b) => b.checked).map((b) => b.dataset.rowId);
        const boxes = this.rowBoxes();
        this.all = boxes.length > 0 && this.selected.length === boxes.length;
    },

    toggleAll() {
        this.rowBoxes().forEach((b) => { b.checked = this.all });
        this.sync();
    },

    clearAll() {
        this.all = false;
        this.rowBoxes().forEach((b) => { b.checked = false });
        this.selected = [];
    },

    isSelected(id) {
        return this.selected.includes(String(id));
    },
});

// The email block builder, shared by the template and campaign editors.
window.knBlockBuilder = blockBuilder;

// Last, and only last. Alpine.start() walks the document and evaluates every
// x-data expression there and then, so any component factory it might name —
// knBlockBuilder, knBulkSelect — has to be on window before this line runs.
Alpine.start();
