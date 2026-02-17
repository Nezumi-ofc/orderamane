<?php
require_once 'config.php';

class Auth {
    private $conn;
    
    public function __construct($database_connection) {
        $this->conn = $database_connection;
    }
    
    // Register
    public function register($username, $email, $password, $full_name, $phone = '') {
        // Validasi
        if (empty($username) || empty($email) || empty($password)) {
            return ['success' => false, 'message' => 'Username, email, dan password harus diisi'];
        }
        
        // Check username existing
        $check = $this->conn->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
        $check->bind_param("ss", $username, $email);
        $check->execute();
        
        if ($check->get_result()->num_rows > 0) {
            return ['success' => false, 'message' => 'Username atau email sudah terdaftar'];
        }
        
        // Hash password
        $password_hash = password_hash($password, PASSWORD_BCRYPT);
        
        // Insert
        $insert = $this->conn->prepare("INSERT INTO users (username, email, password, full_name, phone, status) VALUES (?, ?, ?, ?, ?, 'active')");
        $insert->bind_param("sssss", $username, $email, $password_hash, $full_name, $phone);
        
        if ($insert->execute()) {
            return ['success' => true, 'message' => 'Registrasi berhasil. Silakan login'];
        } else {
            return ['success' => false, 'message' => 'Registrasi gagal'];
        }
    }
    
    // Login
    public function login($username, $password) {
        if (empty($username) || empty($password)) {
            return ['success' => false, 'message' => 'Username dan password harus diisi'];
        }
        
        $stmt = $this->conn->prepare("SELECT id, username, password, status FROM users WHERE username = ? OR email = ?");
        $stmt->bind_param("ss", $username, $username);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 0) {
            return ['success' => false, 'message' => 'Username atau password salah'];
        }
        
        $user = $result->fetch_assoc();
        
        if ($user['status'] === 'banned') {
            return ['success' => false, 'message' => 'Akun Anda telah diblokir'];
        }
        
        if (!password_verify($password, $user['password'])) {
            return ['success' => false, 'message' => 'Username atau password salah'];
        }
        
        // Set session
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['login_time'] = time();
        
        return ['success' => true, 'message' => 'Login berhasil', 'user_id' => $user['id']];
    }
    
    // Logout
    public function logout() {
        session_destroy();
        return ['success' => true, 'message' => 'Logout berhasil'];
    }
    
    // Check login
    public function is_logged_in() {
        return isset($_SESSION['user_id']);
    }
    
    // Get user data
    public function get_user($user_id) {
        $stmt = $this->conn->prepare("SELECT id, username, email, full_name, phone, address, balance, status, created_at FROM users WHERE id = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc();
    }
    
    // Update profile
    public function update_profile($user_id, $data) {
        $stmt = $this->conn->prepare("UPDATE users SET full_name = ?, phone = ?, address = ? WHERE id = ?");
        $stmt->bind_param("sssi", $data['full_name'], $data['phone'], $data['address'], $user_id);
        
        if ($stmt->execute()) {
            return ['success' => true, 'message' => 'Profil berhasil diperbarui'];
        } else {
            return ['success' => false, 'message' => 'Gagal memperbarui profil'];
        }
    }
    
    // Change password
    public function change_password($user_id, $old_password, $new_password) {
        $stmt = $this->conn->prepare("SELECT password FROM users WHERE id = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        
        if (!password_verify($old_password, $result['password'])) {
            return ['success' => false, 'message' => 'Password lama salah'];
        }
        
        $password_hash = password_hash($new_password, PASSWORD_BCRYPT);
        $update = $this->conn->prepare("UPDATE users SET password = ? WHERE id = ?");
        $update->bind_param("si", $password_hash, $user_id);
        
        if ($update->execute()) {
            return ['success' => true, 'message' => 'Password berhasil diubah'];
        } else {
            return ['success' => false, 'message' => 'Gagal mengubah password'];
        }
    }
}
?>