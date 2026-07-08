            </div> <!-- Close main content -->
        </div> <!-- Close row -->
    </div> <!-- Close container-fluid -->
    
    <!-- Footer -->
    <footer class="bg-white border-top py-3 mt-auto">
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
                        <?php if(getCurrentBranch()): ?>
                        <span class="mx-1">|</span>
                        <a href="../index.php" class="text-muted text-decoration-none">
                            <i class="fas fa-exchange-alt"></i> Switch Branch
                        </a>
                        <?php endif; ?>
                    </small>
                </div>
            </div>
        </div>
    </footer>
    
    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <!-- jQuery (for some features) -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <!-- Custom JS -->
    <script src="../assets/js/main.js"></script>
    
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
        
        // Sidebar toggle for mobile
        function toggleSidebar() {
            document.querySelector('.sidebar').classList.toggle('show');
        }
    </script>
</body>
</html>