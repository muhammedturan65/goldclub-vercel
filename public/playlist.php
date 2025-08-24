<?php
// playlist.php - Akıllı ve IPTV Uyumlu Playlist Yönlendirici

// Hataları göstermeyi açalım (sorun giderme için)
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once 'db.php';

// --- BU BÖLÜMÜ KENDİ BİLGİLERİNİZLE DOLDURUN ---
$github_username = 'muhammedturan65';
$github_token = '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'; // <-- GÜVENLİK UYARISI: Bu anahtarı doğrudan koda yazmak risklidir. Ortam değişkenleri (environment variables) kullanmayı düşünün.
$gist_description_prefix = 'Filtrelenmiş Playlist';
// ------------------------------------------------

if (!isset($_GET['username'])) {
    http_response_code(400);
    die('Kullanici adi belirtilmedi.');
}
$username_from_url = $_GET['username'];

try {
    // 1. Kullanıcı adından kullanıcı ID'sini bul
    $stmt = $conn->prepare("SELECT id FROM users WHERE username = ? AND is_active = 1");
    $stmt->execute([$username_from_url]);
    $user = $stmt->fetch();

    if (!$user) {
        http_response_code(404);
        die('Kullanici bulunamadi veya aktif degil.');
    }
    $user_id = $user['id'];

    // 2. Bu kullanıcıya ait en güncel Gist'in raw_url'ini bul
    $api_url = "https://api.github.com/users/" . urlencode($github_username) . "/gists?per_page=30";
    $ch_api = curl_init();
    curl_setopt($ch_api, CURLOPT_URL, $api_url);
    curl_setopt($ch_api, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch_api, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $github_token, 'User-Agent: Vercel-Trigger-PHP-Script', 'Cache-Control: no-cache']);
    $response = curl_exec($ch_api);
    $http_code = curl_getinfo($ch_api, CURLINFO_HTTP_CODE);
    curl_close($ch_api);
    if ($http_code !== 200) { http_response_code(502); die('GitHub API hatasi.'); }

    $all_gists = json_decode($response, true);
    $latest_gist_url = null;

    if (is_array($all_gists)) {
        foreach ($all_gists as $gist) {
            $description = $gist['description'] ?? '';
            $user_tag = "(User: " . $user_id . ")";

            // --- DÜZELTME BURADA YAPILDI ---
            // Hatalı olan "$prefix" değişkeni, yukarıda tanımlanan "$gist_description_prefix" ile değiştirildi.
            if (strpos($description, $gist_description_prefix) === 0 && strpos($description, $user_tag) !== false) {
                $file = current($gist['files']);
                $latest_gist_url = $file['raw_url'] ?? null;
                break; // En güncel olanı (API'den ilk gelen) bulduk, döngüden çık.
            }
        }
    }

    if ($latest_gist_url) {
        // 3. EN GÜVENİLİR YÖNTEM: IPTV oynatıcısını doğrudan en güncel Gist URL'sine yönlendir.
        //    Bu, oynatıcının içeriği doğrudan kaynağından almasını sağlar ve uyumluluk sorunlarını çözer.
        header("Location: " . $latest_gist_url, true, 302);
        exit();
    } else {
        http_response_code(404);
        die('Bu kullanici icin uretilmis bir playlist bulunamadi.');
    }

} catch (PDOException $e) {
    http_response_code(500);
    die("Veritabani hatasi.");
}
?>