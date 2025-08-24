<?php
// panel.php - (v22.0 - Veritabanı Odaklı Sabit Link Mimarisi)

require_once 'db.php';
if (!isset($_SESSION['user_id'])) { header("Location: /test-gold/login"); exit(); }

// --- BU BÖLÜMÜ KENDİ BİLGİLERİNİZLE DOLDURUN ---
$github_token = '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'; // Sadece süre hesaplaması için gerekli olabilir
// ------------------------------------------------

$user_id = $_SESSION['user_id'];

// --- KULLANICI BİLGİLERİNİ ÇEKME (LİMİT VE GIST BİLGİLERİ DAHİL) ---
try {
    $stmt = $conn->prepare("SELECT daily_limit, last_request_date, request_count_today, gist_id, gist_raw_url FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user_data = $stmt->fetch(PDO::FETCH_ASSOC);

    $today = date('Y-m-d');
    if ($user_data['last_request_date'] != $today) {
        $stmt_reset = $conn->prepare("UPDATE users SET request_count_today = 0, last_request_date = ? WHERE id = ?");
        $stmt_reset->execute([$today, $user_id]);
        $user_data['request_count_today'] = 0;
    }
    
    $limit_reached = ($user_data['request_count_today'] >= $user_data['daily_limit']);
} catch (PDOException $e) { die("Kullanıcı verileri alınamadı: " . $e->getMessage()); }

// --- AJAX İSTEKLERİNİ YÖNETEN ANA BLOK ---
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    
    if ($_GET['action'] == 'trigger' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        // Sunucu tarafında limiti tekrar kontrol et
        $stmt_check = $conn->prepare("SELECT daily_limit, last_request_date, request_count_today, gist_id FROM users WHERE id = ?");
        $stmt_check->execute([$user_id]);
        $current_user_data = $stmt_check->fetch(PDO::FETCH_ASSOC);
        if ($current_user_data['last_request_date'] != date('Y-m-d')) { $current_user_data['request_count_today'] = 0; }
        if ($current_user_data['request_count_today'] >= $current_user_data['daily_limit']) {
            echo json_encode(['status' => 'error', 'message' => 'Günlük istek limitinize ulaştınız.']);
            exit();
        }

        // Limiti güncelle ve aktiviteyi logla
        $stmt_update = $conn->prepare("UPDATE users SET request_count_today = request_count_today + 1, last_request_date = ? WHERE id = ?");
        $stmt_update->execute([date('Y-m-d'), $user_id]);
        $stmt_log = $conn->prepare("INSERT INTO activity_logs (user_id, username, action, status) VALUES (?, ?, ?, ?)");
        $stmt_log->execute([$user_id, $_SESSION['username'], 'Playlist Üretimi/Güncellemesi Başlatıldı', 'Başarılı']);

        // Vercel'e GIST ID'sini de gönder
        $gist_id_to_update = $current_user_data['gist_id'];
        $vercel_bot_url = "https://goldclub-vercel.vercel.app/api/run_bot_task?user_id=" . $user_id . "&group=TURKISH";
        if ($gist_id_to_update) {
            $vercel_bot_url .= "&gist_id=" . $gist_id_to_update;
        }
        
        $ch = curl_init(); 
        curl_setopt($ch, CURLOPT_URL, $vercel_bot_url); 
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); 
        curl_setopt($ch, CURLOPT_TIMEOUT, 3); 
        curl_exec($ch); 
        curl_close($ch);
        echo json_encode(['status' => 'success', 'message' => 'Bot tetiklendi, sabit linkiniz güncelleniyor...']);
    }
    // Yeni Eylem: Sadece güncel linki veritabanından çek
    elseif ($_GET['action'] == 'get_latest_link') {
        $stmt = $conn->prepare("SELECT gist_raw_url, gist_id FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $updated_user_data = $stmt->fetch(PDO::FETCH_ASSOC);
        echo json_encode($updated_user_data);
    }
    exit();
}

// --- SABİT LİNK BİLGİSİNİ HAZIRLAMA ---
$playlist_info = null;
if (!empty($user_data['gist_raw_url'])) {
    $playlist_info = [
        'description' => 'Kalıcı Kişisel IPTV Playlist',
        'raw_url' => $user_data['gist_raw_url'],
        'status' => 'ok'
    ];
} else {
    $playlist_info = ['error' => 'Henüz size özel bir playlist linki oluşturulmadı.'];
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <title>Kontrol Paneli</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script src="https://cdn.jsdelivr.net/npm/feather-icons/dist/feather.min.js"></script>
    <style>
        @keyframes gradient { 0% {background-position: 0% 50%;} 50% {background-position: 100% 50%;} 100% {background-position: 0% 50%;} }
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; background: #101014; color: #e0e0e0; margin: 0; padding: 15px; background: linear-gradient(-45deg, #101014, #1c131f, #1a1625, #101014); background-size: 400% 400%; animation: gradient 15s ease infinite; }
        .container { max-width: 900px; margin: 20px auto; width: 100%; }
        .panel-header { display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; margin-bottom: 25px; padding: 0 10px; gap: 15px; }
        .panel-header .welcome-link { text-decoration: none; }
        .panel-header .welcome-link h1 { margin: 0; font-size: 24px; font-weight: 600; color: white; transition: color 0.2s; }
        .panel-header .welcome-link:hover h1 { color: #f0f0f0; }
        .header-actions { display: flex; align-items: center; gap: 20px; }
        .header-actions a { display: flex; align-items: center; gap: 8px; color: #a0a0a0; text-decoration: none; font-weight: 500; transition: color 0.2s; }
        .header-actions a.logout-link { color: #E94057; }
        .header-actions a:hover { color: #fff; }
        .dashboard-grid { display: grid; grid-template-columns: 1fr; gap: 30px; }
        .panel { padding: 25px; background: rgba(30, 30, 35, 0.6); border-radius: 16px; border: 1px solid rgba(255, 255, 255, 0.1); backdrop-filter: blur(10px); }
        .panel-title { font-size: 20px; font-weight: 600; margin-top: 0; margin-bottom: 20px; padding-bottom: 15px; border-bottom: 1px solid rgba(255,255,255,0.1); }
        .trigger-button { margin-top: 10px; display: flex; align-items: center; justify-content: center; gap: 10px; width: 100%; background-image: linear-gradient(90deg, #8A2387, #E94057, #F27121); color: white; border: none; padding: 16px 24px; font-size: 16px; font-weight: bold; border-radius: 8px; cursor: pointer; transition: all 0.3s ease; box-sizing: border-box; }
        .trigger-button:disabled { background: #333; cursor: not-allowed; opacity: 0.6; }
        #response-area { text-align: center; margin-top: 15px; min-height: 24px; }
        #response-message { transition: color 0.3s; }
        .progress-bar { width: 100%; background-color: rgba(0,0,0,0.3); border-radius: 5px; overflow: hidden; height: 8px; margin-top: 15px; display: none; }
        .progress-bar-inner { width: 0%; height: 100%; background-image: linear-gradient(90deg, #8A2387, #E94057); border-radius: 5px; transition: width 75s linear, background-color 0.5s; }
        .progress-bar-inner.success { background-image: none; background-color: #1ed760; }
        .progress-bar-inner.error { background-image: none; background-color: #f44336; }
        .spinner { animation: spin 1s linear infinite; } @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
        .gist-table { width: 100%; margin-top: 0; border-collapse: collapse; }
        .gist-table th, .gist-table td { padding: 15px; border-bottom: 1px solid rgba(255,255,255,0.1); text-align: left; vertical-align: middle; }
        .gist-table th { color: #a0a0a0; font-size: 14px; }
        .actions-cell { display: flex; gap: 10px; justify-content: flex-end; }
        .action-button { display: flex; align-items: center; gap: 5px; padding: 6px 12px; background-color: rgba(255,255,255,0.1); color: #fff; text-decoration: none; border: none; border-radius: 5px; font-size: 14px; cursor: pointer; transition: background-color 0.2s; }
        .action-button:hover { background-color: rgba(255,255,255,0.2); }
        .status-icon { vertical-align: middle; }
        @media (min-width: 768px) {
            .dashboard-grid { grid-template-columns: 300px 1fr; align-items: start; }
            .panel { padding: 30px; }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="panel-header">
            <a href="/test-gold/profile" title="Profil Ayarları" class="welcome-link"><h1>Hoş Geldin, <?php echo htmlspecialchars($_SESSION['username']); ?>!</h1></a>
            <div class="header-actions">
                <a href="/test-gold/profile" title="Profil Ayarları"><i data-feather="user"></i><span>Profil Ayarları</span></a>
                <a href="/test-gold/logout" class="logout-link"><i data-feather="log-out"></i><span>Çıkış Yap</span></a>
            </div>
        </div>
        
        <div class="dashboard-grid">
            <div class="panel" id="control-panel">
                <h2 class="panel-title">Kontrol Merkezi</h2>
                <button type="submit" id="trigger-button" class="trigger-button" <?php if ($limit_reached) echo 'disabled'; ?>>
                    <i data-feather="play-circle"></i><span>Playlist'i Oluştur / Güncelle</span>
                </button>
                <div id="response-area">
                    <p id="response-message">
                        <?php if ($limit_reached): ?>
                            <span style="color: #ffc107;">Günlük limitinize (<?php echo htmlspecialchars($user_data['daily_limit']); ?>) ulaştınız.</span>
                        <?php endif; ?>
                    </p>
                    <div class="progress-bar" id="progress-bar"><div class="progress-bar-inner" id="progress-bar-inner"></div></div>
                </div>
                 <p style="text-align: center; color: #a0a0a0; margin-top: 15px;">
                    Kalan Hak: <strong id="limit-counter"><?php echo max(0, $user_data['daily_limit'] - $user_data['request_count_today']); ?> / <?php echo htmlspecialchars($user_data['daily_limit']); ?></strong>
                </p>
            </div>

            <div class="panel" id="list-panel">
                <h2 class="panel-title">Kalıcı Playlist Linkiniz</h2>
                <div class="gist-list" id="gist-list-container">
                    <?php if (isset($playlist_info['error'])): ?>
                        <p id="no-gists-message" style="text-align: center;"><?php echo htmlspecialchars($playlist_info['error']); ?></p>
                    <?php else: ?>
                        <table class="gist-table">
                            <thead>
                                <tr><th>Durum</th><th>Açıklama</th><th>Aksiyonlar</th></tr>
                            </thead>
                            <tbody id="gist-table-body">
                                <tr id="playlist-row" class="status-<?php echo htmlspecialchars($playlist_info['status']); ?>">
                                    <td><span class="cell-content"><i class="status-icon" data-feather="check-circle" style="color: #1ed760;"></i></span></td>
                                    <td><span class="cell-content"><?php echo htmlspecialchars($playlist_info['description']); ?></span></td>
                                    <td>
                                        <div class="actions-cell">
                                            <a href="<?php echo htmlspecialchars($playlist_info['raw_url']); ?>" class="action-button" target="_blank" title="İndir"><i data-feather="download"></i></a>
                                            <button class="action-button copy-btn" data-url="<?php echo htmlspecialchars($playlist_info['raw_url']); ?>" title="Linki Kopyala"><i data-feather="copy"></i></button>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <script>
        feather.replace();
        
        const triggerButton = document.getElementById('trigger-button');
        const responseMessage = document.getElementById('response-message');
        const progressBar = document.getElementById('progress-bar');
        const progressBarInner = document.getElementById('progress-bar-inner');
        const gistListContainer = document.getElementById('gist-list-container');
        const limitCounter = document.getElementById('limit-counter');
        const dailyLimit = <?php echo (int)$user_data['daily_limit']; ?>;
        let requestCountToday = <?php echo (int)$user_data['request_count_today']; ?>;
        let uiResetTimer;

        function updateUIWithNewLink(data) {
            const noGistsMessage = document.getElementById('no-gists-message');
            if (noGistsMessage) {
                const listContainer = document.getElementById('gist-list-container');
                listContainer.innerHTML = `
                    <table class="gist-table">
                        <thead><tr><th>Durum</th><th>Açıklama</th><th>Aksiyonlar</th></tr></thead>
                        <tbody id="gist-table-body">
                            <tr id="playlist-row" class="status-ok">
                                <td><span class="cell-content"><i class="status-icon" data-feather="check-circle" style="color: #1ed760;"></i></span></td>
                                <td><span class="cell-content">Kalıcı Kişisel IPTV Playlist</span></td>
                                <td>
                                    <div class="actions-cell">
                                        <a href="${data.gist_raw_url}" class="action-button" target="_blank" title="İndir"><i data-feather="download"></i></a>
                                        <button class="action-button copy-btn" data-url="${data.gist_raw_url}" title="Linki Kopyala"><i data-feather="copy"></i></button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>`;
            } else {
                const downloadBtn = document.querySelector('#playlist-row .action-button[title="İndir"]');
                const copyBtn = document.querySelector('#playlist-row .copy-btn');
                if(downloadBtn) downloadBtn.href = data.gist_raw_url;
                if(copyBtn) copyBtn.dataset.url = data.gist_raw_url;
            }
            feather.replace();
            navigator.clipboard.writeText(data.gist_raw_url);
        }

        function resetUI() {
            triggerButton.disabled = (requestCountToday >= dailyLimit);
            if (!triggerButton.disabled) {
                triggerButton.innerHTML = '<i data-feather="play-circle"></i><span>Playlist\'i Oluştur / Güncelle</span>';
            } else {
                triggerButton.innerHTML = '<span>Günlük Limite Ulaşıldı</span>';
                responseMessage.innerHTML = `<span style="color: #ffc107;">Günlük limitinize ulaştınız.</span>`;
            }
            progressBar.style.display = 'none';
            progressBarInner.style.transition = 'none';
            progressBarInner.style.width = '0%';
            progressBarInner.classList.remove('success', 'error');
            feather.replace();
        }
        
        function pollForDbUpdate() {
            let pollCount = 0; const maxPolls = 36;
            const initialGistId = "<?php echo $user_data['gist_id'] ?? 'none'; ?>";
            const pollInterval = setInterval(() => {
                pollCount++;
                if (pollCount > maxPolls) {
                    clearInterval(pollInterval);
                    responseMessage.textContent = 'Hata: İşlem çok uzun sürdü. Lütfen Vercel loglarını kontrol edin.';
                    responseMessage.style.color = '#f44336';
                    progressBarInner.classList.add('error');
                    uiResetTimer = setTimeout(resetUI, 5000);
                    return;
                }
                fetch('?action=get_latest_link')
                .then(res => res.json())
                .then(data => {
                    if (data.gist_id && data.gist_id !== initialGistId) {
                        clearInterval(pollInterval);
                        updateUIWithNewLink(data);
                        responseMessage.textContent = 'İşlem tamamlandı! Linkiniz başarıyla güncellendi.';
                        responseMessage.style.color = '#1ed760';
                        progressBarInner.style.transition = 'width 0.5s ease-out';
                        progressBarInner.style.width = '100%';
                        progressBarInner.classList.add('success');
                        uiResetTimer = setTimeout(resetUI, 5000);
                    }
                });
            }, 5000);
        }

        triggerButton.addEventListener('click', function(event) {
            event.preventDefault();
            clearTimeout(uiResetTimer);
            triggerButton.disabled = true;
            triggerButton.innerHTML = '<i data-feather="loader" class="spinner"></i><span>İşlem Sürüyor...</span>';
            feather.replace();
            responseMessage.textContent = '';
            progressBar.style.display = 'block';
            progressBarInner.classList.remove('success', 'error');
            progressBarInner.style.transition = 'none';
            progressBarInner.style.width = '0%';

            fetch('?action=trigger', { method: 'POST' })
            .then(res => res.json())
            .then(data => {
                if (data.status === 'success') {
                    requestCountToday++;
                    limitCounter.innerHTML = `${Math.max(0, dailyLimit - requestCountToday)} / ${dailyLimit}`;
                    responseMessage.textContent = data.message;
                    responseMessage.style.color = '#a0a0a0';
                    setTimeout(() => {
                        progressBarInner.style.transition = 'width 75s linear';
                        progressBarInner.style.width = '95%';
                    }, 100);
                    pollForDbUpdate();
                } else { 
                    responseMessage.textContent = `Hata: ${data.message}`;
                    responseMessage.style.color = '#f44336';
                    resetUI();
                }
            })
            .catch(error => {
                responseMessage.textContent = 'Hata: Tetikleme sırasında bir ağ sorunu oluştu.';
                responseMessage.style.color = '#f44336';
                resetUI();
            });
        });
        
        gistListContainer.addEventListener('click', function(event) {
            const copyButton = event.target.closest('.copy-btn');
            if (copyButton) {
                const urlToCopy = copyButton.dataset.url;
                navigator.clipboard.writeText(urlToCopy).then(() => {
                    const originalIcon = copyButton.innerHTML;
                    copyButton.innerHTML = '<i data-feather="check"></i>';
                    feather.replace();
                    setTimeout(() => { copyButton.innerHTML = originalIcon; feather.replace(); }, 2000);
                });
            }
        });
    </script>
</body>
</html>