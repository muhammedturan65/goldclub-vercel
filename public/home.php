<?php

// index.php - Ana Giriş ve Yönlendirme Sayfası



// Veritabanı bağlantısını ve oturum yönetimini başlat

require_once 'db.php';



// Eğer kullanıcı zaten giriş yapmışsa, beklemeden doğrudan panele yönlendir.

if (isset($_SESSION['user_id'])) {

    header("Location: panel"); // .php olmadan yönlendiriyoruz!

    exit();

}

?>

<!DOCTYPE html>

<html lang="tr">

<head>

    <meta charset="UTF-8">

    <title>GoldClub Playlist Bot</title>

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <style>

        @keyframes gradient { 0% {background-position: 0% 50%;} 50% {background-position: 100% 50%;} 100% {background-position: 0% 50%;} }

        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; background: #101014; color: #e0e0e0; display: flex; justify-content: center; align-items: center; min-height: 100vh; margin: 0; text-align: center; background: linear-gradient(-45deg, #101014, #1c131f, #1a1625, #101014); background-size: 400% 400%; animation: gradient 15s ease infinite; }

        .welcome-container { max-width: 500px; width: 90%; padding: 50px; background: rgba(30, 30, 35, 0.6); border-radius: 16px; box-shadow: 0 8px 32px rgba(0, 0, 0, 0.4); backdrop-filter: blur(12px); border: 1px solid rgba(255, 255, 255, 0.1); }

        h1 { color: #ffffff; margin-top: 0; margin-bottom: 15px; font-size: 32px; }

        p { color: #a0a0a0; font-size: 18px; line-height: 1.6; margin-bottom: 40px; }

        .button-group { display: flex; gap: 20px; justify-content: center; }

        .button { display: inline-block; padding: 15px 30px; border-radius: 8px; font-size: 16px; font-weight: bold; text-decoration: none; transition: all 0.3s ease; }

        .button-primary { background-image: linear-gradient(90deg, #8A2387, #E94057, #F27121); color: white; box-shadow: 0 4px 20px rgba(233, 64, 87, 0.2); }

        .button-primary:hover { transform: translateY(-3px); box-shadow: 0 6px 25px rgba(233, 64, 87, 0.4); }

        .button-secondary { background-color: rgba(255,255,255,0.1); color: white; }

        .button-secondary:hover { background-color: rgba(255,255,255,0.2); }

    </style>

</head>

<body>

    <div class="welcome-container">

        <h1>GoldClub Playlist Bot</h1>

        <p>Kişisel playlist'lerinizi anında üretin ve yönetin. Başlamak için giriş yapın veya yeni bir hesap oluşturun.</p>

        <div class="button-group">

            <a href="/test-gold/login" class="button button-primary">Giriş Yap</a>

            <a href="/test-gold/register" class="button button-secondary">Kayıt Ol</a>

        </div>

    </div>

</body>

</html>
