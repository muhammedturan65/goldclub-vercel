<?php
// logout.php - Güvenli Çıkış

// Oturumu başlat
session_start();

// Tüm oturum değişkenlerini temizle
$_SESSION = array();

// Oturumu sonlandır
session_destroy();

// Giriş sayfasına yönlendir
header("Location: /test-gold/login");
exit();
?>