# run_bot_task.py ("Callback" Entegre Edilmiş Nihai Sürüm)
import os
import sys
import requests
from datetime import datetime
from gold_club_bot import GoldClubBot
from app import get_db_connection, send_email_notification # get_db_connection'ı sadece loglama için tutuyoruz
import json
from http.server import BaseHTTPRequestHandler
from urllib.parse import urlparse, parse_qs

def upload_or_update_gist(filename, content, description, gist_id=None):
    """
    Verilen içeriği GitHub Gist'e yükler veya günceller.
    """
    try:
        token = os.environ['GITHUB_TOKEN']
    except KeyError:
        return None, None, "GitHub Token ortam değişkeni bulunamadı."

    headers = {
        'Authorization': f'Bearer {token}',
        'Accept': 'application/vnd.github.v3+json',
        'X-GitHub-Api-Version': '2022-11-28'
    }
    
    payload = {
        'description': description,
        'files': {
            filename: {
                'content': content
            }
        }
    }

    try:
        if gist_id:
            print(f"--> Mevcut Gist (ID: {gist_id}) güncelleniyor...")
            api_url = f"https://api.github.com/gists/{gist_id}"
            response = requests.patch(api_url, headers=headers, json=payload, timeout=30)
        else:
            print("--> Yeni bir Gist oluşturuluyor...")
            api_url = "https://api.github.com/gists"
            payload['public'] = False
            response = requests.post(api_url, headers=headers, json=payload, timeout=30)
        
        response.raise_for_status()
        data = response.json()
        
        new_gist_id = data.get('id')
        file_key = list(data.get('files', {}).keys())[0]
        raw_url = data.get('files', {}).get(file_key, {}).get('raw_url')

        if not new_gist_id or not raw_url:
            raise KeyError("API yanıtında 'id' veya 'raw_url' bulunamadı.")

        return new_gist_id, raw_url, None

    except requests.exceptions.RequestException as e:
        error_message = f"GitHub API'sine bağlanılamadı: {e}"
        print(error_message)
        return None, None, error_message
    except (KeyError, IndexError) as e:
        error_message = f"GitHub API'sinden beklenmedik yanıt: {response.text} (Hata: {e})"
        print(error_message)
        return None, None, error_message

def run_bot(user_id=None, target_group="TURKISH", gist_id=None):
    if user_id:
        print(f"İstek kullanıcı ID'si {user_id} için başlatıldı. Gist ID: {gist_id or 'Yeni Oluşturulacak'}")
    
    try:
        email = os.environ['APP_EMAIL']
        password = os.environ['APP_PASSWORD']
    except KeyError as e:
        print(f"HATA: Gerekli ortam değişkeni bulunamadı: {e}")
        return

    bot = GoldClubBot(email=email, password=password, target_group=target_group)
    result_data = bot.run_full_process()

    if not result_data or not result_data.get('channels'):
        print("Bot işlemi başarısız oldu veya kanal bulunamadı.")
        # Başarısızlığı logla (bu kısım Vercel Postgres'e loglar, ana MySQL'e değil)
        try:
            conn = get_db_connection()
            if conn and user_id:
                stmt_log = conn.cursor()
                stmt_log.execute("INSERT INTO activity_logs (user_id, username, action, status, details) VALUES (%s, %s, %s, %s, %s)",
                                 (user_id, f"User {user_id}", 'Playlist Üretimi', 'Başarısız', 'Bot işlemi kanal bulamadı veya hata verdi.'))
                conn.commit()
                conn.close()
        except Exception as log_e:
            print(f"Vercel Postgres loglama hatası: {log_e}")
        return

    m3u_content = "#EXTM3U\n"
    for ch in result_data['channels']:
        m3u_content += f'#EXTINF:-1 group-title="{ch["group"]}",{ch["name"]}\n{ch["url"]}\n'
    
    gist_filename = f"playlist_{user_id or 'shared'}.m3u"
    user_tag = f"(User: {user_id})" if user_id else ""
    gist_description = f"Filtrelenmiş Playlist ({target_group}) {user_tag}"

    new_gist_id, new_raw_url, error = upload_or_update_gist(gist_filename, m3u_content, gist_description, gist_id)

    if new_gist_id and new_raw_url:
        print(f"-> Gist işlemi başarılı. ID: {new_gist_id}, URL: {new_raw_url}")
        
        # --- YENİ BÖLÜM: Veritabanını güncellemek için PHP'ye haber ver ---
        try:
            callback_url = os.environ['CALLBACK_URL']
            secret = os.environ['CALLBACK_SECRET']
            
            params = {
                'secret': secret,
                'user_id': user_id,
                'gist_id': new_gist_id,
                'raw_url': new_raw_url
            }
            
            print(f"--> Veritabanı güncellemesi için callback isteği gönderiliyor: {callback_url}")
            callback_response = requests.get(callback_url, params=params, timeout=15)
            callback_response.raise_for_status() # HTTP hatası varsa exception fırlat
            print(f"--> Callback isteği başarıyla gönderildi. Yanıt: {callback_response.text}")

        except KeyError:
            print("[HATA] CALLBACK_URL veya CALLBACK_SECRET ortam değişkenleri ayarlanmamış.")
        except requests.RequestException as e:
            print(f"[CALLBACK HATASI] Callback isteği gönderilemedi: {e}")
        # --------------------------------------------------------------------
        
        # E-posta gönderme mantığı
        email_subject = f"Yeni Playlist Oluşturuldu ({target_group})"
        email_body = (f"Yeni bir M3U playlist'i başarıyla oluşturuldu/güncellendi.\n\n"
                      f"Kullanıcı ID: {user_id or 'Belirtilmedi'}\n"
                      f"Kalıcı Linkiniz: {new_raw_url}\n"
                      f"Kanal Sayısı: {len(result_data['channels'])}\n"
                      f"Son Kullanma Tarihi: {result_data['expiry']}")
        send_email_notification(email_subject, email_body)
        print("-> E-posta bildirimi gönderildi.")

    else:
        print(f"-> Gist yüklemesi/güncellemesi başarısız oldu: {error}")
        # Başarısızlığı logla
        try:
            conn = get_db_connection()
            if conn and user_id:
                stmt_log = conn.cursor()
                stmt_log.execute("INSERT INTO activity_logs (user_id, username, action, status, details) VALUES (%s, %s, %s, %s, %s)",
                                 (user_id, f"User {user_id}", 'Playlist Üretimi', 'Başarısız', f'Gist hatası: {error}'))
                conn.commit()
                conn.close()
        except Exception as log_e:
            print(f"Vercel Postgres loglama hatası: {log_e}")

# --- VERCEL HANDLER (Gist ID'yi okuyacak şekilde güncellendi) ---
class handler(BaseHTTPRequestHandler):
    def do_GET(self):
        parsed_path = urlparse(self.path)
        query_params = parse_qs(parsed_path.query)

        user_id = query_params.get('user_id', [None])[0]
        target_group = query_params.get('group', ['TURKISH'])[0]
        gist_id = query_params.get('gist_id', [None])[0]

        self.send_response(200)
        self.send_header('Content-type', 'application/json')
        self.end_headers()
        response = {'status': 'success', 'message': 'Bot task is running in the background.'}
        self.wfile.write(json.dumps(response).encode('utf-8'))
        
        run_bot(user_id=user_id, target_group=target_group, gist_id=gist_id)
        return
