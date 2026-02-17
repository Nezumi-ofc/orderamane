<?php
require_once 'config.php';

class Order {
    private $conn;
    
    public function __construct($database_connection) {
        $this->conn = $database_connection;
    }
    
    // Create order
    public function create_order($user_id, $product_name, $quantity, $price, $notes = '') {
        // Cek saldo user
        $user_stmt = $this->conn->prepare("SELECT balance FROM users WHERE id = ?");
        $user_stmt->bind_param("i", $user_id);
        $user_stmt->execute();
        $user = $user_stmt->get_result()->fetch_assoc();
        
        $total_price = $quantity * $price;
        
        if ($user['balance'] < $total_price) {
            return ['success' => false, 'message' => 'Saldo tidak cukup'];
        }
        
        // Generate order number
        $order_number = 'ORD' . date('YmdHis') . $user_id;
        
        $stmt = $this->conn->prepare("INSERT INTO orders (order_number, user_id, product_name, quantity, price, total_price, notes, status, payment_status) 
            VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', 'unpaid')");
        $stmt->bind_param("ssissds", $order_number, $user_id, $product_name, $quantity, $price, $total_price, $notes);
        
        if ($stmt->execute()) {
            return [
                'success' => true,
                'message' => 'Order berhasil dibuat',
                'order_id' => $this->conn->insert_id,
                'order_number' => $order_number,
                'total_price' => $total_price
            ];
        } else {
            return ['success' => false, 'message' => 'Gagal membuat order'];
        }
    }
    
    // Get orders
    public function get_orders($user_id, $page = 1, $status = null) {
        $offset = ($page - 1) * ITEMS_PER_PAGE;
        
        if ($status) {
            $stmt = $this->conn->prepare("SELECT * FROM orders WHERE user_id = ? AND status = ? ORDER BY created_at DESC LIMIT ? OFFSET ?");
            $stmt->bind_param("isii", $user_id, $status, ITEMS_PER_PAGE, $offset);
        } else {
            $stmt = $this->conn->prepare("SELECT * FROM orders WHERE user_id = ? ORDER BY created_at DESC LIMIT ? OFFSET ?");
            $stmt->bind_param("iii", $user_id, ITEMS_PER_PAGE, $offset);
        }
        
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }
    
    // Get order detail
    public function get_order($order_id) {
        $stmt = $this->conn->prepare("SELECT * FROM orders WHERE id = ?");
        $stmt->bind_param("i", $order_id);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc();
    }
    
    // Pay order
    public function pay_order($order_id, $user_id) {
        $order = $this->get_order($order_id);
        
        if (!$order || $order['user_id'] !== $user_id) {
            return ['success' => false, 'message' => 'Order tidak ditemukan'];
        }
        
        if ($order['payment_status'] === 'paid') {
            return ['success' => false, 'message' => 'Order sudah dibayar'];
        }
        
        // Get user balance
        $user_stmt = $this->conn->prepare("SELECT balance FROM users WHERE id = ?");
        $user_stmt->bind_param("i", $user_id);
        $user_stmt->execute();
        $user = $user_stmt->get_result()->fetch_assoc();
        
        if ($user['balance'] < $order['total_price']) {
            return ['success' => false, 'message' => 'Saldo tidak cukup'];
        }
        
        // Update balance
        $update_balance = $this->conn->prepare("UPDATE users SET balance = balance - ? WHERE id = ?");
        $update_balance->bind_param("di", $order['total_price'], $user_id);
        
        // Update order
        $update_order = $this->conn->prepare("UPDATE orders SET payment_status = 'paid', status = 'processing' WHERE id = ?");
        $update_order->bind_param("i", $order_id);
        
        // Log transaction
        $log = $this->conn->prepare("INSERT INTO transaction_logs (user_id, type, reference_id, amount, description, balance_before, balance_after) 
            SELECT ?, 'order', ?, ?, 'Pembayaran order ' || ?, balance - ?, balance FROM users WHERE id = ?");
        
        if ($update_balance->execute() && $update_order->execute()) {
            $this->log_transaction($user_id, 'order', $order_id, $order['total_price'], 'Pembayaran order ' . $order['order_number']);
            return ['success' => true, 'message' => 'Order berhasil dibayar'];
        } else {
            return ['success' => false, 'message' => 'Gagal membayar order'];
        }
    }
    
    // Complete order (admin)
    public function complete_order($order_id) {
        $update = $this->conn->prepare("UPDATE orders SET status = 'completed', completed_at = NOW() WHERE id = ?");
        $update->bind_param("i", $order_id);
        
        if ($update->execute()) {
            return ['success' => true, 'message' => 'Order berhasil diselesaikan'];
        } else {
            return ['success' => false, 'message' => 'Gagal menyelesaikan order'];
        }
    }
    
    // Cancel order
    public function cancel_order($order_id, $user_id, $reason = '') {
        $order = $this->get_order($order_id);
        
        if (!$order || $order['user_id'] !== $user_id) {
            return ['success' => false, 'message' => 'Order tidak ditemukan'];
        }
        
        if ($order['status'] === 'completed') {
            return ['success' => false, 'message' => 'Order sudah selesai, tidak bisa dibatalkan'];
        }
        
        // If already paid, refund balance
        if ($order['payment_status'] === 'paid') {
            $refund = $this->conn->prepare("UPDATE users SET balance = balance + ? WHERE id = ?");
            $refund->bind_param("di", $order['total_price'], $user_id);
            $refund->execute();
            
            $this->log_transaction($user_id, 'refund', $order_id, $order['total_price'], 'Refund order ' . $order['order_number']);
        }
        
        $update = $this->conn->prepare("UPDATE orders SET status = 'cancelled' WHERE id = ?");
        $update->bind_param("i", $order_id);
        
        if ($update->execute()) {
            return ['success' => true, 'message' => 'Order berhasil dibatalkan'];
        } else {
            return ['success' => false, 'message' => 'Gagal membatalkan order'];
        }
    }
    
    // Log transaction
    private function log_transaction($user_id, $type, $reference_id, $amount, $description) {
        $user_stmt = $this->conn->prepare("SELECT balance FROM users WHERE id = ?");
        $user_stmt->bind_param("i", $user_id);
        $user_stmt->execute();
        $user = $user_stmt->get_result()->fetch_assoc();
        
        $balance_after = $user['balance'];
        $balance_before = $balance_after + ($type === 'order' ? $amount : -$amount);
        
        $log = $this->conn->prepare("INSERT INTO transaction_logs (user_id, type, reference_id, amount, description, balance_before, balance_after) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $log->bind_param("isidsddd", $user_id, $type, $reference_id, $amount, $description, $balance_before, $balance_after);
        $log->execute();
    }
}
?>