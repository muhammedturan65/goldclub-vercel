# run_bot_task.py
import os
import sys
from gold_club_bot import GoldClubBot
from app import get_db_connection, send_email_notification, upload_to_gist
from datetime import datetime
import json

# Bu script doğrudan Vercel tarafından çalıştırıldığı için,
# app.py'deki ortam değişkenlerini yeniden okumasına gerek yok,
# Vercel bunları zaten ortama yükler.

def run_bot():
    """
    Botu çalıştırır, sonuçları alır ve veritabanına kaydeder.
    """
    print("Cron Job Tetiklendi: Bot işlemi başlatılıyor...")
    
    # app.py'deki gibi ortam değişkenlerini al
    try:
        email = os.environ['APP_EMAIL']
        password = os.environ['APP_PASSWORD']
    except KeyError as e:
        print(f"HATA: Gerekli ortam değişkeni bulunamadı: {e}")
        return

    # Botu çalıştır (target_group'u None veya varsayılan bir değer yapabilirsiniz)
    bot = GoldClubBot(email=email, password=password, target_group="TURKISH")
    result_data = bot.run_full_process()

    if not result_data or "url" not in result_data or not result_data.get('channels'):
        print("Bot işlemi başarısız oldu veya uygun kanal bulunamadı. Veritabanına kayıt yapılmayacak.")
        return

    print(f"Bot işlemi başarılı. {len(result_data['channels'])} kanal bulundu. Veritabanına kaydediliyor...")

    # Veritabanına kaydet
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
                json.dumps(result_data['channels']) # JSON verisini string'e çevir
            )
        )
        new_id = cur.fetchone()[0]
        conn.commit()
    conn.close()
    print(f"Yeni kayıt ID {new_id} ile veritabanına eklendi.")
    
    # --- Gist'e Yükleme ve E-posta Gönderme (Opsiyonel ama önerilir) ---
    m3u_content = "#EXTM3U\n"
    for ch in result_data['channels']:
        m3u_content += f'#EXTINF:-1 group-title="{ch["group"]}",{ch["name"]}\n{ch["url"]}\n'

    timestamp = datetime.now().strftime("%Y-%m-%d %H:%M")
    gist_filename = f"playlist_turkish_{timestamp}.m3u"
    gist_description = f"Filtrelenmiş Playlist (TURKISH) - {timestamp}"

    public_url, error = upload_to_gist(gist_filename, m3u_content, gist_description)
    
    if public_url:
        print(f"Gist başarıyla yüklendi: {public_url}")
        email_subject = f"Yeni Playlist Oluşturuldu (Otomatik Görev)"
        email_body = f"Yeni bir M3U playlist'i başarıyla oluşturuldu.\n\nLink: {public_url}\nKanal Sayısı: {len(result_data['channels'])}"
        if send_email_notification(email_subject, email_body):
            print("E-posta bildirimi başarıyla gönderildi.")
        else:
            print("E-posta bildirimi gönderilemedi.")
    else:
        print(f"Gist yüklemesi başarısız oldu: {error}")

if __name__ == "__main__":
    run_bot()