            </div> <!-- Close row -->
        </div> <!-- Close container-fluid -->
    </div> <!-- Close wrapper -->

    <footer class="bg-white border-top py-2 mt-auto">
        <div class="container-fluid">
            <div class="row">
                <div class="col-md-6 text-center text-md-start">
                    <small class="text-muted">
                        &copy; <?php echo date('Y'); ?> MyStock v2.0 - Enterprise Stock Management
                    </small>
                </div>
                <div class="col-md-6 text-center text-md-end">
                    <small class="text-muted">
                        <i class="fas fa-store me-1"></i>
                        <?php echo htmlspecialchars(getCurrentBranchName() ?? 'No Branch'); ?>
                    </small>
                </div>
            </div>
        </div>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    
    <script>
        // Auto-dismiss alerts
        document.addEventListener('DOMContentLoaded', function() {
            setTimeout(function() {
                document.querySelectorAll('.alert:not(.alert-permanent)').forEach(function(alert) {
                    var bsAlert = new bootstrap.Alert(alert);
                    bsAlert.close();
                });
            }, 5000);
        });
    </script>

    <!-- ============================================
AUTO-CLOSE MODALS AFTER SUCCESSFUL SUBMISSION
============================================ -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Check if there's a success alert that indicates a successful submission
    const successAlert = document.querySelector('.alert-success');
    if (successAlert) {
        // If the alert contains "added", "created", "updated", "saved", "completed", "recorded"
        const successKeywords = ['added', 'created', 'updated', 'saved', 'completed', 'recorded', 'deleted'];
        const alertText = successAlert.textContent.toLowerCase();
        const shouldClose = successKeywords.some(keyword => alertText.includes(keyword));
        
        if (shouldClose) {
            // Find any open modal and close it
            const openModals = document.querySelectorAll('.modal.show');
            openModals.forEach(function(modalElement) {
                const modal = bootstrap.Modal.getInstance(modalElement);
                if (modal) {
                    modal.hide();
                    // Remove backdrop
                    document.querySelectorAll('.modal-backdrop').forEach(function(backdrop) {
                        backdrop.remove();
                    });
                    document.body.classList.remove('modal-open');
                }
            });
        }
    }
});
</script>
</body>
</html>