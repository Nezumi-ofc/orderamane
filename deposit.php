<?php
require_once 'config.php';

class Deposit {
    private $conn;
    
    public function __construct($database_connection) {
        $this->conn = $database_connection;
    }
    
    // Create deposit request
    public function create_deposit($user_id, $amount, $method) {
        if ($amount < 10000 || $amount > 50000000) {
            return ['success' => false, 'message' => 'Jumlah deposit tidak sesuai dengan batas'];
        }
        
        // Generate reference number
        $reference_number = 'DEP' . date('YmdHis') . $user_id;
        
        $stmt = $this->conn->prepare("INSERT INTO deposits (user_id, amount, method, reference_number, status) VALUES (?, ?, ?, ?, 'pending')");
        $stmt->bind_param("ids", $user_id, $amount, $method);
        
        if ($stmt->execute()) {
            return [
                'success' => true,
                'message' => 'Deposit berhasil dibuat',
                'deposit_id' => $this->conn->insert_id,
                'reference_number' => $reference_number
            ];
        } else {
            return ['success' => false, 'message' => 'Gagal membuat deposit'];
        }
    }
    
    // Upload proof image
    public function upload_proof($deposit_id, $file) {
        if ($file['error'] !== UPLOAD_ERR_OK) {
            return ['success' => false, 'message' => 'Gagal upload file'];
        }
        
        if ($file['size'] > MAX_FILE_SIZE) {
            return ['success' => false, 'message' => 'Ukuran file terlalu besar'];
        }
        
        $allowed = ['image/jpeg', 'image/png', 'image/gif'];
        if (!in_array($file['type'], $allowed)) {
            return ['success' => false, 'message' => 'Tipe file tidak diizinkan'];
        }
        
        if (!is_dir(UPLOAD_DIR)) {
            mkdir(UPLOAD_DIR, 0755, true);
        }
        
        $filename = 'proof_' . $deposit_id . '_' . time() . '.jpg';
        $filepath = UPLOAD_DIR . $filename;
        
        if (move_uploaded_file($file['tmp_name'], $filepath)) {
            $update = $this->conn->prepare("UPDATE deposits SET proof_image = ? WHERE id = ?");
            $update->bind_param("si", $filename, $deposit_id);
            $update->execute();
            
            return ['success' => true, 'message' => 'Bukti berhasil diupload', 'filename' => $filename];
        } else {
            return ['success' => false, 'message' => 'Gagal menyimpan file'];
        }
    }
    
    // Get deposit history
    public function get_deposits($user_id, $page = 1) {
        $offset = ($page - 1) * ITEMS_PER_PAGE;
        
        $stmt = $this->conn->prepare("SELECT * FROM deposits WHERE user_id = ? ORDER BY created_at DESC LIMIT ? OFFSET ?");
        $stmt->bind_param("iii", $user_id, ITEMS_PER_PAGE, $offset);
        $stmt->execute();
        
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }
    
    // Get deposit detail
    public function get_deposit($deposit_id) {
        $stmt = $this->conn->prepare("SELECT * FROM deposits WHERE id = ?");
        $stmt->bind_param("i", $deposit_id);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc();
    }
    
    // Confirm deposit (admin)
    public function confirm_deposit($deposit_id, $admin_id) {
        $stmt = $this->conn->prepare("SELECT user_id, amount FROM deposits WHERE id = ?");
        $stmt->bind_param("i", $deposit_id);
        $stmt->execute();
        $deposit = $stmt->get_result()->fetch_assoc();
        
        // Update balance
        $update_balance = $this->conn->prepare("UPDATE users SET balance = balance + ? WHERE id = ?");
        $update_balance->bind_param("di", $deposit['amount'], $deposit['user_id']);
        
        // Update deposit status
        $update_deposit = $this->conn->prepare("UPDATE deposits SET status = 'success', confirmed_by = ?, confirmed_at = NOW() WHERE id = ?");
        $update_deposit->bind_param("ii", $admin_id, $deposit_id);
        
        // Log transaction
        $log = $this->conn->prepare("INSERT INTO transaction_logs (user_id, type, reference_id, amount, description, balance_before, balance_after) 
            SELECT ?, 'deposit', ?, ?, 'Deposit berhasil dikonfirmasi', balance - ?, balance FROM users WHERE id = ?");
        $log->bind_param("iiddi", $deposit['user_id'], $deposit_id, $deposit['amount'], $deposit['amount'], $deposit['user_id']);
        
        if ($update_balance->execute() && $update_deposit->execute() && $log->execute()) {
            return ['success' => true, 'message' => 'Deposit berhasil dikonfirmasi'];
        } else {
            return ['success' => false, 'message' => 'Gagal mengkonfirmasi deposit'];
        }
    }
    
    // Reject deposit
    public function reject_deposit($deposit_id, $reason) {
        $update = $this->conn->prepare("UPDATE deposits SET status = 'failed', notes = ? WHERE id = ?");
        $update->bind_param("si", $reason, $deposit_id);
        
        if ($update->execute()) {
            return ['success' => true, 'message' => 'Deposit ditolak'];
        } else {
            return ['success' => false, 'message' => 'Gagal menolak deposit'];
        }
    }
}
?>