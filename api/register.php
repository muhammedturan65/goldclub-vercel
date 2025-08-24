<?php
// register.php - Kullanıcı Kayıt Sayfası (Otomatik Giriş ve Şifre Saklama Özellikli)

// Veritabanı bağlantısını ve oturum yönetimini başlat
require_once 'db.php';

// Eğer kullanıcı zaten giriş yapmışsa, beklemeden doğrudan panele yönlendir.
if (isset($_SESSION['user_id'])) {
    header("Location: /test-gold/panel"); // SİTENİZİN ALT KLASÖRÜNE GÖRE GÜNCELLEYİN
    exit();
}

$error_message = '';

// Eğer sayfa, butona basılarak (POST metodu ile) gönderildiyse...
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username']);
    $password = trim($_POST['password']);

    // Basit kontroller: Boş olamaz, şifre en az 6 karakter olmalı.
    if (empty($username) || empty($password)) {
        $error_message = 'Kullanıcı adı ve şifre boş bırakılamaz.';
    } elseif (strlen($password) < 6) {
        $error_message = 'Şifre en az 6 karakter olmalıdır.';
    } else {
        try {
            // Kullanıcı adının daha önce alınıp alınmadığını veritabanından kontrol et
            $stmt = $conn->prepare("SELECT id FROM users WHERE username = ?");
            $stmt->execute([$username]);

            if ($stmt->fetch()) {
                // Eğer kullanıcı adı zaten varsa hata ver
                $error_message = 'Bu kullanıcı adı zaten alınmış.';
            } else {
                // Şifreyi GÜVENLİ bir şekilde hash'le
                $hashed_password = password_hash($password, PASSWORD_BCRYPT);

                // Yeni kullanıcıyı veritabanına ekle
                $stmt = $conn->prepare("INSERT INTO users (username, password) VALUES (?, ?)");
                $stmt->execute([$username, $hashed_password]);

                // --- GÜNCELLENMİŞ OTOMATİK GİRİŞ ---
                // Az önce veritabanına eklenen yeni kullanıcının ID'sini al
                $new_user_id = $conn->lastInsertId();
                
                // Oturum (session) bilgilerini ayarla
                $_SESSION['user_id'] = $new_user_id;
                $_SESSION['username'] = $username;
                // --- YENİ EKLENEN SATIR: Şifreyi de oturuma kaydet ---
                $_SESSION['plain_password_for_url'] = $password;
                
                // Kullanıcıyı doğrudan panele yönlendir
                header("Location: /test-gold/panel"); // SİTENİZİN ALT KLASÖRÜNE GÖRE GÜNCELLEYİN
                exit();
                // ------------------------------------
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
    <title>Kayıt Ol</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; background: #101014; color: #e0e0e0; display: flex; justify-content: center; align-items: center; min-height: 100vh; margin: 0; }
        .auth-container { width: 360px; padding: 40px; background: rgba(30, 30, 35, 0.6); border-radius: 16px; box-shadow: 0 8px 32px rgba(0, 0, 0, 0.4); border: 1px solid rgba(255, 255, 255, 0.1); }
        h1 { text-align: center; margin-bottom: 30px; }
        .input-group { margin-bottom: 20px; }
        .input-group label { display: block; margin-bottom: 8px; color: #a0a0a0; }
        .input-group input { width: 100%; padding: 12px; background-color: rgba(0,0,0,0.25); border: 1px solid #444; border-radius: 8px; color: #fff; font-size: 16px; box-sizing: border-box; }
        .auth-button { width: 100%; background-image: linear-gradient(90deg, #8A2387, #E94057); color: white; border: none; padding: 14px; font-size: 16px; font-weight: bold; border-radius: 8px; cursor: pointer; }
        .message { text-align: center; padding: 10px; border-radius: 5px; margin-bottom: 20px; }
        .message.error { background-color: #5c2323; color: #ffbaba; }
        .switch-page { text-align: center; margin-top: 20px; color: #a0a0a0; }
        .switch-page a { color: #E94057; text-decoration: none; }
    </style>
</head>
<body>
    <div class="auth-container">
        <h1>Kayıt Ol</h1>
        <?php if ($error_message): ?>
            <p class="message error"><?php echo htmlspecialchars($error_message); ?></p>
        <?php endif; ?>
        <form method="post">
            <div class="input-group">
                <label for="username">Kullanıcı Adı</label>
                <input type="text" id="username" name="username" required>
            </div>
            <div class="input-group">
                <label for="password">Şifre</label>
                <input type="password" id="password" name="password" required minlength="6">
            </div>
            <button type="submit" class="auth-button">Kayıt Ol</button>
        </form>
        <p class="switch-page">Zaten bir hesabın var mı? <a href="/test-gold/login">Giriş Yap</a></p>
    </div>
</body>
</html>