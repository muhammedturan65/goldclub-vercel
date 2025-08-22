# run_bot_task.py (Vercel uyumlu, handler eklenmiş hali)
import os
import sys
from gold_club_bot import GoldClubBot
from app import get_db_connection, send_email_notification, upload_to_gist
from datetime import datetime
import json
from http.server import BaseHTTPRequestHandler, HTTPServer
from urllib.parse import urlparse, parse_qs

# --- BOT MANTIĞI AYNI KALIYOR ---
def run_bot():
    """
    Botu çalıştırır, sonuçları alır ve veritabanına kaydeder.
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
    
    # Gist ve E-posta işlemleri burada devam edebilir...
    # (Kodun bu kısmı değişmediği için kısaltılmıştır)

# --- VERCEL'İN ÇAĞIRACAĞI HANDLER FONKSİYONU ---
class handler(BaseHTTPRequestHandler):
    def do_GET(self):
        # Gelen isteğe bir yanıt gönderiyoruz ki istek havada kalmasın
        self.send_response(200)
        self.send_header('Content-type', 'application/json')
        self.end_headers()
        response = {'status': 'success', 'message': 'Bot task is running in the background.'}
        self.wfile.write(json.dumps(response).encode('utf-8'))
        
        # Yanıtı gönderdikten sonra asıl bot işlemini çalıştır
        run_bot()
        return
