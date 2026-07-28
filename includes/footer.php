</div> <!-- Close row -->
        </div> <!-- Close container-fluid -->
    </div> <!-- Close wrapper -->

    <footer class="bg-white border-top py-2 mt-auto">
        <div class="container-fluid">
            <div class="row">
                <div class="col-12 text-center">
                    <small class="text-muted">
                        &copy; <?php echo date('Y'); ?> 
                        <?php 
                        $stmt = $pdo->prepare("SELECT company_name FROM settings WHERE id = 1");
                        $stmt->execute();
                        $settings_row = $stmt->fetch();
                        $company_name = $settings_row['company_name'] ?? 'MyStock';
                        echo htmlspecialchars($company_name); 
                        ?> 
                        <span class="d-none d-sm-inline">- Stock Management</span>
                    </small>
                    <br>
                    <small class="text-muted d-sm-none">
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
            
            // Close modal on success
            const successAlert = document.querySelector('.alert-success');
            if (successAlert) {
                const keywords = ['added', 'created', 'updated', 'saved', 'completed', 'recorded', 'deleted'];
                const text = successAlert.textContent.toLowerCase();
                const shouldClose = keywords.some(function(k) { return text.includes(k); });
                if (shouldClose) {
                    setTimeout(function() {
                        document.querySelectorAll('.modal.show').forEach(function(modalEl) {
                            var modal = bootstrap.Modal.getInstance(modalEl);
                            if (modal) modal.hide();
                        });
                        document.querySelectorAll('.modal-backdrop').forEach(function(b) { b.remove(); });
                        document.body.classList.remove('modal-open');
                    }, 1500);
                }
            }
        });
    </script>
</body>
</html>