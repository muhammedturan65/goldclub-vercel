# run_bot_task.py (Nihai Sürüm - Gist ve E-posta Adımları Eklendi)
import os
import sys
from gold_club_bot import GoldClubBot
from app import get_db_connection, send_email_notification, upload_to_gist
from datetime import datetime
import json
from http.server import BaseHTTPRequestHandler

def run_bot():
    """
    Botu çalıştırır, sonuçları alır, veritabanına kaydeder, Gist'e yükler ve e-posta gönderir.
    """
    print("Cron Job Tetiklendi: Bot işlemi başlatılıyor...")

    try:
        email = os.environ['APP_EMAIL']
        password = os.environ['APP_PASSWORD']
    except KeyError as e:
        print(f"HATA: Gerekli ortam değişkeni bulunamadı: {e}")
        return

    bot = GoldClubBot(email=email, password=password, target_group="TURKISH")
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

    # --- YENİ EKLENEN BÖLÜM: Gist'e Yükleme ve E-posta Gönderme ---
    
    # 1. M3U içeriğini oluştur
    m3u_content = "#EXTM3U\n"
    for ch in result_data['channels']:
        m3u_content += f'#EXTINF:-1 group-title="{ch["group"]}",{ch["name"]}\n{ch["url"]}\n'

    # 2. Gist için dosya adı ve açıklama oluştur
    timestamp = datetime.now().strftime("%Y-%m-%d %H:%M")
    gist_filename = f"playlist_turkish_{timestamp}.m3u"
    gist_description = f"Filtrelenmiş Playlist (TURKISH) - {timestamp}"

    # 3. Gist'e yükle
    print("-> Playlist GitHub Gist'e yükleniyor...")
    public_url, error = upload_to_gist(gist_filename, m3u_content, gist_description)

    # 4. Sonuca göre e-posta gönder
    if public_url:
        print(f"-> Gist başarıyla yüklendi: {public_url}")
        email_subject = f"Yeni Playlist Oluşturuldu (Otomatik Görev)"
        email_body = (f"Yeni bir M3U playlist'i başarıyla oluşturuldu ve Gist'e yüklendi.\n\n"
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

class handler(BaseHTTPRequestHandler):
    def do_GET(self):
        self.send_response(200)
        self.send_header('Content-type', 'application/json')
        self.end_headers()
        response = {'status': 'success', 'message': 'Bot task is running in the background.'}
        self.wfile.write(json.dumps(response).encode('utf-8'))
        run_bot()
        return
