document.addEventListener('DOMContentLoaded', () => {
    const sidebarData = window.sidebarAnalyticsData;
    const container = document.querySelector('.analytics-section-card, .analytics-sidebar');
    if (!sidebarData || !container) {
        return;
    }

    const chartInstances = [];

    function _alpha(color, a) {
        if (!color) return `rgba(127, 0, 255, ${a})`;
        color = color.trim();
        if (color.startsWith('#')) {
            let hex = color.slice(1);
            if (hex.length === 3) hex = hex.split('').map(c => c + c).join('');
            const r = parseInt(hex.slice(0, 2), 16) || 0;
            const g = parseInt(hex.slice(2, 4), 16) || 0;
            const b = parseInt(hex.slice(4, 6), 16) || 0;
            return `rgba(${r},${g},${b},${a})`;
        }
        const match = color.match(/^rgba?\s*\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)/i);
        if (match) {
            return `rgba(${match[1]},${match[2]},${match[3]},${a})`;
        }
        return color;
    }

    const chartLabels = rows => (rows || []).map(row => row.label || 'Other');
    const chartValues = rows => (rows || []).map(row => Number(row.total || 0));
    const formatMetric = value => (value === null || value === undefined ? 'N/A' : Number(value).toLocaleString());
    const formatMethod = m => {
        if (!m || m === 'N/A') return 'N/A';
        const map = {
            'standard_paragraph': 'Paragraph',
            'bullet_points': 'Bullets',
            'hybrid': 'Hybrid',
            'executive_summary': 'Executive',
            'academic_summary': 'Academic',
            'simple_summary': 'Simple',
            'technical_summary': 'Technical',
            'news_summary': 'News'
        };
        return map[m] || m.replace(/_/g, ' ');
    };

    let latestData = sidebarData;

    const renderCharts = data => {
        latestData = data;
        const empty = Number(data.kpis?.summaries || 0) === 0;
        const emptyMsg = container.querySelector('.analytics-sidebar__empty');
        if (emptyMsg) {
            emptyMsg.hidden = !empty;
        }

        container.querySelectorAll('.analytics-sidebar__chart').forEach(node => {
            node.hidden = empty;
        });

        container.querySelectorAll('[data-analytics]').forEach(node => {
            const key = node.dataset.analytics;
            if (!data.kpis) return;
            node.textContent = key === 'reduction' && data.kpis[key] !== null
                ? `${data.kpis[key]}%`
                : key === 'method'
                    ? formatMethod(data.kpis[key])
                    : formatMetric(data.kpis[key]);
        });

        if (typeof Chart === 'undefined') return;
        chartInstances.splice(0).forEach(chart => chart.destroy());
        if (empty) return;

        const isDark  = document.documentElement.dataset.theme === 'dark';
        const _style  = getComputedStyle(document.documentElement);
        const _accent = (_style.getPropertyValue('--color-accent')         || (isDark ? '#9d87f5' : '#7F00FF')).trim();
        const _inkSec = (_style.getPropertyValue('--color-text-secondary') || (isDark ? '#cdd1c9' : '#4f554d')).trim();
        const _muted  = (_style.getPropertyValue('--color-text-muted')     || (isDark ? '#a6afa1' : '#6e756b')).trim();
        const _border = (_style.getPropertyValue('--color-border')         || (isDark ? '#3d4339' : '#c8c5bb')).trim();
        const _grid   = isDark ? 'rgba(255, 255, 255, 0.08)' : 'rgba(8, 8, 8, 0.05)';
        const chartColors = isDark
            ? [_accent, '#38bdf8', '#34d399', '#fbbf24', '#f472b6']
            : [_accent, '#3B82F6', '#10B981', '#F59E0B', '#8B5CF6'];

        const base = {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: {
                    backgroundColor: isDark ? '#242723' : '#080808',
                    titleColor: '#ffffff',
                    bodyColor: '#ffffff',
                    borderColor: _border,
                    borderWidth: isDark ? 1 : 0,
                    padding: 8,
                    cornerRadius: 4,
                    titleFont: { size: 10, family: 'Inter, sans-serif', weight: '600' },
                    bodyFont: { size: 10, family: 'Inter, sans-serif' }
                }
            }
        };

        const activityCanvas = document.getElementById('sidebarActivityChart');
        if (activityCanvas && data.trend) {
            chartInstances.push(new Chart(activityCanvas, {
                type: 'line',
                data: {
                    labels: data.trend.map(row => row.bucket),
                    datasets: [{
                        data: chartValues(data.trend),
                        borderColor: _accent,
                        backgroundColor: _alpha(_accent, 0.12),
                        fill: true,
                        tension: 0.3,
                        borderWidth: 2,
                        pointRadius: 2,
                        pointHoverRadius: 4,
                        pointBackgroundColor: _accent
                    }]
                },
                options: {
                    ...base,
                    scales: {
                        x: {
                            ticks: { maxTicksLimit: 6, maxRotation: 0, color: _muted, font: { size: 9, family: 'Inter, sans-serif' } },
                            grid: { display: false }
                        },
                        y: {
                            beginAtZero: true,
                            ticks: { precision: 0, color: _muted, font: { size: 9, family: 'Inter, sans-serif' } },
                            grid: { color: _grid }
                        }
                    }
                }
            }));
        }

        const wordsCanvas = document.getElementById('sidebarWordsChart');
        if (wordsCanvas && data.words_comparison) {
            chartInstances.push(new Chart(wordsCanvas, {
                type: 'bar',
                data: {
                    labels: ['Words'],
                    datasets: [
                        { label: 'Original', data: [Number(data.words_comparison.original_words || 0)], backgroundColor: isDark ? 'rgba(255, 255, 255, 0.22)' : 'rgba(8, 8, 8, 0.25)', borderRadius: 3, borderSkipped: false },
                        { label: 'Summary', data: [Number(data.words_comparison.summary_words || 0)], backgroundColor: _accent, borderRadius: 3, borderSkipped: false }
                    ]
                },
                options: {
                    ...base,
                    plugins: {
                        ...base.plugins,
                        legend: { display: true, labels: { boxWidth: 8, boxHeight: 8, color: _muted, font: { size: 10, family: 'Inter, sans-serif' } } }
                    },
                    scales: {
                        x: { ticks: { color: _muted, font: { size: 9, family: 'Inter, sans-serif' } }, grid: { display: false } },
                        y: { beginAtZero: true, ticks: { color: _muted, font: { size: 9, family: 'Inter, sans-serif' } }, grid: { color: _grid } }
                    }
                }
            }));
        }

        const categoryCanvas = document.getElementById('sidebarCategoryChart');
        if (categoryCanvas && data.categories) {
            chartInstances.push(new Chart(categoryCanvas, {
                type: 'doughnut',
                data: {
                    labels: chartLabels(data.categories),
                    datasets: [{ data: chartValues(data.categories), backgroundColor: chartColors, borderWidth: 0 }]
                },
                options: {
                    ...base,
                    plugins: {
                        ...base.plugins,
                        legend: { display: true, position: 'bottom', labels: { boxWidth: 8, boxHeight: 8, color: _muted, font: { size: 9, family: 'Inter, sans-serif' } } }
                    },
                    cutout: '68%'
                }
            }));
        }

        const methodCanvas = document.getElementById('sidebarMethodChart');
        if (methodCanvas && data.styles) {
            chartInstances.push(new Chart(methodCanvas, {
                type: 'bar',
                data: {
                    labels: chartLabels(data.styles),
                    datasets: [{ data: chartValues(data.styles), backgroundColor: _alpha(_accent, 0.65), borderRadius: 3, borderSkipped: false }]
                },
                options: {
                    ...base,
                    indexAxis: 'y',
                    scales: {
                        x: { beginAtZero: true, ticks: { precision: 0, color: _muted, font: { size: 9, family: 'Inter, sans-serif' } }, grid: { color: _grid } },
                        y: { ticks: { color: _muted, font: { size: 9, family: 'Inter, sans-serif' } }, grid: { display: false } }
                    }
                }
            }));
        }
    };

    renderCharts(sidebarData);

    window.addEventListener('themechange', () => {
        if (latestData) {
            renderCharts(latestData);
        }
    });

    container.querySelectorAll('.analytics-range').forEach(button => {
        button.addEventListener('click', async () => {
            const adminRangeForm = document.getElementById('admin-stats-range-form');
            const adminRangeSelect = adminRangeForm?.querySelector('[name="stats_range"]');
            if (adminRangeForm && adminRangeSelect) {
                adminRangeSelect.value = button.dataset.range;
                if (typeof adminRangeForm.requestSubmit === 'function') {
                    adminRangeForm.requestSubmit();
                } else {
                    adminRangeForm.submit();
                }
                return;
            }

            container.querySelectorAll('.analytics-range').forEach(item => item.classList.remove('is-active'));
            container.querySelectorAll('.analytics-range').forEach(item => item.setAttribute('aria-pressed', 'false'));
            button.classList.add('is-active');
            button.setAttribute('aria-pressed', 'true');
            container.classList.add('is-loading');
            try {
                const response = await fetch(`analytics.php?action=data&range=${encodeURIComponent(button.dataset.range)}`, { headers: { Accept: 'application/json' } });
                const data = await response.json();
                if (data && data.redirect) {
                    window.location.href = data.redirect;
                    return;
                }
                if (!response.ok || data.error) throw new Error(data.error || 'Analytics unavailable');
                renderCharts(data);
            } catch (error) {
                const emptyMsg = container.querySelector('.analytics-sidebar__empty');
                if (emptyMsg) {
                    emptyMsg.textContent = 'Analytics data could not be loaded. Please try again.';
                    emptyMsg.hidden = false;
                }
            } finally {
                container.classList.remove('is-loading');
            }
        });
    });
});
