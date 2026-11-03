(function () {
    const data = window.analyticsData;
    const error = document.getElementById('analytics-chart-error');
    if (!data) { if (error) error.hidden = false; return; }
    if (typeof Chart === 'undefined') { if (error) error.hidden = false; return; }

    function getThemePalette() {
        const style = getComputedStyle(document.documentElement);
        const token = name => style.getPropertyValue(name).trim();
        const accent = token('--color-accent');
        const tickColor = token('--color-chart-label');
        const legendColor = token('--color-text');
        const gridColor = token('--color-chart-grid');
        const colors = Array.from({ length: 6 }, (_, index) => token(`--color-chart-${index + 1}`));
        const nutshell = colors[1];
        return { accent, tickColor, legendColor, gridColor, nutshell,
            nutshellBg: withAlpha(nutshell, 0.16), colors,
            tooltipBg: token('--color-surface'), tooltipText: token('--color-text'),
            tooltipBorder: token('--color-border') };
    }

    function withAlpha(color, a) {
        if (!color) color = getComputedStyle(document.documentElement).getPropertyValue('--color-accent').trim();
        color = color.trim();
        if (color.startsWith('#')) {
            let hex = color.slice(1);
            if (hex.length === 3) hex = hex.split('').map(c => c + c).join('');
            const r = parseInt(hex.slice(0, 2), 16) || 0;
            const g = parseInt(hex.slice(2, 4), 16) || 0;
            const b = parseInt(hex.slice(4, 6), 16) || 0;
            return `rgba(${r}, ${g}, ${b}, ${a})`;
        }
        const match = color.match(/^rgba?\s*\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)/i);
        if (match) {
            return `rgba(${match[1]}, ${match[2]}, ${match[3]}, ${a})`;
        }
        return color;
    }

    function formatDateLabel(rawLabel) {
        if (typeof rawLabel === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(rawLabel)) {
            const parts = rawLabel.split('-');
            const monthNames = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
            const m = parseInt(parts[1], 10) - 1;
            const d = parseInt(parts[2], 10);
            if (m >= 0 && m < 12) {
                return `${monthNames[m]} ${d}`;
            }
        }
        return rawLabel;
    }

    const labels = (rows, map) => rows.map(row => map[row.label] || (row.label ? row.label.charAt(0).toUpperCase() + row.label.slice(1) : 'Other'));
    const values = rows => rows.map(row => Number(row.total));

    const activeCharts = {};

    function renderAllCharts() {
        const p = getThemePalette();
        const style = getComputedStyle(document.documentElement);

        Chart.defaults.font.family = style.getPropertyValue('--font-body').trim();
        Chart.defaults.color = p.tickColor;
        Chart.defaults.borderColor = p.gridColor;

        const base = {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    labels: {
                        usePointStyle: true,
                        boxWidth: 8,
                        pointStyleWidth: 8,
                        color: p.legendColor,
                        font: { size: 11, weight: '600', family: style.getPropertyValue('--font-body').trim() }
                    }
                },
                tooltip: {
                    backgroundColor: p.tooltipBg,
                    titleColor: p.tooltipText,
                    bodyColor: p.tooltipText,
                    borderColor: p.tooltipBorder,
                    borderWidth: 1,
                    padding: 8,
                    cornerRadius: 4,
                    callbacks: {
                        title: function (items) {
                            if (!items || !items.length) return '';
                            return formatDateLabel(items[0].label);
                        }
                    }
                }
            }
        };

        function fieldGuideScales(opts) {
            opts = opts || {};
            const xOpts = opts.x || {};
            const yOpts = opts.y || {};
            return {
                x: {
                    grid: { color: p.gridColor, drawBorder: false, ...(xOpts.grid || {}) },
                    ticks: {
                        color: p.tickColor,
                        font: { size: 10, weight: '500', family: style.getPropertyValue('--font-body').trim() },
                        maxRotation: 45,
                        autoSkip: true,
                        maxTicksLimit: 12,
                        callback: function (val) {
                            const rawLabel = this.getLabelForValue(val);
                            return formatDateLabel(rawLabel);
                        },
                        ...(xOpts.ticks || {})
                    },
                    ...xOpts
                },
                y: {
                    grid: { color: p.gridColor, drawBorder: false, ...(yOpts.grid || {}) },
                    ticks: {
                        color: p.tickColor,
                        font: { size: 10, weight: '500', family: style.getPropertyValue('--font-body').trim() },
                        ...(yOpts.ticks || {})
                    },
                    ...yOpts
                }
            };
        }

        function draw(id, config) {
            const canvas = document.getElementById(id);
            if (!canvas) return;
            if (activeCharts[id]) {
                activeCharts[id].destroy();
            }
            try {
                activeCharts[id] = new Chart(canvas, config);
            } catch (e) {
                canvas.closest('.chart-card')?.classList.add('chart-failed');
            }
        }

        draw('activityChart', {
            type: 'line',
            data: {
                labels: data.trend.map(row => row.bucket),
                datasets: [{
                    label: 'Summaries',
                    data: values(data.trend),
                    borderColor: p.accent,
                    backgroundColor: withAlpha(p.accent, 0.20),
                    fill: true,
                    tension: 0.25,
                    borderWidth: 2.5,
                    pointRadius: 2,
                    pointHoverRadius: 5,
                    pointBackgroundColor: p.accent,
                    pointBorderColor: p.accent
                }]
            },
            options: {
                ...base,
                scales: {
                    ...fieldGuideScales({
                        y: { beginAtZero: true, ticks: { precision: 0, color: p.tickColor } }
                    })
                }
            }
        });

        draw('nutshellChart', {
            type: 'line',
            data: {
                labels: data.nutshellTrend.map(row => row.bucket),
                datasets: [{
                    label: 'Nutshells',
                    data: values(data.nutshellTrend),
                    borderColor: p.nutshell,
                    backgroundColor: p.nutshellBg,
                    fill: true,
                    tension: 0.25,
                    borderWidth: 2.5,
                    pointRadius: 2,
                    pointHoverRadius: 5,
                    pointBackgroundColor: p.nutshell,
                    pointBorderColor: p.nutshell
                }]
            },
            options: {
                ...base,
                scales: {
                    ...fieldGuideScales({
                        y: { beginAtZero: true, ticks: { precision: 0, color: p.tickColor } }
                    })
                }
            }
        });

        draw('styleChart', {
            type: 'doughnut',
            data: {
                labels: labels(data.styles, data.styleLabels),
                datasets: [{
                    data: values(data.styles),
                    backgroundColor: p.colors,
                    borderWidth: 0
                }]
            },
            options: { ...base, cutout: '64%' }
        });

        draw('lengthChart', {
            type: 'bar',
            data: {
                labels: labels(data.lengths, {}),
                datasets: [{
                    label: 'Summaries',
                    data: values(data.lengths),
                    backgroundColor: withAlpha(p.accent, 0.85),
                    borderRadius: 4,
                    borderSkipped: false
                }]
            },
            options: {
                ...base,
                plugins: { legend: { display: false } },
                scales: {
                    ...fieldGuideScales({
                        x: { ticks: { color: p.tickColor } },
                        y: { beginAtZero: true, ticks: { precision: 0, color: p.tickColor } }
                    })
                }
            }
        });

        draw('wordsChart', {
            type: 'bar',
            data: {
                labels: ['Words'],
                datasets: [
                    {
                        label: 'Original',
                        data: [Number(data.words.original_words || 0)],
                        backgroundColor: withAlpha(p.tickColor, 0.25),
                        borderColor: withAlpha(p.tickColor, 0.45),
                        borderWidth: 1,
                        borderRadius: 4,
                        borderSkipped: false
                    },
                    {
                        label: 'Summary',
                        data: [Number(data.words.summary_words || 0)],
                        backgroundColor: p.accent,
                        borderRadius: 4,
                        borderSkipped: false
                    }
                ]
            },
            options: {
                ...base,
                plugins: {
                    legend: {
                        labels: {
                            color: p.legendColor,
                            font: { size: 11, weight: '600' }
                        }
                    }
                },
                scales: { ...fieldGuideScales({ y: { beginAtZero: true, ticks: { color: p.tickColor } } }) }
            }
        });

        draw('typeChart', {
            type: 'bar',
            data: {
                labels: labels(data.types, data.typeLabels),
                datasets: [{
                    label: 'Processed',
                    data: values(data.types),
                    backgroundColor: withAlpha(p.accent, 0.85),
                    borderRadius: 4,
                    borderSkipped: false
                }]
            },
            options: {
                ...base,
                indexAxis: 'y',
                plugins: { legend: { display: false } },
                scales: {
                    ...fieldGuideScales({
                        x: { beginAtZero: true, ticks: { precision: 0, color: p.tickColor } },
                        y: { ticks: { color: p.tickColor } }
                    })
                }
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', renderAllCharts, { once: true });
    } else {
        renderAllCharts();
    }

    window.addEventListener('themechange', () => {
        renderAllCharts();
    });

}());
