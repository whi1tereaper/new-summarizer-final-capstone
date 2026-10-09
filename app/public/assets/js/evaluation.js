(() => {
  'use strict';
  const dataNode = document.getElementById('evaluation-chart-data');
  const canvas = document.getElementById('evaluation-compression-chart');
  if (!dataNode || !canvas || typeof Chart === 'undefined') return;
  const rows = JSON.parse(dataNode.textContent).filter(row => row.metric === 'compression_ratio' && row.mean !== null);
  if (!rows.length) return;
  const modes = ['brief', 'balanced', 'detailed', 'comprehensive'];
  const styles = getComputedStyle(document.documentElement);
  const colors = ['--color-accent', '--color-text', '--color-text-secondary', '--color-text-muted'];
  const systems = [...new Set(rows.map(row => row.system))];
  const datasets = systems.map((system, index) => ({
    label: system.replaceAll('_', ' '),
    data: modes.map(mode => rows.find(row => row.system === system && row.mode === mode)?.mean ?? null),
    backgroundColor: styles.getPropertyValue(colors[index % colors.length]).trim(),
    borderWidth: 1
  }));
  new Chart(canvas, {
    type: 'bar', data: {labels: modes, datasets},
    options: {responsive: true, maintainAspectRatio: false, animation: false,
      scales: {y: {beginAtZero: true, title: {display: true, text: 'Summary words / source words'}}},
      plugins: {legend: {position: 'bottom'}}}
  });
})();
