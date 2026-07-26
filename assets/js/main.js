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
    // Confirm before delete (Enhanced)
    // ============================================
    document.querySelectorAll('.confirm-delete, .btn-danger[onclick*="confirm"]').forEach(function(link) {
        link.addEventListener('click', function(e) {
            // If there's already an onclick handler with confirm, skip
            if (this.hasAttribute('onclick') && this.getAttribute('onclick').includes('confirm')) {
                return;
            }
            if (!confirm('Are you sure you want to delete this item? This action cannot be undone.')) {
                e.preventDefault();
            }
        });
    });
    
    // ============================================
    // Workspace Delete Confirmation (Handles custom confirm)
    // ============================================
    // This is handled inline in workspace_details.php via confirmDeleteWorkspace()
    
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
    // Number formatting for inputs - DISABLED (raw numbers only)
    // ============================================
    // This is now disabled to prevent automatic comma formatting
    // Users can still enter raw numbers without formatting
    document.querySelectorAll('.number-format').forEach(function(input) {
        input.addEventListener('input', function() {
            // Simply allow raw numbers - no formatting
            // This prevents the "nearest values" issue
            let value = this.value.replace(/[^0-9.]/g, '');
            this.value = value;
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

/**
 * Format number WITHOUT commas (raw number)
 * Used for displaying numbers in tables and cards
 */
function formatNumber(number) {
    // Return raw number rounded
    return Math.round(number);
}

/**
 * Format currency with RWF suffix (NO commas)
 */
function formatCurrency(amount, currency = 'RWF') {
    return Math.round(amount) + ' ' + currency;
}

/**
 * Format number WITH commas (for display only)
 * Use this ONLY when you want comma formatting
 */
function formatNumberWithCommas(number) {
    return new Intl.NumberFormat('en-RW').format(Math.round(number));
}

/**
 * Get URL parameters
 */
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

/**
 * Go back to previous page
 */
function goBack() {
    window.history.back();
}

/**
 * Refresh current page
 */
function refreshPage() {
    window.location.reload();
}

/**
 * Confirm workspace deletion (custom message)
 */
function confirmDeleteWorkspace() {
    return confirm('⚠️ Are you sure you want to delete this workspace?\n\nAll inputs and outputs will be permanently deleted.\n\nThis action CANNOT be undone!');
}

/**
 * Confirm generic deletion with custom message
 */
function confirmDelete(message) {
    if (!message) {
        message = 'Are you sure you want to delete this item? This action cannot be undone.';
    }
    return confirm(message);
}

/**
 * Toggle visibility of new supplier fields
 */
function toggleNewSupplier(value) {
    const fields = document.getElementById('new_supplier_fields');
    if (fields) {
        if (value === 'new') {
            fields.style.display = 'block';
            document.querySelectorAll('#new_supplier_fields input[required]').forEach(function(input) {
                input.setAttribute('required', 'required');
            });
        } else {
            fields.style.display = 'none';
            document.querySelectorAll('#new_supplier_fields input').forEach(function(input) {
                input.removeAttribute('required');
            });
        }
    }
}

/**
 * Toggle visibility of new customer fields
 */
function toggleNewCustomer(value) {
    const fields = document.getElementById('new_customer_fields');
    if (fields) {
        if (value === 'new') {
            fields.style.display = 'block';
            document.querySelectorAll('#new_customer_fields input[required]').forEach(function(input) {
                input.setAttribute('required', 'required');
            });
        } else {
            fields.style.display = 'none';
            document.querySelectorAll('#new_customer_fields input').forEach(function(input) {
                input.removeAttribute('required');
            });
        }
    }
}

/**
 * Toggle PO type (Formal vs Advance)
 */
function togglePOType() {
    const type = document.getElementById('po_type');
    if (type) {
        const formalSection = document.getElementById('formal_section');
        const advanceSection = document.getElementById('advance_section');
        if (formalSection && advanceSection) {
            formalSection.style.display = type.value === 'formal' ? 'block' : 'none';
            advanceSection.style.display = type.value === 'advance' ? 'block' : 'none';
        }
    }
}

/**
 * Toggle payment method fields
 */
function togglePaymentFields() {
    const method = document.getElementById('payment_method');
    if (method) {
        const cashField = document.getElementById('cash_amount');
        const momoField = document.getElementById('momo_amount');
        const grandTotal = parseFloat(document.getElementById('grand_total_display')?.textContent.replace(/[^0-9]/g, '')) || 0;
        
        if (method.value === 'cash') {
            if (cashField) cashField.value = grandTotal;
            if (momoField) momoField.value = 0;
            if (cashField) cashField.disabled = false;
            if (momoField) momoField.disabled = true;
        } else if (method.value === 'momo') {
            if (cashField) cashField.value = 0;
            if (momoField) momoField.value = grandTotal;
            if (cashField) cashField.disabled = true;
            if (momoField) momoField.disabled = false;
        } else if (method.value === 'debt') {
            if (cashField) cashField.value = 0;
            if (momoField) momoField.value = 0;
            if (cashField) cashField.disabled = true;
            if (momoField) momoField.disabled = true;
        }
    }
}

/**
 * Set date range for reports
 */
function setDateRange(days) {
    const end = new Date();
    const start = new Date();
    start.setDate(end.getDate() - days);
    
    const startInput = document.querySelector('input[name="start_date"]');
    const endInput = document.querySelector('input[name="end_date"]');
    
    if (startInput && endInput) {
        startInput.value = start.toISOString().split('T')[0];
        endInput.value = end.toISOString().split('T')[0];
        const form = startInput.closest('form');
        if (form) {
            form.submit();
        }
    }
}

/**
 * Escape HTML special characters
 */
function escapeHtml(str) {
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}

/**
 * Debounce function for search inputs
 */
function debounce(func, wait) {
    let timeout;
    return function executedFunction() {
        const context = this;
        const args = arguments;
        const later = function() {
            clearTimeout(timeout);
            func.apply(context, args);
        };
        clearTimeout(timeout);
        timeout = setTimeout(later, wait);
    };
}