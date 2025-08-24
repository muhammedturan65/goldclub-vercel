<?php
// admin_login.php - Yönetici Giriş Sayfası

// Veritabanı bağlantısını ve oturum yönetimini başlat
require_once 'db.php';

// Eğer yönetici zaten giriş yapmışsa, doğrudan admin paneline yönlendir
if (isset($_SESSION['admin_id'])) {
    header("Location: /test-gold/admin_panel");
    exit();
}

$error_message = '';

// Eğer form gönderildiyse...
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username']);
    $password = trim($_POST['password']);

    if (empty($username) || empty($password)) {
        $error_message = 'Kullanıcı adı ve şifre boş bırakılamaz.';
    } else {
        try {
            // Yöneticinin 'admins' tablosunda olup olmadığını kontrol et
            $stmt = $conn->prepare("SELECT id, username, password FROM admins WHERE username = ?");
            $stmt->execute([$username]);
            $admin = $stmt->fetch();

            // Eğer yönetici bulunduysa ve şifre doğruysa...
            if ($admin && password_verify($password, $admin['password'])) {
                // Oturum (session) bilgilerini ayarla (normal kullanıcıdan farklı!)
                // session_regenerate_id(true); // Güvenlik için oturum kimliğini yenile
                $_SESSION['admin_id'] = $admin['id'];
                $_SESSION['admin_username'] = $admin['username'];
                
                // Yönetici paneline yönlendir
                header("Location: /test-gold/admin_panel");
                exit();
            } else {
                $error_message = 'Geçersiz yönetici adı veya şifre.';
            }
        } catch(PDOException $e) {
            $error_message = 'Bir veritabanı hatası oluştu: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <title>Yönetici Girişi</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <!-- Stil kodları standart login sayfasıyla benzerdir -->
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; background: #101014; color: #e0e0e0; display: flex; justify-content: center; align-items: center; min-height: 100vh; margin: 0; }
        .auth-container { width: 360px; padding: 40px; background: rgba(30, 30, 35, 0.6); border-radius: 16px; box-shadow: 0 8px 32px rgba(0, 0, 0, 0.4); border: 1px solid rgba(255, 255, 255, 0.1); }
        h1 { text-align: center; margin-bottom: 30px; color: #ffc107; } /* Yönetici için sarı renk */
        .input-group { margin-bottom: 20px; }
        .input-group label { display: block; margin-bottom: 8px; color: #a0a0a0; }
        .input-group input { width: 100%; padding: 12px; background-color: rgba(0,0,0,0.25); border: 1px solid #444; border-radius: 8px; color: #fff; font-size: 16px; box-sizing: border-box; }
        .auth-button { width: 100%; background-image: linear-gradient(90deg, #ffc107, #ff9800); color: #101014; border: none; padding: 14px; font-size: 16px; font-weight: bold; border-radius: 8px; cursor: pointer; }
        .message { text-align: center; padding: 10px; border-radius: 5px; margin-bottom: 20px; }
        .message.error { background-color: #5c2323; color: #ffbaba; }
    </style>
</head>
<body>
    <div class="auth-container">
        <h1>Yönetici Girişi</h1>
        <?php if ($error_message): ?>
            <p class="message error"><?php echo htmlspecialchars($error_message); ?></p>
        <?php endif; ?>
        <form method="post">
            <div class="input-group">
                <label for="username">Yönetici Adı</label>
                <input type="text" id="username" name="username" required>
            </div>
            <div class="input-group">
                <label for="password">Şifre</label>
                <input type="password" id="password" name="password" required>
            </div>
            <button type="submit" class="auth-button">Giriş Yap</button>
        </form>
    </div>
</body>
</html>