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
        # JavaScript kodu içinde f-string çakışmasını önlemek için süslü parantezlere dikkat edilmeli.
        # Bu yüzden JS kodunu raw string veya dikkatli bir şekilde oluşturuyoruz.
        
        js_code = """
        export default async function({ page }) {
            const email = "%s";
            const password = "%s";
            const baseUrl = "https://goldclubhosting.xyz/";

            const log = (msg) => console.log(msg);

            try {
                // 1. LOGIN
                await page.goto(baseUrl + "index.php?rp=/login", { timeout: 60000 });
                await page.waitForSelector("#inputEmail");
                await page.type("#inputEmail", email);
                await page.type("#inputPassword", password);
                
                await Promise.all([
                    page.waitForNavigation(),
                    page.click("#login")
                ]);

                // 2. ORDER FREE TRIAL
                await page.goto(baseUrl + "index.php?rp=/store/free-trial", { timeout: 60000 });
                
                // Sipariş butonu genelde product7-order-button ama değişebilir, ID ile deniyoruz
                try {
                    await page.waitForSelector("#product7-order-button", { timeout: 10000 });
                    await page.click("#product7-order-button");
                } catch (e) {
                    // Alternatif seçiciler gerekirse eklenebilir
                    throw new Error("Sipariş butonu bulunamadı.");
                }

                await page.waitForSelector("#checkout");
                await page.click("#checkout");

                // Şartları kabul et (XPath to JS)
                await page.evaluate(() => {
                    const labels = Array.from(document.querySelectorAll('label'));
                    const target = labels.find(l => l.textContent.includes('I have read and agree'));
                    if(target) target.click();
                });
                
                // Siparişi tamamla
                await Promise.all([
                    page.waitForNavigation(),
                    page.click("#btnCompleteOrder")
                ]);

                // 3. PRODUCT DETAILS (M3U Linkini alma)
                // Doğrudan hizmetler sayfasına gidip oradan detaya girelim
                await page.goto(baseUrl + "clientarea.php?action=services", { timeout: 60000 });
                
                // Listeden 'Active' olan ilk servisin detayına veya View Details butonuna tıkla
                // Basitçe sayfadaki ilk 'View Details' butonunu bulalım
                await page.waitForFunction(() => {
                    return Array.from(document.querySelectorAll('button'))
                        .some(b => b.textContent.includes('View Details') || b.textContent.includes('Yönet'));
                });

                await page.evaluate(() => {
                    const btns = Array.from(document.querySelectorAll('button', 'a.btn')); // buton veya link olabilir
                    const target = btns.find(b => b.textContent.includes('View Details') || b.textContent.includes('Yönet'));
                    if(target) target.click();
                });

                // Detay sayfası yüklendiğinde #m3ulinks elementini bekle
                await page.waitForSelector("#m3ulinks", { timeout: 30000 });
                
                const m3uUrl = await page.$eval("#m3ulinks", el => el.value);
                
                // Son kullanma tarihi
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
                // Hata durumunda ekran görüntüsü veya hata mesajı döndür
                return {
                    data: {
                        error: error.message,
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
                raise Exception(f"Browser İçi Hata: {data['error']}")
            
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
