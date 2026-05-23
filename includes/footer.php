            </div> <!-- close col-md-10 from sidebar -->
        </div> <!-- close row from header -->
    </div> <!-- close container-fluid -->

    <footer class="text-center py-3 mt-4 bg-light border-top">
        <div class="container-fluid">
            <small>&copy; <?php echo date('Y'); ?> MyStock - Stock Management System. All rights reserved.</small>
        </div>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Auto-hide alerts after 5 seconds
        setTimeout(function() {
            let alerts = document.querySelectorAll('.alert');
            alerts.forEach(function(alert) {
                let bsAlert = new bootstrap.Alert(alert);
                bsAlert.close();
            });
        }, 5000);
    </script>
</body>
</html>