/**
 * Dialoga Analytics Charts
 * Initializes Chart.js visualizations on the Analytics admin page.
 * Reads data from window.dialogaAnalytics (set inline in analytics.php template).
 */
(function () {
    function t(message) {
        var i18n = window.dialogaAdmin && window.dialogaAdmin.i18n;
        return i18n && Object.prototype.hasOwnProperty.call(i18n, message) ? i18n[message] : message;
    }

    if (typeof Chart === 'undefined') return;
    var data = window.dialogaAnalytics || {};

    Chart.defaults.font.family = "-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif";
    Chart.defaults.color = '#64748B';
    Chart.defaults.borderColor = '#E5E7EF';

    // 1. Daily conversation trend — line chart
    var trendEl = document.getElementById('dialoga-chart-trend');
    if (trendEl && data.trend && data.trend.labels && data.trend.labels.length) {
        new Chart(trendEl, {
            type: 'line',
            data: {
                labels: data.trend.labels,
                datasets: [{
                    label: t('Conversations'),
                    data: data.trend.values,
                    borderColor: '#00ADA1',
                    backgroundColor: 'rgba(59, 130, 246, 0.12)',
                    tension: 0.3,
                    fill: true,
                    pointRadius: 3,
                    pointHoverRadius: 5,
                    pointBackgroundColor: '#00ADA1',
                    borderWidth: 2
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: { backgroundColor: '#26233A', padding: 10, titleFont: { size: 13 }, bodyFont: { size: 13 } }
                },
                scales: {
                    y: { beginAtZero: true, ticks: { precision: 0 }, grid: { color: '#F1F5F9' } },
                    x: { grid: { display: false } }
                }
            }
        });
    }

    // 2. Conversion split — doughnut chart
    var donutEl = document.getElementById('dialoga-chart-conversion');
    if (donutEl && data.conversion) {
        var total = (data.conversion.converted || 0) + (data.conversion.not_converted || 0);
        new Chart(donutEl, {
            type: 'doughnut',
            data: {
                labels: [t('Converted'), t('Not converted')],
                datasets: [{
                    data: total > 0 ? [data.conversion.converted, data.conversion.not_converted] : [0, 1],
                    backgroundColor: total > 0 ? ['#10B981', '#E5E7EF'] : ['#E5E7EF', '#E5E7EF'],
                    borderWidth: 0,
                    hoverOffset: 6
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '68%',
                plugins: {
                    legend: { position: 'bottom', labels: { padding: 16, boxWidth: 12, boxHeight: 12, usePointStyle: true, pointStyle: 'circle' } },
                    tooltip: {
                        backgroundColor: '#26233A',
                        padding: 10,
                        callbacks: {
                            label: function (ctx) {
                                if (total === 0) return t('No data yet');
                                var pct = Math.round((ctx.parsed / total) * 100);
                                return ctx.label + ': ' + ctx.parsed + ' (' + pct + '%)';
                            }
                        }
                    }
                }
            }
        });
    }

    // 3. Top source pages — horizontal bar
    var sourcesEl = document.getElementById('dialoga-chart-sources');
    if (sourcesEl && data.sources && data.sources.labels && data.sources.labels.length) {
        new Chart(sourcesEl, {
            type: 'bar',
            data: {
                labels: data.sources.labels,
                datasets: [{
                    label: t('Conversations'),
                    data: data.sources.values,
                    backgroundColor: '#8B5CF6',
                    borderRadius: 4,
                    barThickness: 18
                }]
            },
            options: {
                indexAxis: 'y',
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: { backgroundColor: '#26233A', padding: 10 }
                },
                scales: {
                    x: { beginAtZero: true, ticks: { precision: 0 }, grid: { color: '#F1F5F9' } },
                    y: { grid: { display: false } }
                }
            }
        });
    }
})();
