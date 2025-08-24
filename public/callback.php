<?php
// callback.php - Vercel'den gelen veritabanı güncelleme isteklerini işler

require_once 'db.php';

// --- BU BÖLÜMÜ Vercel'e GİRDİĞİNİZ GİZLİ ANAHTARLA GÜNCELLEYİN ---
$expected_secret = 'CokGuvenliBirSifre12345';
// -------------------------------------------------------------

// 1. Güvenlik Kontrolü: Gizli anahtar doğru mu?
if (!isset($_GET['secret']) || $_GET['secret'] !== $expected_secret) {
    http_response_code(403); // Yasak
    die('Erisim reddedildi.');
}

// 2. Gerekli parametreler geldi mi?
if (!isset($_GET['user_id'], $_GET['gist_id'], $_GET['raw_url'])) {
    http_response_code(400); // Hatalı İstek
    die('Eksik parametreler.');
}

// 3. Gelen verileri al
$user_id = $_GET['user_id'];
$gist_id = $_GET['gist_id'];
$raw_url = $_GET['raw_url'];

// 4. Veritabanını Güncelle
try {
    $stmt = $conn->prepare("UPDATE users SET gist_id = ?, gist_raw_url = ? WHERE id = ?");
    $stmt->execute([$gist_id, $raw_url, $user_id]);

    // Başarılı olursa 200 OK yanıtı ver
    http_response_code(200);
    echo "Veritabani basariyla guncellendi.";

} catch (PDOException $e) {
    // Hata olursa 500 Sunucu Hatası yanıtı ver
    http_response_code(500);
    // Hata detayını sunucu loglarına yazmak daha güvenlidir, ama test için böyle bırakabiliriz.
    echo "Veritabani guncelleme hatasi: " . $e->getMessage();
}
?>