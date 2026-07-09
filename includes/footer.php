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

        // Prevent back button after logout
        <?php if(!isLoggedIn()): ?>
        window.addEventListener('pageshow', function(event) {
            if (event.persisted) {
                window.location.href = 'login.php';
            }
        });
        <?php endif; ?>
    </script>
</body>
</html>