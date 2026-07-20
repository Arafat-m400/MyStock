<?php
require_once '../config/db.php';
if(!isLoggedIn() || !isAdmin()) redirect('../index.php');

$message = '';

// ============================================
// UPDATE SETTINGS
// ============================================

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['save_settings'])) {
    $company_name = sanitize($_POST['company_name']);
    $company_address = sanitize($_POST['company_address']);
    $company_phone = sanitize($_POST['company_phone']);
    $company_email = sanitize($_POST['company_email']);
    $currency_symbol = sanitize($_POST['currency_symbol']);
    $whatsapp_alert_number = sanitize($_POST['whatsapp_alert_number']);
    $default_discount = floatval($_POST['default_discount']);
    $tax_rate = floatval($_POST['tax_rate']);
    $low_stock_alert_threshold = intval($_POST['low_stock_alert_threshold']);
    
    try {
        $stmt = $pdo->prepare("
            UPDATE settings SET 
                company_name=?, company_address=?, company_phone=?, company_email=?,
                currency_symbol=?, whatsapp_alert_number=?, default_discount=?, 
                tax_rate=?, low_stock_alert_threshold=?
            WHERE id=1
        ");
        $stmt->execute([
            $company_name, $company_address, $company_phone, $company_email,
            $currency_symbol, $whatsapp_alert_number, $default_discount,
            $tax_rate, $low_stock_alert_threshold
        ]);
        $message = '<div class="alert alert-success">✅ Settings saved successfully!</div>';
        logAction($pdo, 'Update Settings', "System settings updated");
    } catch (PDOException $e) {
        $message = '<div class="alert alert-danger">❌ Error: ' . $e->getMessage() . '</div>';
    }
}

// ============================================
// GET DATA
// ============================================

$settings = $pdo->query("SELECT * FROM settings WHERE id=1")->fetch();

include '../includes/header.php';
include '../includes/sidebar.php';
?>
<div class="col-md-10 main-content">
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap">
    <div>
        <h2><i class="fas fa-cog me-2 text-primary"></i>System Settings</h2>
        <p class="text-muted">Configure your system preferences</p>
    </div>
</div>

<?php echo $message; ?>

<div class="card shadow-sm">
    <div class="card-header bg-primary text-white">
        <h5 class="mb-0"><i class="fas fa-sliders-h me-2"></i>General Settings</h5>
    </div>
    <div class="card-body">
        <form method="POST">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">Company Name *</label>
                    <input type="text" name="company_name" class="form-control" required
                           value="<?php echo htmlspecialchars($settings['company_name']); ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Currency Symbol</label>
                    <input type="text" name="currency_symbol" class="form-control"
                           value="<?php echo htmlspecialchars($settings['currency_symbol']); ?>">
                </div>
                <div class="col-12">
                    <label class="form-label">Address</label>
                    <textarea name="company_address" class="form-control" rows="2"><?php echo htmlspecialchars($settings['company_address']); ?></textarea>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Phone</label>
                    <input type="text" name="company_phone" class="form-control"
                           value="<?php echo htmlspecialchars($settings['company_phone']); ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Email</label>
                    <input type="email" name="company_email" class="form-control"
                           value="<?php echo htmlspecialchars($settings['company_email']); ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">WhatsApp Alert Number</label>
                    <input type="text" name="whatsapp_alert_number" class="form-control"
                           value="<?php echo htmlspecialchars($settings['whatsapp_alert_number']); ?>">
                    <small class="text-muted">For low stock alerts</small>
                </div>
            </div>
            
            <hr class="my-4">
            
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">Default Discount (%)</label>
                    <input type="number" name="default_discount" class="form-control" step="0.01"
                           value="<?php echo $settings['default_discount']; ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Tax Rate (%)</label>
                    <input type="number" name="tax_rate" class="form-control" step="0.01"
                           value="<?php echo $settings['tax_rate']; ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Low Stock Threshold</label>
                    <input type="number" name="low_stock_alert_threshold" class="form-control"
                           value="<?php echo $settings['low_stock_alert_threshold']; ?>">
                    <small class="text-muted">Quantity to trigger low stock alerts</small>
                </div>
            </div>
            
            <div class="mt-4">
                <button type="submit" name="save_settings" class="btn btn-primary">
                    <i class="fas fa-save me-1"></i> Save Settings
                </button>
            </div>
        </form>
    </div>
</div>
</div>
<?php include '../includes/footer.php'; ?>