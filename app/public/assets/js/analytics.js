(function () {
    const data = window.analyticsData;
    const error = document.getElementById('analytics-chart-error');
    if (!data) { if (error) error.hidden = false; return; }
    if (typeof Chart === 'undefined') { if (error) error.hidden = false; return; }

    // Read palette from CSS tokens so charts match the design system
    const style = getComputedStyle(document.documentElement);
    const ink      = (style.getPropertyValue('--color-text')          || '#1d192b').trim();
    const accent   = (style.getPropertyValue('--color-accent')        || '#6d4ddb').trim();
    const muted    = (style.getPropertyValue('--color-text-muted')    || '#79748b').trim();
    const border   = (style.getPropertyValue('--color-border')        || '#dcd6eb').trim();
    const inkSec   = (style.getPropertyValue('--color-text-secondary')|| '#49455a').trim();
    const success  = (style.getPropertyValue('--color-success')       || '#2e7d58').trim();

    // Multi-series palette: royal purple + tonal lavender neutrals
    const colors = [accent, inkSec, muted, success, border, ink];

    function withAlpha(hex, a) {
        const r = parseInt(hex.slice(1,3),16), g = parseInt(hex.slice(3,5),16), b = parseInt(hex.slice(5,7),16);
        return `rgba(${r},${g},${b},${a})`;
    }

    const labels = (rows, map) => rows.map(row => map[row.label] || (row.label ? row.label.charAt(0).toUpperCase() + row.label.slice(1) : 'Other'));
    const values = rows => rows.map(row => Number(row.total));

    Chart.defaults.font.family = style.getPropertyValue('--font-body').trim() || 'Inter, system-ui, sans-serif';
    Chart.defaults.color = muted;
    Chart.defaults.borderColor = border;

    const base = {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { labels: { usePointStyle: true, boxWidth: 8, color: inkSec, pointStyleWidth: 8 } }
        },
        scales: {}
    };

    function fieldGuideScales(opts) {
        return {
            x: { grid: { color: border, drawBorder: false }, ticks: { color: muted }, ...((opts||{}).x||{}) },
            y: { grid: { color: border, drawBorder: false }, ticks: { color: muted }, ...((opts||{}).y||{}) }
        };
    }

    function draw(id, config) {
        const canvas = document.getElementById(id);
        if (!canvas) return;
        try { new Chart(canvas, config); }
        catch (e) { canvas.closest('.chart-card').classList.add('chart-failed'); }
    }

    draw('activityChart', {
        type: 'line',
        data: {
            labels: data.trend.map(row => row.bucket),
            datasets: [{ label: 'Summaries', data: values(data.trend), borderColor: accent, backgroundColor: withAlpha(accent, .08), fill: true, tension: .25, borderWidth: 1.5, pointRadius: 2 }]
        },
        options: { ...base, scales: { ...fieldGuideScales({ y: { beginAtZero: true, ticks: { precision: 0, color: muted } } }) } }
    });

    draw('nutshellChart', {
        type: 'line',
        data: {
            labels: data.nutshellTrend.map(row => row.bucket),
            datasets: [{ label: 'Nutshells', data: values(data.nutshellTrend), borderColor: inkSec, backgroundColor: withAlpha(inkSec, .08), fill: true, tension: .25, borderWidth: 1.5, pointRadius: 2 }]
        },
        options: { ...base, scales: { ...fieldGuideScales({ y: { beginAtZero: true, ticks: { precision: 0, color: muted } } }) } }
    });

    draw('styleChart', {
        type: 'doughnut',
        data: {
            labels: labels(data.styles, data.styleLabels),
            datasets: [{ data: values(data.styles), backgroundColor: colors, borderWidth: 0 }]
        },
        options: { ...base, cutout: '64%' }
    });

    draw('lengthChart', {
        type: 'bar',
        data: {
            labels: labels(data.lengths, {}),
            datasets: [{ label: 'Summaries', data: values(data.lengths), backgroundColor: withAlpha(accent, .75), borderRadius: 2, borderSkipped: false }]
        },
        options: { ...base, plugins: { legend: { display: false } }, scales: { ...fieldGuideScales({ y: { beginAtZero: true, ticks: { precision: 0, color: muted } } }) } }
    });

    draw('wordsChart', {
        type: 'bar',
        data: {
            labels: ['Words'],
            datasets: [
                { label: 'Original', data: [Number(data.words.original_words || 0)], backgroundColor: inkSec, borderRadius: 2, borderSkipped: false },
                { label: 'Summary',  data: [Number(data.words.summary_words  || 0)], backgroundColor: accent, borderRadius: 2, borderSkipped: false }
            ]
        },
        options: { ...base, scales: { ...fieldGuideScales({ y: { beginAtZero: true } }) } }
    });

    draw('typeChart', {
        type: 'bar',
        data: {
            labels: labels(data.types, data.typeLabels),
            datasets: [{ label: 'Processed', data: values(data.types), backgroundColor: withAlpha(inkSec, .75), borderRadius: 2, borderSkipped: false }]
        },
        options: { ...base, indexAxis: 'y', plugins: { legend: { display: false } }, scales: { ...fieldGuideScales({ x: { beginAtZero: true, ticks: { precision: 0, color: muted } } }) } }
    });
}());
