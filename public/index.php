<?php
// public/index.php (Yeni Front Controller)

// İstenen yolu al (örn: /test-gold/login -> /login)
$request_uri = $_SERVER['REQUEST_URI'];
$base_path = '/test-gold'; // Projenizin alt klasörü
$route = str_replace($base_path, '', $request_uri);
$route = strtok($route, '?'); // Soru işaretinden sonrasını (parametreleri) at

// Temel yönlendirme
switch ($route) {
    case '/':
    case '':
        require __DIR__ . '/home.php'; // Ana sayfa için yeni bir dosya kullanmak daha temiz
        break;
    case '/login':
        require __DIR__ . '/login.php';
        break;
    case '/register':
        require __DIR__ . '/register.php';
        break;
    case '/panel':
        require __DIR__ . '/panel.php';
        break;
    case '/profile':
        require __DIR__ . '/profile.php';
        break;
    case '/logout':
        require __DIR__ . '/logout.php';
        break;
    case '/callback':
        require __DIR__ . '/callback.php';
        break;
    // ... gelecekteki diğer sayfalar buraya eklenebilir ...
    default:
        http_response_code(404);
        echo "Sayfa Bulunamadı";
        break;
}
?>
