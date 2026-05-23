<?php
require_once 'config/db.php';
if(!isLoggedIn() || !isAdmin()) redirect('index.php');
// CRUD identical to customers, just table 'suppliers' with fields: name, contact_person, phone, email, address.
// ... (standard CRUD, omitted for brevity but can be provided on request)
?>