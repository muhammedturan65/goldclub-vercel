# run_bot_task.py (Otomatik Gist Silme Özellikli Nihai Sürüm)
import os
import sys
import requests
from datetime import datetime, timezone, timedelta
from gold_club_bot import GoldClubBot
from app import get_db_connection, send_email_notification, upload_to_gist
import json
from http.server import BaseHTTPRequestHandler
from urllib.parse import urlparse, parse_qs

# --- YENİ FONKSİYON: Süresi Dolmuş Gist'leri Temizleme ---
def cleanup_expired_gists():
    """
    GitHub'a bağlanır, kullanıcıya ait Gist'leri listeler ve oluşturulma tarihinin
    üzerinden 48 saatten fazla geçmiş olanları siler.
    """
    print("-> Süresi dolmuş Gist'ler için temizlik işlemi başlatılıyor...")
    
    try:
        # Gerekli bilgileri Vercel ortam değişkenlerinden al
        username = os.environ['GITHUB_USERNAME']
        token = os.environ['GITHUB_TOKEN']
        prefix = os.environ.get('GIST_DESCRIPTION_PREFIX', 'Filtrelenmiş Playlist') # Varsayılan değer ekledik
    except KeyError as e:
        print(f"[TEMİZLİK HATASI] Gerekli ortam değişkeni bulunamadı: {e}. Temizlik atlanıyor.")
        return

    api_url = f"https://api.github.com/users/{username}/gists"
    headers = {
        'Authorization': f'Bearer {token}',
        'Accept': 'application/vnd.github.v3+json',
        'X-GitHub-Api-Version': '2022-11-28' # API versiyonunu belirtmek iyi bir pratiktir
    }

    try:
        response = requests.get(api_url, headers=headers, timeout=20)
        response.raise_for_status()
        gists = response.json()
        
        now_utc = datetime.now(timezone.utc)
        deleted_count = 0

        for gist in gists:
            description = gist.get('description', '')
            # Sadece bizim projemize ait Gist'leri kontrol et
            if description and description.startswith(prefix):
                created_at_str = gist['created_at']
                # 'Z' harfini UTC timezone bilgisine çevirerek tarih nesnesi oluştur
                created_at = datetime.fromisoformat(created_at_str.replace('Z', '+00:00'))
                
                # Son kullanma tarihini hesapla (oluşturulma + 48 saat)
                expires_at = created_at + timedelta(hours=48)
                
                if now_utc > expires_at:
                    gist_id = gist['id']
                    delete_url = f"https://api.github.com/gists/{gist_id}"
                    
                    print(f"--> Süresi dolan Gist bulundu (ID: {gist_id}). Siliniyor...")
                    delete_response = requests.delete(delete_url, headers=headers, timeout=10)
                    
                    if delete_response.status_code == 204:
                        print(f"--> Gist (ID: {gist_id}) başarıyla silindi.")
                        deleted_count += 1
                    else:
                        print(f"[TEMİZLİK HATASI] Gist (ID: {gist_id}) silinemedi. Durum Kodu: {delete_response.status_code}, Yanıt: {delete_response.text}")
        
        if deleted_count > 0:
            print(f"-> Temizlik tamamlandı. Toplam {deleted_count} adet süresi dolmuş Gist silindi.")
        else:
            print("-> Süresi dolmuş Gist bulunamadı. Temizlik tamamlandı.")

    except requests.RequestException as e:
        print(f"[TEMİZLİK HATASI] GitHub API'sine bağlanılamadı: {e}")
# -----------------------------------------------------------------

def run_bot(user_id=None, target_group="TURKISH"):
    # --- ANA GÖREVE BAŞLAMADAN ÖNCE TEMİZLİĞİ ÇALIŞTIR ---
    cleanup_expired_gists()
    # ---------------------------------------------------

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
    
    m3u_content = "#EXTM3U\n"
    for ch in result_data['channels']:
        m3u_content += f'#EXTINF:-1 group-title="{ch["group"]}",{ch["name"]}\n{ch["url"]}\n'

    timestamp = datetime.now().strftime("%Y-%m-%d %H:%M")
    gist_filename = f"playlist_{target_group}_{timestamp}.m3u"
    
    user_tag = f"(User: {user_id})" if user_id else ""
    gist_description = f"Filtrelenmiş Playlist ({target_group}) - {timestamp} {user_tag}"
    
    print(f"-> Playlist GitHub Gist'e yükleniyor... Açıklama: '{gist_description}'")
    public_url, error = upload_to_gist(gist_filename, m3u_content, gist_description)
    
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

# --- VERCEL HANDLER (Bu bölüm değişmedi) ---
class handler(BaseHTTPRequestHandler):
    def do_GET(self):
        parsed_path = urlparse(self.path)
        query_params = parse_qs(parsed_path.query)

        user_id = query_params.get('user_id', [None])[0]
        target_group = query_params.get('group', ['TURKISH'])[0]

        self.send_response(200)
        self.send_header('Content-type', 'application/json')
        self.end_headers()
        response = {'status': 'success', 'message': 'Bot task is running in the background.'}
        self.wfile.write(json.dumps(response).encode('utf-8'))
        
        run_bot(user_id=user_id, target_group=target_group)
        return
