<?php
// profile.php - Kişisel Kullanıcı Profili Sayfası

require_once 'db.php';
// Güvenlik: Eğer kullanıcı giriş yapmamışsa, login sayfasına yönlendir.
if (!isset($_SESSION['user_id'])) { 
    header("Location: /test-gold/login"); 
    exit(); 
}

$user_id = $_SESSION['user_id'];
$message = '';
$message_type = '';

// --- AJAX İSTEKLERİNİ YÖNETEN ANA BLOK ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    $action = $_POST['action'];

    // Şifre Değiştirme İsteği
    if ($action == 'change_password') {
        $current_password = $_POST['current_password'];
        $new_password = $_POST['new_password'];

        try {
            $stmt = $conn->prepare("SELECT password FROM users WHERE id = ?");
            $stmt->execute([$user_id]);
            $user = $stmt->fetch();

            if ($user && password_verify($current_password, $user['password'])) {
                if (strlen($new_password) < 6) {
                    echo json_encode(['status' => 'error', 'message' => 'Yeni şifre en az 6 karakter olmalıdır.']);
                    exit();
                }
                $hashed_password = password_hash($new_password, PASSWORD_BCRYPT);
                $stmt_update = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
                $stmt_update->execute([$hashed_password, $user_id]);
                echo json_encode(['status' => 'success', 'message' => 'Şifreniz başarıyla güncellendi.']);
            } else {
                echo json_encode(['status' => 'error', 'message' => 'Mevcut şifreniz yanlış.']);
            }
        } catch (PDOException $e) {
            echo json_encode(['status' => 'error', 'message' => 'Veritabanı hatası oluştu.']);
        }
    }
    // Hesap Silme İsteği
    elseif ($action == 'delete_account') {
        $password_to_confirm = $_POST['password_to_confirm'];

        try {
            $stmt = $conn->prepare("SELECT password FROM users WHERE id = ?");
            $stmt->execute([$user_id]);
            $user = $stmt->fetch();

            if ($user && password_verify($password_to_confirm, $user['password'])) {
                // Not: İlişkili verileri de silmek iyi bir pratiktir (Örn: user_gists, activity_logs)
                // Veritabanı FOREIGN KEY 'ON DELETE CASCADE' olarak ayarlandığı için bu otomatik olabilir.
                $stmt_delete = $conn->prepare("DELETE FROM users WHERE id = ?");
                $stmt_delete->execute([$user_id]);
                
                // Oturumu sonlandır ve çıkış yap
                session_destroy();
                echo json_encode(['status' => 'success', 'message' => 'Hesabınız başarıyla silindi.']);
            } else {
                echo json_encode(['status' => 'error', 'message' => 'Onay için girilen şifre yanlış.']);
            }
        } catch (PDOException $e) {
            echo json_encode(['status' => 'error', 'message' => 'Veritabanı hatası oluştu.']);
        }
    }
    exit();
}

// Sayfa ilk yüklendiğinde kullanıcının bilgilerini çek
try {
    $stmt = $conn->prepare("SELECT username, created_at, daily_limit FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user_info = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Kullanıcı bilgileri alınamadı: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <title>Profilim</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script src="https://cdn.jsdelivr.net/npm/feather-icons/dist/feather.min.js"></script>
    <style>
        @keyframes gradient { 0% {background-position: 0% 50%;} 50% {background-position: 100% 50%;} 100% {background-position: 0% 50%;} }
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; background: #101014; color: #e0e0e0; margin: 0; padding: 15px; background: linear-gradient(-45deg, #101014, #1c131f, #1a1625, #101014); background-size: 400% 400%; animation: gradient 15s ease infinite; }
        .container { max-width: 600px; margin: 20px auto; width: 100%; }
        .panel-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; padding: 0 10px; }
        .panel-header h1 { margin: 0; font-size: 24px; font-weight: 600; }
        .panel-header a { display: flex; align-items: center; gap: 8px; color: #a0a0a0; text-decoration: none; font-weight: 500; }
        .panel { padding: 25px; background: rgba(30, 30, 35, 0.6); border-radius: 16px; border: 1px solid rgba(255, 255, 255, 0.1); backdrop-filter: blur(10px); margin-bottom: 30px; }
        .panel-title { font-size: 20px; font-weight: 600; margin-top: 0; margin-bottom: 20px; padding-bottom: 15px; border-bottom: 1px solid rgba(255,255,255,0.1); }
        .info-grid { display: grid; grid-template-columns: 1fr; gap: 15px; }
        .info-item { display: flex; justify-content: space-between; font-size: 16px; }
        .info-title { color: #a0a0a0; } .info-value { font-weight: 500; }
        .input-group { margin-bottom: 20px; } .input-group label { display: block; margin-bottom: 8px; color: #a0a0a0; }
        .input-group input { width: 100%; padding: 12px; background-color: rgba(0,0,0,0.25); border: 1px solid #444; border-radius: 8px; color: #fff; font-size: 16px; box-sizing: border-box; }
        .form-button { width: 100%; background-image: linear-gradient(90deg, #8A2387, #E94057); color: white; border: none; padding: 14px; font-size: 16px; font-weight: bold; border-radius: 8px; cursor: pointer; }
        .delete-button { background-image: none; background-color: #701a1a; }
        .message { text-align: center; padding: 10px; border-radius: 5px; margin-top: 20px; }
        .message.error { background-color: #5c2323; color: #ffbaba; }
        .message.success { background-color: #1e4620; color: #c6f6d5; }

        @media (min-width: 600px) {
            .panel { padding: 40px; }
            .info-grid { grid-template-columns: repeat(2, 1fr); }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="panel-header">
            <h1>Profilim</h1>
            <a href="/test-gold/panel"><i data-feather="arrow-left"></i> Ana Panele Dön</a>
        </div>

        <div class="panel">
            <h2 class="panel-title">Hesap Bilgileri</h2>
            <div class="info-grid">
                <div class="info-item">
                    <span class="info-title">Kullanıcı Adı:</span>
                    <span class="info-value"><?php echo htmlspecialchars($user_info['username']); ?></span>
                </div>
                <div class="info-item">
                    <span class="info-title">Kayıt Tarihi:</span>
                    <span class="info-value"><?php echo htmlspecialchars(date('d F Y', strtotime($user_info['created_at']))); ?></span>
                </div>
                <div class="info-item">
                    <span class="info-title">Günlük Limit:</span>
                    <span class="info-value"><?php echo htmlspecialchars($user_info['daily_limit']); ?></span>
                </div>
            </div>
        </div>

        <div class="panel">
            <h2 class="panel-title">Şifre Değiştir</h2>
            <form id="change-password-form">
                <input type="hidden" name="action" value="change_password">
                <div class="input-group">
                    <label for="current-password">Mevcut Şifre</label>
                    <input type="password" id="current-password" name="current_password" required>
                </div>
                <div class="input-group">
                    <label for="new-password">Yeni Şifre</label>
                    <input type="password" id="new-password" name="new_password" required minlength="6">
                </div>
                <button type="submit" class="form-button">Şifreyi Güncelle</button>
            </form>
            <div id="password-message"></div>
        </div>

        <div class="panel">
            <h2 class="panel-title" style="color: #f44336;">Tehlikeli Alan</h2>
            <p style="color: #a0a0a0; margin-top: -10px; margin-bottom: 20px;">Bu işlem geri alınamaz. Hesabınızı silmek tüm verilerinizi kalıcı olarak yok edecektir.</p>
            <form id="delete-account-form">
                 <input type="hidden" name="action" value="delete_account">
                <div class="input-group">
                    <label for="password-to-confirm">Onay için mevcut şifrenizi girin</label>
                    <input type="password" id="password-to-confirm" name="password_to_confirm" required>
                </div>
                <button type="submit" class="form-button delete-button">Hesabımı Kalıcı Olarak Sil</button>
            </form>
            <div id="delete-message"></div>
        </div>
    </div>
    
    <script>
        feather.replace();

        function handleFormSubmit(formId, messageId) {
            const form = document.getElementById(formId);
            form.addEventListener('submit', function(event) {
                event.preventDefault();
                const messageEl = document.getElementById(messageId);
                const formData = new FormData(form);

                fetch('', { // Mevcut sayfaya (profile.php) POST yap
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    let messageClass = data.status === 'success' ? 'success' : 'error';
                    messageEl.innerHTML = `<p class="message ${messageClass}">${data.message}</p>`;

                    if (data.status === 'success') {
                        if(formId === 'delete-account-form') {
                            // Hesap silindiyse 3 saniye sonra ana sayfaya yönlendir
                            setTimeout(() => window.location.href = '/test-gold/', 3000);
                        } else {
                            form.reset();
                        }
                    }
                })
                .catch(error => {
                    messageEl.innerHTML = `<p class="message error">Bir ağ hatası oluştu.</p>`;
                });
            });
        }

        handleFormSubmit('change-password-form', 'password-message');
        handleFormSubmit('delete-account-form', 'delete-message');
    </script>
</body>
</html>