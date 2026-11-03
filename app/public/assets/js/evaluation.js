(() => {
  'use strict';
  const dataNode = document.getElementById('evaluation-chart-data');
  const canvas = document.getElementById('evaluation-compression-chart');
  if (!dataNode || !canvas || typeof Chart === 'undefined') return;
  const rows = JSON.parse(dataNode.textContent).filter(row => row.metric === 'compression_ratio' && row.mean !== null);
  if (!rows.length) return;
  const modes = ['brief', 'balanced', 'detailed', 'comprehensive'];
  const systems = [...new Set(rows.map(row => row.system))];
  let chart;
  function render() {
    chart?.destroy();
    const styles = getComputedStyle(document.documentElement);
    const token = name => styles.getPropertyValue(name).trim();
    Chart.defaults.font.family = token('--font-body');
    Chart.defaults.color = token('--color-chart-label');
    Chart.defaults.borderColor = token('--color-chart-grid');
    const datasets = systems.map((system, index) => ({
      label: system.replaceAll('_', ' '),
      data: modes.map(mode => rows.find(row => row.system === system && row.mode === mode)?.mean ?? null),
      backgroundColor: token(`--color-chart-${index % 6 + 1}`), borderWidth: 1
    }));
    chart = new Chart(canvas, {
      type: 'bar', data: { labels: modes, datasets },
      options: { responsive: true, maintainAspectRatio: false, animation: false,
        scales: { y: { beginAtZero: true, title: { display: true, text: 'Summary words / source words' } } },
        plugins: { legend: { position: 'bottom' } }
      }
    });
  }
  render();
  window.addEventListener('themechange', render);
})();
