<?php
// login.php - Kullanıcı Giriş Sayfası (Şifreyi Oturuma Kaydeden ve Aktif/Pasif Kontrollü)

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

    if (empty($username) || empty($password)) {
        $error_message = 'Kullanıcı adı ve şifre boş bırakılamaz.';
    } else {
        try {
            // Veritabanından kullanıcıyı çekerken 'is_active' durumunu da al
            $stmt = $conn->prepare("SELECT id, username, password, is_active FROM users WHERE username = ?");
            $stmt->execute([$username]);
            $user = $stmt->fetch();

            // 1. KONTROL: Kullanıcı bulundu mu ve şifre doğru mu?
            if ($user && password_verify($password, $user['password'])) {
                
                // 2. KONTROL: Eğer şifre doğruysa, hesap aktif mi?
                if ($user['is_active'] == 1) {
                    // Her şey yolundaysa, oturum bilgilerini ayarla
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['username'] = $user['username'];
                    
                    // --- YENİ EKLENEN SATIR: Şifreyi oturuma kaydet ---
                    // Bu, panel.php'de tam M3U linkini oluşturmak için kullanılacak.
                    $_SESSION['plain_password_for_url'] = $password;
                    
                    // Kullanıcıyı panele yönlendir
                    header("Location: /test-gold/panel"); // SİTENİZİN ALT KLASÖRÜNE GÖRE GÜNCELLEYİN
                    exit();
                } else {
                    // Eğer hesap aktif değilse (yani pasif/askıda ise) hata ver
                    $error_message = 'Hesabınız bir yönetici tarafından askıya alınmıştır.';
                }

            } else {
                // Eğer kullanıcı bulunamadıysa veya şifre yanlışsa genel bir hata ver
                $error_message = 'Geçersiz kullanıcı adı veya şifre.';
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
    <title>Giriş Yap</title>
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
        <h1>Giriş Yap</h1>
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
                <input type="password" id="password" name="password" required>
            </div>
            <button type="submit" class="auth-button">Giriş Yap</button>
        </form>
        <p class="switch-page">Hesabın yok mu? <a href="/test-gold/register">Kayıt Ol</a></p>
    </div>
</body>
</html>