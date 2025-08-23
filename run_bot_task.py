# run_bot_task.py (Kullanıcı ID'si Entegre Edilmiş Nihai Sürüm)
import os
import sys
from gold_club_bot import GoldClubBot
from app import get_db_connection, send_email_notification, upload_to_gist
from datetime import datetime
import json
from http.server import BaseHTTPRequestHandler
from urllib.parse import urlparse, parse_qs # <- Bu satır önemli

def run_bot(user_id=None, target_group="TURKISH"): # <- Fonksiyona parametreler eklendi
    """
    Botu çalıştırır, sonuçları alır, veritabanına kaydeder,
    KULLANICIYA ÖZEL Gist'e yükler ve e-posta gönderir.
    """
    if user_id:
        print(f"İstek kullanıcı ID'si {user_id} için başlatıldı. Grup: {target_group}")
    else:
        print(f"Genel istek başlatıldı (kullanıcı belirtilmedi). Grup: {target_group}")

    try:
        email = os.environ['APP_EMAIL']
        password = os.environ['APP_PASSWORD']
    except KeyError as e:
        print(f"HATA: Gerekli ortam değişkeni bulunamadı: {e}")
        return

    # Botu, parametre olarak gelen target_group ile başlat
    bot = GoldClubBot(email=email, password=password, target_group=target_group)
    result_data = bot.run_full_process()

    if not result_data or "url" not in result_data or not result_data.get('channels'):
        print("Bot işlemi başarısız oldu veya uygun kanal bulunamadı. Veritabanına kayıt yapılmayacak.")
        return

    print(f"Bot işlemi başarılı. {len(result_data['channels'])} kanal bulundu. Veritabanına kaydediliyor...")

    conn = get_db_connection()
    if not conn:
        print("Veritabanı bağlantısı kurulamadı. İşlem sonlandırılıyor.")
        return

    with conn.cursor() as cur:
        cur.execute(
            """
            INSERT INTO generated_links (m3u_url, expiry_date, channel_count, playlist_data)
            VALUES (%s, %s, %s, %s) RETURNING id;
            """,
            (
                result_data['url'],
                result_data['expiry'],
                len(result_data['channels']),
                json.dumps(result_data['channels'])
            )
        )
        new_id = cur.fetchone()[0]
        conn.commit()
    conn.close()
    print(f"Yeni kayıt ID {new_id} ile veritabanına eklendi.")
    
    # --- M3U İÇERİĞİ OLUŞTURMA ---
    m3u_content = "#EXTM3U\n"
    for ch in result_data['channels']:
        m3u_content += f'#EXTINF:-1 group-title="{ch["group"]}",{ch["name"]}\n{ch["url"]}\n'

    # --- KULLANICIYA ÖZEL GIST AÇIKLAMASI OLUŞTURMA ---
    timestamp = datetime.now().strftime("%Y-%m-%d %H:%M")
    gist_filename = f"playlist_{target_group}_{timestamp}.m3u"
    
    # Eğer bir user_id geldiyse, açıklamaya etiket olarak ekle
    user_tag = f"(User: {user_id})" if user_id else ""
    gist_description = f"Filtrelenmiş Playlist ({target_group}) - {timestamp} {user_tag}"
    
    print(f"-> Playlist GitHub Gist'e yükleniyor... Açıklama: '{gist_description}'")
    public_url, error = upload_to_gist(gist_filename, m3u_content, gist_description)
    
    # --- E-POSTA BİLDİRİMİ ---
    if public_url:
        print(f"-> Gist başarıyla yüklendi: {public_url}")
        email_subject = f"Yeni Playlist Oluşturuldu ({target_group})"
        email_body = (f"Yeni bir M3U playlist'i başarıyla oluşturuldu ve Gist'e yüklendi.\n\n"
                      f"Kullanıcı ID: {user_id or 'Belirtilmedi'}\n"
                      f"Link: {public_url}\n"
                      f"Kanal Sayısı: {len(result_data['channels'])}\n"
                      f"Son Kullanma Tarihi: {result_data['expiry']}")

        print("-> E-posta bildirimi gönderiliyor...")
        if send_email_notification(email_subject, email_body):
            print("-> E-posta bildirimi başarıyla gönderildi.")
        else:
            print("-> E-posta bildirimi gönderilemedi.")
    else:
        print(f"-> Gist yüklemesi başarısız oldu: {error}")

# --- VERCEL'İN ÇAĞIRACAĞI HANDLER ---
# Bu bölüm, gelen isteği analiz eder ve run_bot fonksiyonuna doğru parametreleri gönderir.
class handler(BaseHTTPRequestHandler):
    def do_GET(self):
        # Gelen URL'yi ve parametrelerini ayrıştır
        parsed_path = urlparse(self.path)
        query_params = parse_qs(parsed_path.query)

        # Parametreleri al, eğer yoksa varsayılan değerleri kullan
        user_id = query_params.get('user_id', [None])[0]
        target_group = query_params.get('group', ['TURKISH'])[0]

        # Önce hızlıca yanıt gönder
        self.send_response(200)
        self.send_header('Content-type', 'application/json')
        self.end_headers()
        response = {'status': 'success', 'message': 'Bot task is running in the background.'}
        self.wfile.write(json.dumps(response).encode('utf-8'))
        
        # Yanıtı gönderdikten sonra asıl bot işlemini doğru parametrelerle çalıştır
        run_bot(user_id=user_id, target_group=target_group)
        return
