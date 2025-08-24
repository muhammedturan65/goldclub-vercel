<?php
// user_delete.php - Kullanıcı Silme İşlemcisi

require_once 'db.php';
// Güvenlik: Yönetici girişi yapılmamışsa erişimi engelle
if (!isset($_SESSION['admin_id'])) {
    header("Location: /test-gold/admin_login");
    exit();
}

// URL'den silinecek kullanıcının ID'sini al
if (isset($_GET['id']) && is_numeric($_GET['id'])) {
    $user_id_to_delete = $_GET['id'];

    try {
        // İlgili kullanıcıyı 'users' tablosundan sil
        $stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
        $stmt->execute([$user_id_to_delete]);
        
        // Bonus: İleride bu kullanıcıya ait Gist'leri de veritabanından silebiliriz.
        // $stmt_gists = $conn->prepare("DELETE FROM user_gists WHERE user_id = ?");
        // $stmt_gists->execute([$user_id_to_delete]);

    } catch (PDOException $e) {
        die("Silme işlemi sırasında bir hata oluştu: " . $e->getMessage());
    }
}

// İşlem bittikten sonra yönetici paneline geri yönlendir
header("Location: /test-gold/admin_panel");
exit();
?>