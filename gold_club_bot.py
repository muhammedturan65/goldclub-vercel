# gold_club_bot.py (Browserless.io Entegre Edilmiş Nihai Sürüm)
import time
import traceback
import re
import requests
import os  # Ortam değişkenlerini okumak için gerekli
from selenium import webdriver
from selenium.webdriver.common.by import By
from selenium.webdriver.support.ui import WebDriverWait
from selenium.webdriver.support import expected_conditions as EC
from selenium.common.exceptions import TimeoutException, WebDriverException

class GoldClubBot:
    def __init__(self, email, password, socketio=None, sid=None, target_group=None):
        self.email = email
        self.password = password
        self.socketio = socketio
        self.sid = sid
        self.target_group = target_group
        self.driver = None
        self.wait = None
        self.base_url = "https://goldclubhosting.xyz/"

    def _report_status(self, message):
        # Bu fonksiyon loglamayı yapar, Vercel loglarında bu mesajları göreceğiz.
        print(f"SID {self.sid}: {message}")
        if self.socketio and self.sid:
            self.socketio.emit('status_update', {'message': message}, to=self.sid)

    def _setup_driver(self):
        """
        Yerel WebDriver yerine uzaktaki Browserless.io tarayıcısına bağlanır.
        Bu yöntem, Vercel'in "read-only file system" ve tarayıcı eksikliği kısıtlamalarını aşar.
        """
        self._report_status("-> Uzak tarayıcıya (Browserless.io) bağlanılıyor...")

        # Vercel ortam değişkenlerinden API anahtarını al
        api_key = os.environ.get('BROWSERLESS_API_KEY')
        if not api_key:
            error_message = "[KRİTİK HATA] BROWSERLESS_API_KEY ortam değişkeni bulunamadı!"
            self._report_status(error_message)
            raise ValueError(error_message)

        try:
            options = webdriver.ChromeOptions()
            # Sunucusuz ortamlar için gerekli olan standart argümanlar
            options.add_argument('--headless')
            options.add_argument('--no-sandbox')
            options.add_argument('--disable-dev-shm-usage')

            # Browserless.io'ya bağlanmak için webdriver.Remote ve GÜNCEL wss:// adresini kullan
            self.driver = webdriver.Remote(
                command_executor=f"wss://chrome.browserless.io/webdriver?token={api_key}",
                options=options
            )
            # Uzak bağlantılarda ağ gecikmesi olabileceğinden bekleme süresini 25 saniye yapmak iyi bir pratiktir
            self.wait = WebDriverWait(self.driver, 25)
            self._report_status("-> Uzak tarayıcıya başarıyla bağlanıldı.")

        except WebDriverException as e:
            self._report_status(f"[HATA] Uzak tarayıcıya bağlanılamadı: {e.msg}")
            raise
        except Exception as e:
            self._report_status(f"[BEKLENMEDİK HATA] WebDriver kurulumunda hata: {e}")
            raise

    # --- DİĞER TÜM BOT FONKSİYONLARI DEĞİŞMEDEN AYNI KALIYOR ---

    def _find_element_with_retry(self, by, value, retries=3, delay=5):
        for i in range(retries):
            try:
                return self.wait.until(EC.visibility_of_element_located((by, value)))
            except TimeoutException:
                if i < retries - 1:
                    self._report_status(f"-> Element '{value}' bulunamadı. {delay} sn sonra tekrar deneniyor...")
                    time.sleep(delay)
                else:
                    raise

    def _click_element_with_retry(self, by, value, retries=3, delay=5):
        for i in range(retries):
            try:
                element = self.wait.until(EC.element_to_be_clickable((by, value)))
                element.click()
                return
            except TimeoutException:
                if i < retries - 1:
                    self._report_status(f"-> Tıklanabilir element '{value}' bulunamadı. {delay} sn sonra tekrar deneniyor...")
                    time.sleep(delay)
                else:
                    raise

    def _parse_playlist(self, m3u_url):
        self._report_status(f"-> M3U playlist içeriği indiriliyor ve '{self.target_group or 'Tümü'}' grubuna göre filtreleniyor...")
        try:
            response = requests.get(m3u_url, timeout=20)
            response.raise_for_status()
            content = response.text
            channels = [{"name": name.strip(), "group": group.strip(), "url": url.strip()} for group, name, url in re.findall(r'#EXTINF:-1.*?group-title="(.*?)".*?,(.*?)\n(https?://.*)', content) if not self.target_group or self.target_group.lower() in group.lower()]
            self._report_status(f"-> Analiz tamamlandı: {len(channels)} adet uygun kanal bulundu.")
            if not channels:
                self._report_status(f"[UYARI] '{self.target_group}' grubunda hiç kanal bulunamadı.")
            return channels
        except requests.RequestException as e:
            self._report_status(f"[HATA] Playlist indirilemedi: {e}")
            return None

    def _login(self):
        self._report_status("-> Giriş yapılıyor...")
        self.driver.get(f"{self.base_url}index.php?rp=/login")
        self._find_element_with_retry(By.ID, "inputEmail").send_keys(self.email)
        self._find_element_with_retry(By.ID, "inputPassword").send_keys(self.password)
        self._click_element_with_retry(By.ID, "login")
        self.wait.until(EC.url_contains("clientarea.php"))

    def _order_free_trial(self):
        self._report_status("-> Ücretsiz deneme sipariş ediliyor...")
        self.driver.get(f"{self.base_url}index.php?rp=/store/free-trial")
        self._click_element_with_retry(By.ID, "product7-order-button")
        self._click_element_with_retry(By.ID, "checkout")
        self._click_element_with_retry(By.XPATH, "//label[contains(., 'I have read and agree to the')]")
        self._click_element_with_retry(By.ID, "btnCompleteOrder")
        self.wait.until(EC.url_contains("cart.php?a=complete"))

    def _navigate_to_product_details(self):
        self._report_status("-> Ürün detayları sayfasına gidiliyor...")
        self._click_element_with_retry(By.PARTIAL_LINK_TEXT, "Continue To Client Area")
        view_details_button = self._find_element_with_retry(By.XPATH, "(//button[contains(., 'View Details')])[1]")
        view_details_button.click()

    def _extract_data(self):
        self._report_status("-> Temel veriler çekiliyor...")
        m3u_input = self._find_element_with_retry(By.ID, "m3ulinks")
        m3u_link = m3u_input.get_attribute("value")
        expiry_date_element = self._find_element_with_retry(By.XPATH, "//div[contains(., 'Expiry Date:')]/strong")
        expiry_date = expiry_date_element.text.strip()
        if not (m3u_link and expiry_date):
            raise Exception("M3U linki veya son kullanma tarihi alınamadı.")
        return {"url": m3u_link, "expiry": expiry_date, "channels": self._parse_playlist(m3u_link)}

    def _cleanup(self):
        if self.driver:
            self.driver.quit()
            self._report_status("-> Tarayıcı kapatıldı.")

    def run_full_process(self):
        try:
            self._setup_driver()
            self._login()
            self._order_free_trial()
            self._navigate_to_product_details()
            return self._extract_data()
        except Exception as e:
            error_message = f"[KRİTİK HATA] {e}"
            self._report_status(error_message)
            traceback.print_exc()
            if self.socketio and self.sid:
                self.socketio.emit('process_error', {'error': str(e)}, to=self.sid)
            return None
        finally:
            self._cleanup()
