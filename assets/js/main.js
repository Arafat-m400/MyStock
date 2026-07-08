// ============================================
// MyStock v2.0 - Main JavaScript
// Offline-Ready
// ============================================

document.addEventListener('DOMContentLoaded', function() {
    
    // ============================================
    // Auto-dismiss alerts
    // ============================================
    setTimeout(function() {
        document.querySelectorAll('.alert:not(.alert-permanent)').forEach(function(alert) {
            var bsAlert = new bootstrap.Alert(alert);
            bsAlert.close();
        });
    }, 5000);
    
    // ============================================
    // Confirm before delete
    // ============================================
    document.querySelectorAll('.confirm-delete').forEach(function(link) {
        link.addEventListener('click', function(e) {
            if (!confirm('Are you sure you want to delete this item? This action cannot be undone.')) {
                e.preventDefault();
            }
        });
    });
    
    // ============================================
    // Mobile sidebar toggle
    // ============================================
    const sidebarToggle = document.getElementById('sidebarToggle');
    const sidebar = document.querySelector('.sidebar');
    const overlay = document.createElement('div');
    overlay.className = 'sidebar-overlay';
    document.body.appendChild(overlay);
    
    if (sidebarToggle) {
        sidebarToggle.addEventListener('click', function() {
            sidebar.classList.toggle('show');
            overlay.classList.toggle('show');
        });
    }
    
    overlay.addEventListener('click', function() {
        sidebar.classList.remove('show');
        overlay.classList.remove('show');
    });
    
    // ============================================
    // Number formatting for inputs
    // ============================================
    document.querySelectorAll('.number-format').forEach(function(input) {
        input.addEventListener('input', function() {
            let value = this.value.replace(/[^0-9.]/g, '');
            if (value) {
                this.value = new Intl.NumberFormat('en-RW').format(value);
            }
        });
    });
    
    // ============================================
    // Quick search/filter
    // ============================================
    document.querySelectorAll('.search-input').forEach(function(input) {
        input.addEventListener('keyup', function() {
            const search = this.value.toLowerCase();
            const target = document.querySelector(this.dataset.target);
            if (!target) return;
            
            const rows = target.querySelectorAll('tbody tr');
            rows.forEach(function(row) {
                const text = row.textContent.toLowerCase();
                row.style.display = text.includes(search) ? '' : 'none';
            });
        });
    });
    
    // ============================================
    // Copy to clipboard
    // ============================================
    document.querySelectorAll('.copy-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            const text = this.dataset.copy;
            navigator.clipboard.writeText(text).then(function() {
                const original = btn.innerHTML;
                btn.innerHTML = '<i class="fas fa-check"></i> Copied!';
                setTimeout(function() {
                    btn.innerHTML = original;
                }, 2000);
            });
        });
    });
    
    // ============================================
    // Date range presets for reports
    // ============================================
    document.querySelectorAll('.date-preset').forEach(function(btn) {
        btn.addEventListener('click', function() {
            const days = parseInt(this.dataset.days);
            const end = new Date();
            const start = new Date();
            start.setDate(end.getDate() - days);
            
            const startInput = document.getElementById('start_date');
            const endInput = document.getElementById('end_date');
            
            if (startInput && endInput) {
                startInput.value = start.toISOString().split('T')[0];
                endInput.value = end.toISOString().split('T')[0];
                document.getElementById('reportForm').submit();
            }
        });
    });
    
    // ============================================
    // Print invoice
    // ============================================
    document.querySelectorAll('.print-invoice').forEach(function(btn) {
        btn.addEventListener('click', function() {
            window.print();
        });
    });
    
    // ============================================
    // Auto-calculate totals in forms
    // ============================================
    document.querySelectorAll('.calc-total').forEach(function(container) {
        const inputs = container.querySelectorAll('.calc-input');
        const totalDisplay = container.querySelector('.calc-total-display');
        
        if (inputs.length && totalDisplay) {
            inputs.forEach(function(input) {
                input.addEventListener('input', function() {
                    let total = 0;
                    inputs.forEach(function(inp) {
                        const val = parseFloat(inp.value) || 0;
                        const qty = parseFloat(inp.dataset.quantity) || 1;
                        total += val * qty;
                    });
                    totalDisplay.textContent = new Intl.NumberFormat('en-RW').format(total);
                });
            });
        }
    });
    
    // ============================================
    // Branch switcher
    // ============================================
    document.querySelectorAll('.branch-switcher').forEach(function(link) {
        link.addEventListener('click', function(e) {
            e.preventDefault();
            const branchId = this.dataset.branchId;
            if (confirm('Switch to this branch?')) {
                window.location.href = '../select_branch.php?id=' + branchId;
            }
        });
    });
    
    // ============================================
    // Keyboard shortcuts
    // ============================================
    document.addEventListener('keydown', function(e) {
        // Ctrl + S - Save form
        if (e.ctrlKey && e.key === 's') {
            e.preventDefault();
            const form = document.querySelector('form[data-save-shortcut]');
            if (form) {
                form.submit();
            }
        }
    });
    
    // ============================================
    // Online/Offline status
    // ============================================
    window.addEventListener('online', function() {
        showNotification('Connected', 'Your connection is back online.', 'success');
    });
    
    window.addEventListener('offline', function() {
        showNotification('Disconnected', 'You are offline. Some features may not work.', 'warning');
    });
    
    function showNotification(title, message, type) {
        const container = document.querySelector('.notification-container');
        if (!container) return;
        
        const alert = document.createElement('div');
        alert.className = 'alert alert-' + type + ' alert-dismissible fade show';
        alert.innerHTML = '<strong>' + title + '</strong> ' + message;
        container.appendChild(alert);
        
        setTimeout(function() {
            alert.remove();
        }, 5000);
    }
});

// ============================================
// Utility Functions
// ============================================

function formatNumber(number) {
    return new Intl.NumberFormat('en-RW').format(number);
}

function formatCurrency(amount, currency = 'RWF') {
    return new Intl.NumberFormat('en-RW', {
        style: 'currency',
        currency: 'RWF',
        minimumFractionDigits: 0,
        maximumFractionDigits: 0
    }).format(amount);
}

function getUrlParams() {
    const params = {};
    const query = window.location.search.substring(1);
    const vars = query.split('&');
    vars.forEach(function(v) {
        const pair = v.split('=');
        params[pair[0]] = decodeURIComponent(pair[1]);
    });
    return params;
}

function goBack() {
    window.history.back();
}

function refreshPage() {
    window.location.reload();
}