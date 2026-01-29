# app.py (Vercel'e dağıtıma hazır temel sürüm)
import os, json, requests, sys, smtplib, ssl, psycopg2
from datetime import datetime
from email.message import EmailMessage
from flask import Flask, render_template_string, request, jsonify, Response
from psycopg2.extras import RealDictCursor

# --- 1. AYARLAR VE YAPILANDIRMA (ORTAM DEĞİŞKENLERİNDEN) ---
# Bu değerler Vercel projenizin "Environment Variables" bölümünden ayarlanacaktır.
try:
    # Uygulama ve Bot kimlik bilgileri
    APP_EMAIL = os.environ['APP_EMAIL']
    APP_PASSWORD = os.environ['APP_PASSWORD']
    
    # Browserless API Token
    BROWSERLESS_TOKEN = os.environ.get('BROWSERLESS_TOKEN')
    
    if not BROWSERLESS_TOKEN:
         print("UYARI: BROWSERLESS_TOKEN ayarlanmamış. Bot çalışmayabilir.")
    GITHUB_TOKEN = os.environ.get('GITHUB_TOKEN')

    # E-posta bildirim ayarları
    SMTP_SERVER = os.environ['SMTP_SERVER']
    SMTP_PORT = int(os.environ['SMTP_PORT'])
    SMTP_USER = os.environ['SMTP_USER']
    SMTP_PASSWORD = os.environ['SMTP_PASSWORD']
    RECIPIENT_EMAIL = os.environ['RECIPIENT_EMAIL']

    # Vercel Postgres veritabanı bağlantı adresi
    DATABASE_URL = os.environ['POSTGRES_URL']

except KeyError as e:
    print(f"HATA: Gerekli ortam değişkeni ayarlanmamış: {e}. Lütfen Vercel ayarlarınızı kontrol edin.")
    sys.exit(1)

# --- 2. VERİTABANI FONKSİYONLARI (POSTGRESQL İÇİN) ---

def get_db_connection():
    """PostgreSQL veritabanına yeni bir bağlantı oluşturur ve döndürür."""
    try:
        conn = psycopg2.connect(DATABASE_URL)
        return conn
    except psycopg2.OperationalError as e:
        print(f"Veritabanı bağlantı hatası: {e}")
        return None

def init_db():
    """Uygulama ilk çalıştığında veritabanı tablosunun mevcut olduğundan emin olur."""
    conn = get_db_connection()
    if conn is None:
        print("Veritabanı bağlantısı kurulamadığı için 'init_db' işlemi atlanıyor.")
        return
    
    # Vercel'in sunucusuz doğası gereği, bu fonksiyon her soğuk başlangıçta çalışabilir.
    # Bu yüzden "IF NOT EXISTS" kullanmak kritiktir.
    with conn.cursor() as cur:
        cur.execute("""
            CREATE TABLE IF NOT EXISTS generated_links (
                id SERIAL PRIMARY KEY,
                m3u_url TEXT,
                expiry_date TEXT,
                channel_count INTEGER,
                created_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP,
                status VARCHAR(20) DEFAULT 'completed',
                logs TEXT,
                playlist_data JSONB
            );
        """)
    conn.commit()
    conn.close()
    print("Veritabanı tablosu 'generated_links' kontrol edildi/oluşturuldu.")

# --- Flask Uygulaması ---
app = Flask(__name__)

# --- 3. HTML TEMPLATE'LER ---
# NOT: Bu template'lerdeki JavaScript kısımları, Socket.IO olmadan çalışacak şekilde
# güncellenmelidir. Bu kodda, temel yapı korunmuştur ancak gerçek zamanlı log akışı çalışmayacaktır.
HOME_TEMPLATE = """
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8"><title>Playlist Yönetim Paneli</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script src="https://cdn.jsdelivr.net/npm/feather-icons/dist/feather.min.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root { --bg-dark: #101014; --bg-card: rgba(30, 30, 35, 0.5); --border-color: rgba(255, 255, 255, 0.1); --text-primary: #f0f0f0; --text-secondary: #a0a0a0; --accent-grad: linear-gradient(90deg, #8A2387, #E94057, #F27121); --success-color: #1ed760; --error-color: #f44336; }
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Manrope', sans-serif; background: var(--bg-dark); color: var(--text-primary); font-size: 15px; overflow-x: hidden; }
        body::before { content: ''; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: radial-gradient(circle at 15% 25%, #8a238744, transparent 30%), radial-gradient(circle at 85% 75%, #f2712133, transparent 40%); z-index: -1; }
        .container { max-width: 1400px; margin: 3rem auto; padding: 0 2rem; }
        .shell { background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 16px; padding: 2rem; backdrop-filter: blur(20px); -webkit-backdrop-filter: blur(20px); box-shadow: 0 8px 32px rgba(0, 0, 0, 0.2); }
        h1, h2 { font-weight: 800; }
        .dashboard { display: grid; grid-template-columns: minmax(350px, 1fr) 2fr; gap: 2rem; align-items: flex-start; margin-top: 2rem; }
        .card-header { display: flex; align-items: center; gap: 0.75rem; margin-bottom: 1.5rem; color: var(--text-secondary); font-size: 1.1rem; border-bottom: 1px solid var(--border-color); padding-bottom: 1rem; }
        label { display: block; margin-bottom: 0.5rem; font-weight: 500; color: var(--text-secondary); }
        input[type="text"] { width: 100%; padding: 0.8rem 1rem; background-color: rgba(0,0,0,0.2); border: 1px solid var(--border-color); border-radius: 8px; color: var(--text-primary); font-size: 1rem; transition: all 0.2s; }
        input[type="text"]:focus { border-color: #E94057; box-shadow: 0 0 0 3px #e9405733; outline: none; }
        .btn { display: flex; align-items: center; justify-content: center; gap: 0.75rem; width: 100%; padding: 0.9rem; background: var(--accent-grad); color: white; border: none; border-radius: 8px; font-size: 1.1rem; cursor: pointer; transition: all 0.2s; font-weight: 700; margin-top: 1.5rem; }
        .btn:hover:not(:disabled) { transform: translateY(-3px); box-shadow: 0 4px 20px rgba(233, 64, 87, 0.3); }
        .btn:disabled { background: #333; cursor: not-allowed; }
        .btn .spinner { animation: spin 1s linear infinite; }
        #log-container { margin-top: 1rem; background-color: rgba(0,0,0,0.3); padding: 1rem; border-radius: 8px; height: 350px; overflow-y: auto; font-family: 'Fira Code', monospace; font-size: 0.85rem; }
        .history-table { width: 100%; border-collapse: collapse; }
        .history-table th, .history-table td { padding: 1rem; border-bottom: 1px solid var(--border-color); text-align: left; vertical-align: middle; }
        .history-table th { font-weight: 600; color: var(--text-secondary); }
        .btn-details { background: var(--success-color); color: white; padding: 0.4rem 1rem; border-radius: 20px; text-decoration: none; font-size: 0.9rem; font-weight: 500; }
    </style>
</head>
<body>
    <div class="container">
        <h1 style="text-align: center;">Playlist Yönetim Paneli</h1>
        <div class="dashboard shell">
            <div>
                <div class="card-header"><i data-feather="sliders"></i><span>Kontrol Merkezi</span></div>
                <form id="control-form">
                    <label for="target_group">Filtrelenecek Kanal Grubu</label>
                    <input type="text" id="target_group" value="TURKISH">
                    <button type="submit" id="start-btn" class="btn"><i data-feather="play-circle"></i><span>Link Üret ve Analiz Et</span></button>
                </form>
                <h3 style="margin-top:2rem;color:var(--text-secondary);">İşlem Durumu</h3>
                <div id="log-container">İşlem logları Vercel'in sunucusuz yapısı nedeniyle artık burada canlı olarak akmayacaktır. İşlem başlatıldığında arka planda çalışacak ve bittiğinde "Geçmiş İşlemler" tablosuna eklenecektir.</div>
            </div>
            <div>
                <div class="card-header"><i data-feather="clock"></i><span>Geçmiş İşlemler</span></div>
                <div style="max-height: 550px; overflow-y: auto;">
                    <table class="history-table">
                        <thead><tr><th>Üretim Zamanı</th><th>Son Kullanma</th><th>Kanal Sayısı</th><th>İşlem</th></tr></thead>
                        <tbody id="history-body"></tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    <script>
        feather.replace();
        const startBtn = document.getElementById('start-btn');
        const logContainer = document.getElementById('log-container');
        const historyBody = document.getElementById('history-body');

        async function fetchHistory() {
            try {
                const res = await fetch('/api/get_history');
                const history = await res.json();
                historyBody.innerHTML = '';
                history.forEach(item => {
                    const createdAt = new Date(item.created_at).toLocaleString('tr-TR');
                    historyBody.innerHTML += `<tr><td>${createdAt}</td><td>${item.expiry_date || 'N/A'}</td><td>${item.channel_count || 'N/A'}</td><td><a href="/playlist/${item.id}" class="btn-details">Detaylar</a></td></tr>`;
                });
            } catch (e) { console.error("Geçmiş alınamadı:", e); }
        }

        document.getElementById('control-form').addEventListener('submit', async (e) => {
            e.preventDefault();
            startBtn.disabled = true;
            startBtn.innerHTML = '<i data-feather="loader" class="spinner"></i><span>İşlem Başlatılıyor...</span>';
            feather.replace();
            
            const targetGroup = document.getElementById('target_group').value.trim();
            logContainer.innerHTML = `İşlem arka planda başlatıldı. Sayfayı yenileyerek veya bir süre sonra geçmişi kontrol ederek sonucu görebilirsiniz.<br>Filtre: <strong>${targetGroup}</strong>`;

            try {
                // Bu endpoint, Vercel Cron Job'u veya başka bir servisi tetiklemelidir.
                // Şimdilik sadece bir istek gönderip arka plan işleminin başladığını varsayıyoruz.
                const response = await fetch('/api/start-process', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ target_group: targetGroup })
                });
                const result = await response.json();
                
                if (response.ok) {
                     logContainer.innerHTML += `<br>Başarı: ${result.message}`;
                } else {
                    throw new Error(result.error || 'Bilinmeyen bir hata oluştu.');
                }

            } catch (error) {
                logContainer.innerHTML = `<div style="color: var(--error-color);">HATA: ${error.message}</div>`;
            } finally {
                startBtn.disabled = false;
                startBtn.innerHTML = '<i data-feather="play-circle"></i><span>Yeni İşlem Başlat</span>';
                feather.replace();
                // İşlem bittikten bir süre sonra geçmişi yenile
                setTimeout(fetchHistory, 5000); 
            }
        });

        document.addEventListener('DOMContentLoaded', () => {
            fetchHistory();
            feather.replace();
        });
    </script>
</body>
</html>
"""
# PLAYLIST_DETAILS_HTML'nin de Socket.IO bağımlılıklarından arındırılması gerekir.
# Bu örnekte bu sayfanın şablonu atlanmıştır, ancak mantık benzerdir.

# --- 4. YARDIMCI FONKSİYONLAR ---

def upload_to_gist(filename, content, description):
    """Verilen içeriği GitHub'a gizli bir Gist olarak yükler."""
    if not GITHUB_TOKEN:
        return None, "GitHub token'ı ortam değişkenlerinde bulunamadı."

    headers = {'Authorization': f'Bearer {GITHUB_TOKEN}', 'Accept': 'application/vnd.github.v3+json'}
    payload = {'description': description, 'public': False, 'files': {filename: {'content': content}}}

    try:
        response = requests.post('https://api.github.com/gists', headers=headers, json=payload, timeout=30)
        response.raise_for_status()
        data = response.json()
        return data['files'][filename]['raw_url'], None
    except requests.exceptions.RequestException as e:
        return None, f"GitHub API hatası: {e}"
    except KeyError:
        return None, f"GitHub API'sinden beklenmedik yanıt: {response.text}"

def send_email_notification(subject, body):
    """Belirtilen alıcıya e-posta gönderir."""
    msg = EmailMessage()
    msg.set_content(body)
    msg['Subject'] = subject
    msg['From'] = SMTP_USER
    msg['To'] = RECIPIENT_EMAIL
    try:
        context = ssl.create_default_context()
        with smtplib.SMTP(SMTP_SERVER, SMTP_PORT) as server:
            server.starttls(context=context)
            server.login(SMTP_USER, SMTP_PASSWORD)
            server.send_message(msg)
        return True
    except Exception as e:
        print(f"E-posta Gönderme Hatası: {e}")
        return False

# --- 5. FLASK YOLLARI (API ENDPOINTS) ---

@app.route('/')
def index():
    return render_template_string(HOME_TEMPLATE)

@app.route('/playlist/<int:link_id>')
def playlist_details(link_id):
    conn = get_db_connection()
    if not conn: return "Veritabanı bağlantı hatası.", 500
    
    with conn.cursor(cursor_factory=RealDictCursor) as cur:
        cur.execute("SELECT playlist_data FROM generated_links WHERE id = %s", (link_id,))
        record = cur.fetchone()
    conn.close()

    if not record or not record['playlist_data']:
        return "Playlist bulunamadı veya henüz işlenmemiş.", 404
        
    channels = record['playlist_data']
    # PLAYLIST_DETAILS_HTML'nin tam içeriği burada olmalı.
    # Bu şablonun da Socket.IO olmadan çalışacak şekilde güncellenmesi gerekir.
    return f"<h1>Playlist Detayları ({len(channels)} Kanal)</h1><pre>{json.dumps(channels, indent=2)}</pre>"


@app.route('/api/get_history')
def get_history():
    conn = get_db_connection()
    if not conn: return jsonify({"error": "Veritabanı bağlantı hatası"}), 500

    with conn.cursor(cursor_factory=RealDictCursor) as cur:
        cur.execute('SELECT id, created_at, expiry_date, channel_count FROM generated_links WHERE status = \'completed\' ORDER BY id DESC LIMIT 20')
        history = cur.fetchall()
    conn.close()
    
    return jsonify(history)

@app.route('/api/start-process', methods=['POST'])
def start_process():
    # BU FONKSİYON, UZUN SÜREN BOT İŞLEMİNİ DOĞRUDAN ÇALIŞTIRMAZ!
    # Vercel'de bir istek en fazla 10-60 saniye sürebilir. Selenium işlemi çok daha uzun sürer.
    # Bu endpoint, sadece bir "iş" kaydı oluşturur.
    # Asıl bot mantığı, bir Vercel Cron Job tarafından tetiklenen başka bir fonksiyonda
    # veya tamamen ayrı bir serviste çalışmalıdır.
    
    data = request.json
    target_group = data.get('target_group', 'all')

    # Bu noktada, bu isteği işleyecek bir arka plan mekanizmasını tetiklemeniz gerekir.
    # Örneğin:
    # 1. Veritabanına "pending" (beklemede) durumunda yeni bir iş kaydı ekleyebilirsiniz.
    # 2. Vercel Cron Job'unuz periyodik olarak çalışıp "pending" işleri arar.
    # 3. Bir iş bulduğunda, Selenium botunu çalıştırır ve sonuçları veritabanına yazar.

    print(f"Arka plan işlemi için istek alındı. Filtre: {target_group}")
    
    # Frontend'e işlemin başladığına dair bilgi verilir.
    return jsonify({"message": f"'{target_group}' grubu için link üretim işlemi arka planda başlatıldı. Sonuçlar bir süre sonra geçmişe yansıyacaktır."}), 202


@app.route('/generate_custom_playlist', methods=['POST'])
def generate_custom_playlist():
    data = request.json
    channels = data.get('channels', [])
    if not channels: return "Kanal seçilmedi.", 400
    
    content = "#EXTM3U\n"
    for ch in channels:
        content += f'#EXTINF:-1 group-title="{ch.get("group", "")}",{ch.get("name", "")}\n{ch.get("url", "")}\n'
    
    return Response(content, mimetype="audio/x-mpegurl", headers={"Content-disposition": "attachment; filename=custom_playlist.m3u"})


# --- UYGULAMA BAŞLATMA ---
# Vercel, 'app' adlı Flask nesnesini otomatik olarak bulur ve çalıştırır.
# Bu nedenle 'if __name__ == "__main__"' bloğuna gerek yoktur.
# init_db() fonksiyonu, uygulama her başlatıldığında (her soğuk başlangıçta)
# tablonun var olduğundan emin olmak için çağrılır.
init_db()