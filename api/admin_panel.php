<?php
// admin_panel.php - Gelişmiş Yönetim Paneli (Tüm Özellikler Entegre)

require_once 'db.php';
// Güvenlik: Eğer bir yönetici oturumu yoksa, yönetici giriş sayfasına yönlendir.
if (!isset($_SESSION['admin_id'])) {
    header("Location: /test-gold/admin_login");
    exit();
}

// --- AJAX İSTEKLERİNİ YÖNETEN ANA BLOK ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    $action = $_POST['action'];

    try {
        // Tek Kullanıcı Güncelleme (Pop-up'tan gelir)
        if ($action == 'update_user' && isset($_POST['user_id'])) {
            $user_id = $_POST['user_id'];
            $daily_limit = $_POST['daily_limit'];
            $new_password = $_POST['new_password'];
            $is_active = isset($_POST['is_active']) ? 1 : 0;

            if (!empty($new_password)) {
                if (strlen($new_password) < 6) { echo json_encode(['status' => 'error', 'message' => 'Yeni şifre en az 6 karakter olmalıdır.']); exit(); }
                $hashed_password = password_hash($new_password, PASSWORD_BCRYPT);
                $stmt = $conn->prepare("UPDATE users SET daily_limit = ?, is_active = ?, password = ? WHERE id = ?");
                $stmt->execute([$daily_limit, $is_active, $hashed_password, $user_id]);
            } else {
                $stmt = $conn->prepare("UPDATE users SET daily_limit = ?, is_active = ? WHERE id = ?");
                $stmt->execute([$daily_limit, $is_active, $user_id]);
            }
            echo json_encode(['status' => 'success', 'message' => 'Kullanıcı başarıyla güncellendi.']);
        }
        // Yönetici Şifresi Güncelleme
        elseif ($action == 'update_admin_password' && isset($_POST['current_password'], $_POST['new_password'])) {
            $stmt = $conn->prepare("SELECT password FROM admins WHERE id = ?");
            $stmt->execute([$_SESSION['admin_id']]);
            $admin = $stmt->fetch();
            if ($admin && password_verify($_POST['current_password'], $admin['password'])) {
                if (strlen($_POST['new_password']) < 6) { echo json_encode(['status' => 'error', 'message' => 'Yeni şifre en az 6 karakter olmalıdır.']); exit(); }
                $new_hashed_password = password_hash($_POST['new_password'], PASSWORD_BCRYPT);
                $stmt_update = $conn->prepare("UPDATE admins SET password = ? WHERE id = ?");
                $stmt_update->execute([$new_hashed_password, $_SESSION['admin_id']]);
                echo json_encode(['status' => 'success', 'message' => 'Yönetici şifresi başarıyla değiştirildi.']);
            } else {
                echo json_encode(['status' => 'error', 'message' => 'Mevcut şifre yanlış.']);
            }
        }
        // Toplu İşlemler
        elseif ($action == 'bulk_action' && isset($_POST['user_ids']) && is_array($_POST['user_ids'])) {
            $userIds = $_POST['user_ids'];
            $bulkType = $_POST['bulk_type'];
            if (empty($userIds)) { echo json_encode(['status' => 'error', 'message' => 'Hiç kullanıcı seçilmedi.']); exit(); }
            $placeholders = implode(',', array_fill(0, count($userIds), '?'));
            $message = '';

            if ($bulkType == 'delete') {
                $stmt = $conn->prepare("DELETE FROM users WHERE id IN ($placeholders)");
                $message = count($userIds) . ' kullanıcı başarıyla silindi.';
            } elseif ($bulkType == 'activate') {
                $stmt = $conn->prepare("UPDATE users SET is_active = 1 WHERE id IN ($placeholders)");
                $message = count($userIds) . ' kullanıcı başarıyla aktifleştirildi.';
            } elseif ($bulkType == 'deactivate') {
                $stmt = $conn->prepare("UPDATE users SET is_active = 0 WHERE id IN ($placeholders)");
                $message = count($userIds) . ' kullanıcı başarıyla pasif hale getirildi.';
            }
            if(isset($stmt)) {
                $stmt->execute($userIds);
                echo json_encode(['status' => 'success', 'message' => $message]);
            }
        }
    } catch (PDOException $e) {
        echo json_encode(['status' => 'error', 'message' => 'Veritabanı hatası: ' . $e->getMessage()]);
    }
    exit();
}

// --- SAYFA YÜKLENİRKEN VERİ ÇEKME ---
try {
    // İstatistikler
    $total_users = $conn->query("SELECT COUNT(id) FROM users")->fetchColumn();
    $today_requests = $conn->query("SELECT COUNT(id) FROM activity_logs WHERE DATE(created_at) = CURDATE()")->fetchColumn() ?: 0;
    $active_users_today = $conn->query("SELECT COUNT(DISTINCT user_id) FROM activity_logs WHERE DATE(created_at) = CURDATE()")->fetchColumn() ?: 0;
    
    // Kullanıcı ve Log Listeleri
    $users = $conn->query("SELECT id, username, is_active, created_at, daily_limit, last_request_date, request_count_today FROM users ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
    $logs = $conn->query("SELECT username, action, status, created_at FROM activity_logs ORDER BY id DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) { die("Veritabanı sorgu hatası: " . $e->getMessage()); }
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <title>Gelişmiş Yönetici Paneli</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script src="https://cdn.jsdelivr.net/npm/feather-icons/dist/feather.min.js"></script>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; background: #1a1a1a; color: #e0e0e0; margin: 0; padding: 20px; }
        .container { max-width: 1200px; margin: 20px auto; }
        .panel-header { display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; margin-bottom: 30px; gap: 15px; }
        .panel-header h1 { margin: 0; color: #ffc107; }
        .header-actions { display: flex; gap: 20px; }
        .header-actions a, .header-actions button { display: flex; align-items: center; gap: 8px; color: #ffc107; text-decoration: none; font-weight: 500; background: none; border: none; font-size: 16px; font-family: inherit; cursor: pointer; }
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .stat-card { background: #2a2a2a; padding: 25px; border-radius: 12px; border: 1px solid #444; }
        .stat-card h3 { margin-top: 0; color: #a0a0a0; font-size: 16px; font-weight: 500; }
        .stat-card p { margin-bottom: 0; font-size: 36px; font-weight: bold; }
        .panel { padding: 30px; background: #2a2a2a; border-radius: 12px; border: 1px solid #444; }
        .panel-title { margin-top:0; }
        .search-bar { margin-bottom: 20px; }
        .search-bar input { width: 100%; padding: 12px; background-color: #1a1a1a; border: 1px solid #444; border-radius: 8px; color: #fff; font-size: 16px; box-sizing: border-box; }
        .user-table { width: 100%; border-collapse: collapse; }
        .user-table th, .user-table td { padding: 12px 15px; border-bottom: 1px solid #444; text-align: left; vertical-align: middle;}
        .user-table th { color: #a0a0a0; cursor: pointer; user-select: none; }
        .user-table th:hover { color: #fff; }
        .user-table tbody tr:hover { background-color: #333; }
        .actions-cell button, .actions-cell a { display: inline-flex; align-items: center; gap: 5px; color: #ffc107; text-decoration: none; margin-right: 15px; padding: 5px 8px; border-radius: 5px; transition: background-color 0.2s; background: none; border: none; font-size: 14px; font-family: inherit; cursor: pointer; }
        .actions-cell button:hover, .actions-cell a:hover { background-color: rgba(255, 193, 7, 0.1); }
        .actions-cell a.delete-link { color: #f44336; }
        .actions-cell a.delete-link:hover { background-color: rgba(244, 67, 54, 0.1); }
        .status-badge { padding: 4px 8px; border-radius: 12px; font-size: 12px; font-weight: bold; }
        .status-active { background-color: rgba(29, 215, 96, 0.2); color: #1ed760; } 
        .status-inactive { background-color: rgba(244, 67, 54, 0.2); color: #f44336; }
        .bulk-actions { margin-top: 20px; display: flex; gap: 10px; align-items: center; }
        .bulk-actions select, .bulk-actions button { padding: 10px; background-color: #333; border: 1px solid #555; color: #fff; border-radius: 8px; font-size: 14px; cursor: pointer; }
        .modal-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.7); display: none; justify-content: center; align-items: center; z-index: 1000; opacity: 0; transition: opacity 0.3s ease; }
        .modal-overlay.visible { display: flex; opacity: 1; }
        .modal-content { width: 400px; padding: 40px; background: #2a2a2a; border-radius: 12px; border: 1px solid #444; position: relative; transform: scale(0.95); transition: transform 0.3s ease; }
        .modal-overlay.visible .modal-content { transform: scale(1); }
        .modal-close { position: absolute; top: 15px; right: 15px; background: none; border: none; color: #a0a0a0; cursor: pointer; padding: 5px; }
        .input-group { margin-bottom: 20px; } .input-group label { display: block; margin-bottom: 8px; color: #a0a0a0; }
        .input-group input { width: 100%; padding: 12px; background-color: rgba(0,0,0,0.25); border: 1px solid #444; border-radius: 8px; color: #fff; font-size: 16px; box-sizing: border-box; }
        .form-button { width: 100%; background-image: linear-gradient(90deg, #ffc107, #ff9800); color: #101014; border: none; padding: 14px; font-size: 16px; font-weight: bold; border-radius: 8px; cursor: pointer; }
        .message { text-align: center; padding: 10px; border-radius: 5px; margin-bottom: 20px; }
        .message.error { background-color: #5c2323; color: #ffbaba; }
        .message.success { background-color: #1e4620; color: #c6f6d5; }
        .toggle-switch-group { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
        .toggle-switch { position: relative; display: inline-block; width: 40px; height: 20px; }
        .toggle-switch input { opacity: 0; width: 0; height: 0; }
        .slider { position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background-color: #555; border-radius: 10px; transition: .4s; }
        .slider:before { position: absolute; content: ""; height: 16px; width: 16px; left: 2px; bottom: 2px; background-color: white; border-radius: 50%; transition: .4s; }
        input:checked + .slider { background-color: #1ed760; }
        input:checked + .slider:before { transform: translateX(20px); }
    </style>
</head>
<body>
    <div class="container">
        <div class="panel-header">
            <h1>Yönetici Paneli</h1>
            <div class="header-actions">
                <button id="change-password-btn"><i data-feather="key"></i> Şifre Değiştir</button>
                <a href="/test-gold/logout"><i data-feather="log-out"></i> Güvenli Çıkış</a>
            </div>
        </div>

        <div class="stats-grid">
            <div class="stat-card"><h3>Toplam Kullanıcı</h3><p><?php echo $total_users; ?></p></div>
            <div class="stat-card"><h3>Bugünkü İstekler</h3><p><?php echo $today_requests; ?></p></div>
            <div class="stat-card"><h3>Bugün Aktif Kullanıcı</h3><p><?php echo $active_users_today; ?></p></div>
        </div>

        <div class="panel">
            <h2 class="panel-title">Kullanıcı Yönetimi</h2>
            <div class="search-bar">
                <input type="text" id="search-input" placeholder="Kullanıcı adında ara...">
            </div>
            <div style="overflow-x: auto;">
                <table class="user-table">
                    <thead>
                        <tr>
                            <th><input type="checkbox" id="select-all-users"></th>
                            <th>ID</th><th>Kullanıcı Adı</th><th>Durum</th><th>Kayıt Tarihi</th><th>Limit</th><th>Aksiyonlar</th>
                        </tr>
                    </thead>
                    <tbody id="user-table-body">
                        <?php if (empty($users)): ?>
                            <tr><td colspan="7" style="text-align: center;">Henüz kayıtlı kullanıcı bulunmuyor.</td></tr>
                        <?php else: ?>
                            <?php foreach ($users as $user): ?>
                                <tr id="user-row-<?php echo $user['id']; ?>">
                                    <td><input type="checkbox" class="user-checkbox" value="<?php echo $user['id']; ?>"></td>
                                    <td><?php echo htmlspecialchars($user['id']); ?></td>
                                    <td><?php echo htmlspecialchars($user['username']); ?></td>
                                    <td><span class="status-badge status-<?php echo $user['is_active'] ? 'active' : 'inactive'; ?>"><?php echo $user['is_active'] ? 'Aktif' : 'Pasif'; ?></span></td>
                                    <td><?php echo htmlspecialchars(date('d M Y', strtotime($user['created_at']))); ?></td>
                                    <td><?php echo htmlspecialchars($user['daily_limit']); ?></td>
                                    <td class="actions-cell">
                                        <button class="edit-btn" data-id="<?php echo $user['id']; ?>" data-username="<?php echo htmlspecialchars($user['username']); ?>" data-limit="<?php echo $user['daily_limit']; ?>" data-active="<?php echo $user['is_active']; ?>"><i data-feather="edit-2" style="width:16px;"></i> Düzenle</button>
                                        <a href="/test-gold/user_delete?id=<?php echo $user['id']; ?>" class="delete-link" onclick="return confirm('Bu kullanıcıyı silmek istediğinizden emin misiniz?');"><i data-feather="trash-2" style="width:16px;"></i> Sil</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <div class="bulk-actions">
                <select id="bulk-action-select"><option value="">Toplu İşlem Seç</option><option value="delete">Seçilenleri Sil</option><option value="activate">Seçilenleri Aktifleştir</option><option value="deactivate">Seçilenleri Pasifleştir</option></select>
                <button id="apply-bulk-action">Uygula</button>
            </div>
        </div>
        
        <div class="panel" style="margin-top: 30px;">
            <h2 class="panel-title">Son Aktiviteler (Son 10)</h2>
            <div style="overflow-x: auto;">
                <table class="user-table">
                    <thead><tr><th>Kullanıcı</th><th>Eylem</th><th>Durum</th><th>Zaman</th></tr></thead>
                    <tbody>
                        <?php if (empty($logs)): ?>
                            <tr><td colspan="4" style="text-align: center;">Henüz bir aktivite kaydedilmedi.</td></tr>
                        <?php else: ?>
                            <?php foreach ($logs as $log): ?>
                                <tr><td><?php echo htmlspecialchars($log['username']); ?></td><td><?php echo htmlspecialchars($log['action']); ?></td><td><span class="status-badge status-<?php echo strtolower($log['status']) == 'başarılı' ? 'active' : 'inactive'; ?>"><?php echo htmlspecialchars($log['status']); ?></span></td><td><?php echo htmlspecialchars(date('d M Y H:i', strtotime($log['created_at']))); ?></td></tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Modallar -->
    <div class="modal-overlay" id="edit-user-modal"> <div class="modal-content"> <button class="modal-close"><i data-feather="x"></i></button> <h1 id="modal-title">Kullanıcı Düzenle</h1> <div id="modal-message"></div> <form id="edit-form"> <input type="hidden" name="action" value="update_user"> <input type="hidden" id="edit-user-id" name="user_id"> <div class="toggle-switch-group"><label for="edit-is-active">Hesap Aktif</label><label class="toggle-switch"><input type="checkbox" id="edit-is-active" name="is_active"><span class="slider"></span></label></div> <div class="input-group"><label for="edit-daily-limit">Günlük İstek Limiti</label><input type="number" id="edit-daily-limit" name="daily_limit" required></div> <div class="input-group"><label for="edit-new-password">Yeni Şifre (Boş bırakırsanız değişmez)</label><input type="password" id="edit-new-password" name="new_password" minlength="6"></div> <button type="submit" class="form-button">Güncelle</button> </form> </div> </div>
    <div class="modal-overlay" id="change-password-modal"> <div class="modal-content"> <button class="modal-close"><i data-feather="x"></i></button> <h1>Şifre Değiştir</h1> <div id="password-modal-message"></div> <form id="change-password-form"> <input type="hidden" name="action" value="update_admin_password"> <div class="input-group"><label for="current-password">Mevcut Şifre</label><input type="password" id="current-password" name="current_password" required></div> <div class="input-group"><label for="new-password-admin">Yeni Şifre</label><input type="password" id="new-password-admin" name="new_password" required minlength="6"></div> <button type="submit" class="form-button">Şifreyi Değiştir</button> </form> </div> </div>

    <script>
        feather.replace();
        
        // Arama Mantığı
        document.getElementById('search-input').addEventListener('keyup', function() {
            const filter = this.value.toLowerCase();
            const rows = document.getElementById('user-table-body').getElementsByTagName('tr');
            Array.from(rows).forEach(row => {
                const usernameCell = row.cells[2];
                if (usernameCell.textContent.toLowerCase().includes(filter)) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
        });

        // Toplu İşlemler Mantığı
        const selectAllCheckbox = document.getElementById('select-all-users');
        const userCheckboxes = document.querySelectorAll('.user-checkbox');
        selectAllCheckbox.addEventListener('change', () => userCheckboxes.forEach(checkbox => checkbox.checked = selectAllCheckbox.checked));
        document.getElementById('apply-bulk-action').addEventListener('click', () => {
            const selectedIds = Array.from(userCheckboxes).filter(cb => cb.checked).map(cb => cb.value);
            const bulkType = document.getElementById('bulk-action-select').value;
            if (!bulkType || selectedIds.length === 0) { alert('Lütfen bir işlem seçin ve en az bir kullanıcı işaretleyin.'); return; }
            if (confirm(`${selectedIds.length} kullanıcı üzerinde "${bulkType}" işlemi yapmak istediğinizden emin misiniz?`)) {
                const formData = new FormData();
                formData.append('action', 'bulk_action');
                formData.append('bulk_type', bulkType);
                selectedIds.forEach(id => formData.append('user_ids[]', id));
                fetch('admin_panel.php', { method: 'POST', body: formData }).then(res => res.json()).then(data => {
                    alert(data.message);
                    if (data.status === 'success') window.location.reload();
                });
            }
        });

        // Modal (Pop-up) Yönetimi
        function openModal(modal) { modal.classList.add('visible'); feather.replace(); }
        function closeModal(modal) { modal.classList.remove('visible'); }

        // Kullanıcı Düzenleme Modal'ı
        const editUserModal = document.getElementById('edit-user-modal');
        const userTableBody = document.getElementById('user-table-body');
        const editForm = document.getElementById('edit-form');
        
        userTableBody.addEventListener('click', (event) => {
            const editButton = event.target.closest('.edit-btn');
            if (editButton) {
                document.getElementById('modal-title').textContent = `"${editButton.dataset.username}" Kullanıcısını Düzenle`;
                document.getElementById('edit-user-id').value = editButton.dataset.id;
                document.getElementById('edit-daily-limit').value = editButton.dataset.limit;
                document.getElementById('edit-is-active').checked = editButton.dataset.active == '1';
                document.getElementById('edit-new-password').value = '';
                document.getElementById('modal-message').innerHTML = '';
                openModal(editUserModal);
            }
        });

        editForm.addEventListener('submit', (event) => {
            event.preventDefault();
            const formData = new FormData(editForm);
            fetch('admin_panel.php', { method: 'POST', body: formData })
            .then(response => response.json())
            .then(data => {
                const messageEl = document.getElementById('modal-message');
                let messageClass = data.status === 'success' ? 'success' : 'error';
                messageEl.innerHTML = `<p class="message ${messageClass}">${data.message}</p>`;
                if (data.status === 'success') {
                    setTimeout(() => window.location.reload(), 1500);
                }
            });
        });

        // Yönetici Şifre Değiştirme Modal'ı
        const changePasswordModal = document.getElementById('change-password-modal');
        document.getElementById('change-password-btn').addEventListener('click', () => {
            document.getElementById('change-password-form').reset();
            document.getElementById('password-modal-message').innerHTML = '';
            openModal(changePasswordModal);
        });

        document.getElementById('change-password-form').addEventListener('submit', (event) => {
            event.preventDefault();
            const formData = new FormData(event.target);
            fetch('admin_panel.php', { method: 'POST', body: formData })
            .then(res => res.json())
            .then(data => {
                const messageEl = document.getElementById('password-modal-message');
                let messageClass = data.status === 'success' ? 'success' : 'error';
                messageEl.innerHTML = `<p class="message ${messageClass}">${data.message}</p>`;
                if (data.status === 'success') {
                    setTimeout(() => closeModal(changePasswordModal), 2000);
                }
            });
        });

        // Tüm Modalları Kapatma Eventleri
        document.querySelectorAll('.modal-overlay').forEach(modal => {
            modal.addEventListener('click', (event) => {
                if (event.target === modal) closeModal(modal);
            });
        });
        document.querySelectorAll('.modal-close').forEach(closeBtn => {
            closeBtn.addEventListener('click', () => {
                closeModal(closeBtn.closest('.modal-overlay'));
            });
        });
    </script>
</body>
</html>