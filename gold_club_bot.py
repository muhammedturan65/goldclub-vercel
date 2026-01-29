# gold_club_bot.py (Browserless Function API Sürümü)
import time
import traceback
import re
import requests
import json
import os

class GoldClubBot:
    def __init__(self, email, password, socketio=None, sid=None, target_group=None):
        self.email = email
        self.password = password
        self.socketio = socketio
        self.sid = sid
        self.target_group = target_group
        self.base_url = "https://goldclubhosting.xyz/"
        
        # Browserless Token
        self.browserless_token = os.environ.get('BROWSERLESS_TOKEN')
        if not self.browserless_token:
            raise ValueError("[KRİTİK HATA] BROWSERLESS_TOKEN ortam değişkeni bulunamadı!")

    def _report_status(self, message):
        print(f"SID {self.sid}: {message}")
        if self.socketio and self.sid:
            self.socketio.emit('status_update', {'message': message}, to=self.sid)

    def _get_puppeteer_script(self):
        """
        Browserless üzerinde çalışacak Puppeteer (Node.js) kodunu string olarak döndürür.
        Python f-string formatı ile email ve şifre enjekte edilir.
        """
        js_code = """
        export default async function({ page }) {
            const email = "%s";
            const password = "%s";
            const baseUrl = "https://goldclubhosting.xyz/";
            
            let lastStep = "Başlangıç";

            try {
                // Konfigürasyon: Tüm beklemeler için varsayılan süreyi artır
                page.setDefaultTimeout(60000); // 60 saniye
                page.setDefaultNavigationTimeout(60000);

                // 1. LOGIN
                lastStep = "Login Sayfasına Gitme";
                await page.goto(baseUrl + "index.php?rp=/login", { waitUntil: 'networkidle2', timeout: 60000 });
                
                lastStep = "Login Bilgilerini Girme";
                await page.waitForSelector("#inputEmail");
                await page.type("#inputEmail", email);
                await page.type("#inputPassword", password);
                
                lastStep = "Login Butonuna Tıklama ve Bekleme";
                await Promise.all([
                    page.waitForNavigation({ waitUntil: 'networkidle2' }),
                    page.click("#login")
                ]);

                // 2. ORDER FREE TRIAL
                lastStep = "Ücretsiz Deneme Sayfasına Gitme";
                await page.goto(baseUrl + "index.php?rp=/store/free-trial", { waitUntil: 'networkidle2', timeout: 60000 });
                
                lastStep = "Sipariş Butonunu Bulma";
                // Sipariş butonu genelde product7-order-button ama değişebilir
                try {
                    await page.waitForSelector("#product7-order-button", { timeout: 15000 });
                    await page.click("#product7-order-button");
                } catch (e) {
                     // Eğer ID ile bulunamazsa, metin içeren bir buton deneyelim
                     const buttons = await page.$$('button, a.btn');
                     let clicked = false;
                     for (const btn of buttons) {
                         const text = await page.evaluate(el => el.textContent, btn);
                         if (text.includes('Order Now') || text.includes('Sipariş Ver')) {
                             await btn.click();
                             clicked = true;
                             break;
                         }
                     }
                     if (!clicked) throw new Error("Sipariş butonu bulunamadı (ID veya Text ile).");
                }

                lastStep = "Checkout Sayfası İşlemleri";
                await page.waitForSelector("#checkout");
                await page.click("#checkout");

                // Şartları kabul et (XPath to JS)
                lastStep = "Sözleşme Kabul Etme";
                await page.evaluate(() => {
                    const labels = Array.from(document.querySelectorAll('label'));
                    const target = labels.find(l => l.textContent.includes('I have read and agree'));
                    if(target) target.click();
                });
                
                lastStep = "Siparişi Tamamlama";
                await Promise.all([
                    page.waitForNavigation({ waitUntil: 'networkidle2' }),
                    page.click("#btnCompleteOrder")
                ]);

                // 3. PRODUCT DETAILS (M3U Linkini alma)
                lastStep = "Hizmetler Sayfasına Gitme";
                await page.goto(baseUrl + "clientarea.php?action=services", { waitUntil: 'networkidle2', timeout: 60000 });
                
                lastStep = "Servis Detayına Tıklama";
                // Sayfadaki ilk 'View Details' veya 'Active' butonunu bulmaya çalışalım
                await page.waitForFunction(() => {
                    return Array.from(document.querySelectorAll('button, a.btn'))
                        .some(b => b.textContent.includes('View Details') || b.textContent.includes('Yönet')  || b.textContent.includes('Active'));
                }, { timeout: 30000 });

                await page.evaluate(() => {
                    const els = Array.from(document.querySelectorAll('button, a.btn, td.text-center a')); 
                    // Genişletilmiş seçici: Tablo içindeki linkleri de kontrol et
                    const target = els.find(b => b.textContent.includes('View Details') || b.textContent.includes('Yönet') || b.textContent.includes('Active'));
                    if(target) target.click();
                    else throw new Error("Detay butonu DOM içinde bulunamadı.");
                });

                lastStep = "M3U Link Elementini Bekleme (#m3ulinks)";
                // Detay sayfası yüklendiğinde #m3ulinks elementini bekle (En çok hata burası olabilir, süreyi artırdık)
                await page.waitForSelector("#m3ulinks", { timeout: 60000 });
                
                const m3uUrl = await page.$eval("#m3ulinks", el => el.value);
                
                lastStep = "Son Kullanma Tarihi Alma";
                const expiryDate = await page.evaluate(() => {
                    const divs = Array.from(document.querySelectorAll('div'));
                    const targetDiv = divs.find(d => d.textContent.includes('Expiry Date:') || d.textContent.includes('Son Kullanma'));
                    if(targetDiv) {
                        const strong = targetDiv.querySelector('strong');
                        return strong ? strong.textContent.trim() : "Bulunamadı";
                    }
                    return "Bulunamadı";
                });

                return {
                    data: {
                        url: m3uUrl,
                        expiry: expiryDate
                    },
                    type: "application/json"
                };

            } catch (error) {
                return {
                    data: {
                        error: error.message,
                        step: lastStep,
                        stack: error.stack
                    },
                    type: "application/json"
                };
            }
        }
        """ % (self.email, self.password)
        return js_code

    def _parse_playlist(self, m3u_url):
        self._report_status(f"-> M3U playlist içeriği indiriliyor ve '{self.target_group or 'Tümü'}' grubuna göre filtreleniyor...")
        try:
            response = requests.get(m3u_url, timeout=30)
            response.raise_for_status()
            content = response.text
            
            # Basit regex ile parse etme
            pattern = re.compile(r'#EXTINF:-1.*?group-title="(.*?)".*?,(.*?)\n(https?://.*)')
            matches = pattern.findall(content)
            
            channels = []
            for group, name, url in matches:
                if not self.target_group or self.target_group.lower() in group.lower():
                    channels.append({
                        "name": name.strip(),
                        "group": group.strip(),
                        "url": url.strip()
                    })

            self._report_status(f"-> Analiz tamamlandı: {len(channels)} adet uygun kanal bulundu.")
            if not channels:
                self._report_status(f"[UYARI] '{self.target_group}' grubunda hiç kanal bulunamadı.")
            return channels
        except Exception as e:
            self._report_status(f"[HATA] Playlist indirilemedi: {e}")
            return None

    def run_full_process(self):
        self._report_status("-> Browserless Function API başlatılıyor...")
        
        js_code = self._get_puppeteer_script()
        
        # Endpoint: Kullanıcının verdiği örneklerdeki veya genel endpoint
        # Eğer özel bir endpoint varsa ortam değişkeninden de alınabilir ama şimdilik standart olanı deniyoruz.
        url = f"https://production-sfo.browserless.io/function?token={self.browserless_token}"
        
        headers = {
            'Content-Type': 'application/javascript'
        }
        
        try:
            self._report_status("-> Uzak tarayıcıda işlemler yapılıyor (Login, Sipariş, Veri Çekme)... Bu işlem 30-60sn sürebilir.")
            
            # Timeout'u yüksek tutuyoruz çünkü tüm browser işlemi tek request'te dönecek
            response = requests.post(url, headers=headers, data=js_code, timeout=90)
            
            if response.status_code != 200:
                raise Exception(f"Browserless API Hatası: {response.status_code} - {response.text}")
            
            result = response.json()
            
            # Browserless'dan gelen yanıt yapısını kontrol et
            # Beklenen: { "data": { "url": "...", "expiry": "...", "error": "..." }, "type": "application/json" }
            
            data = result.get('data', {})
            
            if 'error' in data:
                error_step = data.get('step', 'Bilinmiyor')
                raise Exception(f"Browser İçi Hata ({error_step}): {data['error']}")
            
            m3u_link = data.get('url')
            expiry_date = data.get('expiry')
            
            if not m3u_link:
                raise Exception("M3U Linki alınamadı, yanıt boş.")
            
            self._report_status(f"-> Veriler alındı. Link: {m3u_link[:30]}... Son Kullanma: {expiry_date}")
            
            parsed_channels = self._parse_playlist(m3u_link)
            
            return {
                "url": m3u_link,
                "expiry": expiry_date,
                "channels": parsed_channels
            }

        except Exception as e:
            error_message = f"[KRİTİK HATA] {e}"
            self._report_status(error_message)
            traceback.print_exc()
            if self.socketio and self.sid:
                self.socketio.emit('process_error', {'error': str(e)}, to=self.sid)
            return None
