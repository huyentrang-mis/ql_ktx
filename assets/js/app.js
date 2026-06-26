// KTX Smart - Main JS

document.addEventListener('DOMContentLoaded', function () {
    // Sidebar toggle
    const toggle = document.getElementById('sidebarToggle');
    const sidebar = document.getElementById('sidebar');
    if (toggle && sidebar) {
        toggle.addEventListener('click', () => sidebar.classList.toggle('open'));
        document.addEventListener('click', (e) => {
            if (!sidebar.contains(e.target) && !toggle.contains(e.target)) {
                sidebar.classList.remove('open');
            }
        });
    }

    // Auto-dismiss alerts
    document.querySelectorAll('.alert-dismissible').forEach(alert => {
        setTimeout(() => {
            const bsAlert = bootstrap.Alert.getOrCreateInstance(alert);
            if (bsAlert) bsAlert.close();
        }, 5000);
    });

    // Confirm dialogs
    document.querySelectorAll('[data-confirm]').forEach(el => {
        el.addEventListener('click', function (e) {
            if (!confirm(this.dataset.confirm || 'Bạn có chắc chắn không?')) {
                e.preventDefault();
            }
        });
    });

    // Initialize charts if present
    initCharts();

    // QR polling
    const qrPoll = document.getElementById('qrPaymentStatus');
    if (qrPoll) {
        startQRPolling(qrPoll.dataset.invoiceId);
    }

    // Number formatting
    document.querySelectorAll('.format-money').forEach(el => {
        const val = parseFloat(el.textContent.replace(/[^\d.]/g, ''));
        if (!isNaN(val)) el.textContent = formatMoney(val);
    });
});

function formatMoney(amount) {
    return new Intl.NumberFormat('vi-VN', { style: 'currency', currency: 'VND' }).format(amount);
}

function initCharts() {
    // Revenue Chart
    const revenueCtx = document.getElementById('revenueChart');
    if (revenueCtx && window.chartLabels) {
        new Chart(revenueCtx, {
            type: 'line',
            data: {
                labels: window.chartLabels,
                datasets: [{
                    label: 'Doanh thu (VNĐ)',
                    data: window.chartData,
                    borderColor: '#2563b0',
                    backgroundColor: 'rgba(37,99,176,0.08)',
                    borderWidth: 2.5,
                    pointBackgroundColor: '#2563b0',
                    pointRadius: 5,
                    tension: 0.4,
                    fill: true
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: ctx => ' ' + new Intl.NumberFormat('vi-VN').format(ctx.parsed.y) + ' đ'
                        }
                    }
                },
                scales: {
                    y: {
                        grid: { color: 'rgba(0,0,0,0.05)' },
                        ticks: {
                            callback: v => new Intl.NumberFormat('vi-VN', { notation: 'compact' }).format(v) + 'đ'
                        }
                    },
                    x: { grid: { display: false } }
                }
            }
        });
    }

    // Payment methods donut chart
    const methodCtx = document.getElementById('methodChart');
    if (methodCtx && window.methodLabels) {
        new Chart(methodCtx, {
            type: 'doughnut',
            data: {
                labels: window.methodLabels,
                datasets: [{
                    data: window.methodData,
                    backgroundColor: ['#2563b0','#f0a500','#0f7e55','#7c3aed','#c0392b'],
                    borderWidth: 0,
                    hoverOffset: 6
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: { font: { family: 'Be Vietnam Pro', size: 12 }, padding: 16 }
                    }
                },
                cutout: '65%'
            }
        });
    }

    // Room occupancy chart
    const occupancyCtx = document.getElementById('occupancyChart');
    if (occupancyCtx && window.occupancyData) {
        new Chart(occupancyCtx, {
            type: 'bar',
            data: {
                labels: window.occupancyLabels,
                datasets: [{
                    label: 'Số phòng',
                    data: window.occupancyData,
                    backgroundColor: ['#2563b0','#0f7e55','#f0a500'],
                    borderRadius: 6,
                }]
            },
            options: {
                responsive: true,
                plugins: { legend: { display: false } },
                scales: {
                    y: { beginAtZero: true, grid: { color: 'rgba(0,0,0,0.05)' } },
                    x: { grid: { display: false } }
                }
            }
        });
    }
}

// QR Payment polling
function startQRPolling(invoiceId) {
    if (!invoiceId) return;
    const statusEl = document.getElementById('paymentStatusText');
    const statusCard = document.getElementById('paymentStatusCard');
    let attempts = 0;
    const maxAttempts = 60; // 5 minutes

    const poll = setInterval(async () => {
        attempts++;
        if (attempts > maxAttempts) { clearInterval(poll); return; }

        try {
            const res = await fetch(`../pages/check_payment.php?invoice_id=${invoiceId}`);
            const data = await res.json();
            if (data.status === 'paid') {
                clearInterval(poll);
                if (statusEl) statusEl.textContent = '✓ Đã thanh toán thành công!';
                if (statusCard) {
                    statusCard.className = 'alert alert-success';
                    statusCard.innerHTML = '<i class="bi bi-check-circle-fill me-2"></i> Thanh toán thành công! Trang sẽ tự động tải lại...';
                }
                setTimeout(() => location.reload(), 2000);
            }
        } catch (e) { /* ignore */ }
    }, 5000); // Poll every 5 seconds
}

// Copy to clipboard
function copyToClipboard(text, btn) {
    navigator.clipboard.writeText(text).then(() => {
        const orig = btn.innerHTML;
        btn.innerHTML = '<i class="bi bi-check-lg"></i>';
        setTimeout(() => btn.innerHTML = orig, 1500);
    });
}

// Preview QR before print
function printQR(invoiceCode) {
    const w = window.open('', '_blank', 'width=400,height=600');
    w.document.write(`<html><body style="text-align:center;font-family:sans-serif;padding:20px">
        <h2>Hóa Đơn: ${invoiceCode}</h2>
        <img src="${document.querySelector('.qr-container img').src}" style="width:250px;height:250px">
        <p>Quét mã để thanh toán</p>
        <script>window.print();window.close();<\/script>
    </body></html>`);
    w.document.close();
}

// Backup progress simulation
function startBackup(btn) {
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span> Đang sao lưu...';
}