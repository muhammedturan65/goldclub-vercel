<?php
// user_edit.php - Yönetici için Kullanıcı Düzenleme Sayfası

require_once 'db.php';
// Güvenlik: Yönetici girişi yapılmamışsa erişimi engelle
if (!isset($_SESSION['admin_id'])) {
    header("Location: /test-gold/admin_login");
    exit();
}

// URL'den düzenlenecek kullanıcının ID'sini al
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    die('Geçersiz kullanıcı ID.');
}
$user_id = $_GET['id'];

$message = '';
$message_type = '';

// Form gönderildiğinde verileri güncelle
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $daily_limit = $_POST['daily_limit'];
    $new_password = $_POST['new_password'];

    try {
        if (!empty($new_password)) {
            // Eğer yeni bir şifre girildiyse, hash'le ve güncelle
            if (strlen($new_password) < 6) {
                $message = 'Yeni şifre en az 6 karakter olmalıdır.';
                $message_type = 'error';
            } else {
                $hashed_password = password_hash($new_password, PASSWORD_BCRYPT);
                $stmt = $conn->prepare("UPDATE users SET daily_limit = ?, password = ? WHERE id = ?");
                $stmt->execute([$daily_limit, $hashed_password, $user_id]);
                $message = 'Kullanıcı bilgileri ve şifre başarıyla güncellendi.';
                $message_type = 'success';
            }
        } else {
            // Sadece kullanım limitini güncelle
            $stmt = $conn->prepare("UPDATE users SET daily_limit = ? WHERE id = ?");
            $stmt->execute([$daily_limit, $user_id]);
            $message = 'Kullanıcı limiti başarıyla güncellendi.';
            $message_type = 'success';
        }
    } catch (PDOException $e) {
        $message = 'Güncelleme sırasında bir hata oluştu: ' . $e->getMessage();
        $message_type = 'error';
    }
}

// Düzenlenecek kullanıcının mevcut bilgilerini çek
try {
    $stmt = $conn->prepare("SELECT username, daily_limit FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) {
        die('Kullanıcı bulunamadı.');
    }
} catch (PDOException $e) {
    die("Kullanıcı bilgileri alınamadı: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <title>Kullanıcı Düzenle</title>
    <!-- Stil kodları admin_login.php'ye benzer -->
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; background: #1a1a1a; color: #e0e0e0; display: flex; justify-content: center; align-items: center; min-height: 100vh; margin: 0; }
        .form-container { width: 400px; padding: 40px; background: #2a2a2a; border-radius: 12px; border: 1px solid #444; }
        h1 { text-align: center; margin-bottom: 30px; color: #ffc107; }
        .input-group { margin-bottom: 20px; }
        .input-group label { display: block; margin-bottom: 8px; color: #a0a0a0; }
        .input-group input { width: 100%; padding: 12px; background-color: rgba(0,0,0,0.25); border: 1px solid #444; border-radius: 8px; color: #fff; font-size: 16px; box-sizing: border-box; }
        .form-button { width: 100%; background-image: linear-gradient(90deg, #ffc107, #ff9800); color: #101014; border: none; padding: 14px; font-size: 16px; font-weight: bold; border-radius: 8px; cursor: pointer; }
        .message { text-align: center; padding: 10px; border-radius: 5px; margin-bottom: 20px; }
        .message.error { background-color: #5c2323; color: #ffbaba; }
        .message.success { background-color: #1e4620; color: #c6f6d5; }
        .back-link { display: block; text-align: center; margin-top: 20px; color: #ffc107; }
    </style>
</head>
<body>
    <div class="form-container">
        <h1>"<?php echo htmlspecialchars($user['username']); ?>" Düzenle</h1>
        <?php if ($message): ?>
            <p class="message <?php echo $message_type; ?>"><?php echo htmlspecialchars($message); ?></p>
        <?php endif; ?>
        <form method="post">
            <div class="input-group">
                <label for="daily_limit">Günlük İstek Limiti</label>
                <input type="number" id="daily_limit" name="daily_limit" value="<?php echo htmlspecialchars($user['daily_limit']); ?>" required>
            </div>
            <div class="input-group">
                <label for="new_password">Yeni Şifre (Değiştirmek istemiyorsanız boş bırakın)</label>
                <input type="password" id="new_password" name="new_password" minlength="6">
            </div>
            <button type="submit" class="form-button">Güncelle</button>
        </form>
        <a href="/test-gold/admin_panel" class="back-link">Yönetici Paneline Geri Dön</a>
    </div>
</body>
</html>