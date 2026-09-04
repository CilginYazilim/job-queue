# Değişiklik Günlüğü

Bu dosyanın biçimi [Keep a Changelog](https://keepachangelog.com/tr/1.1.0/) esas alınarak,
sürüm numaralandırması [Semantic Versioning](https://semver.org/lang/tr/) kuralına göre tutulur.

---

## [1.1.0] — 2026-09-04

### Eklendi

- **Veritabanı bilgileri artık `.env` dosyasından okunabiliyor.**
  Daha önce tek yol `system/config.php` dosyasını elle düzenlemekti — ve
  o dosya depoda durur: yazdığınız parola hem GitHub'a gider hem de ilk
  dağıtımda depodaki sürümle değiştirilerek kaybolur.

  Depo köküne `.env.example` eklendi; kopyalayıp `.env` yapmanız yeterli.
  `.env` zaten `.gitignore` içindeydi.

  Değer arama sırası: `.env` → sunucunun gerçek ortam değişkeni → bu
  dosyadaki varsayılan. (`config.local.php` destekleyen depolarda o hâlâ
  en önde gelir; eski kurulumlar olduğu gibi çalışır.)

  Uygulama kodu değişmedi: `cy_env()` yardımcısı bilerek `getenv()` ile
  aynı sözleşmeyi taşır (değer ya da `false`), böylece mevcut `?:` ve
  `!== false` kalıplarının hiçbirine dokunulmadı.

### Değiştirildi

- **Depo adı `PHP-MySQL-Job-Queue-Is-Kuyrugu-PDO-Skip-Locked-Worker`
  yerine `job-queue` oldu.** Uzun ad adres satırında okunmuyordu ve
  klasör adıyla eşleşmediği için vitrindeki bağlantılar kırılıyordu.
  Klon, ZIP, issue ve yerel kurulum adresleri buna göre güncellendi.
  GitHub eski adresi yenisine yönlendirir; eski bağlantılar kırılmaz.

- **Zaman dilimi artık açıkça sabitleniyor.** `system/config.php` içinde
  `APP_TIMEZONE` (varsayılan `Europe/Istanbul`) tanımlanıp
  `date_default_timezone_set()` çağrılıyor; ortam değişkeniyle
  değiştirilebilir.

  **Ölçülen sorun:** XAMPP'ın `php.ini` dosyasındaki `date.timezone`,
  MySQL'in kullandığı sistem diliminden farklı olabiliyor. Test
  makinesinde PHP `Europe/Berlin`, MySQL ise `Europe/Istanbul`
  kullanıyordu ve aynı anı anlatan iki satır bir saat farklı görünüyordu:

  ```
  worker günlüğü (PHP date)  : 14:03:17
  veritabanı  (MySQL NOW())  : 15:03:17
  ```

  Zaman **aritmetiği** bu depoda bilinçli olarak SQL tarafında yapıldığı
  için (`NOW()`, `INTERVAL`, `TIMESTAMPDIFF`) hesaplar zaten doğruydu;
  kayan şey PHP'nin ekrana ve günlüğe bastığı saatti. Ama demoyu deneyen
  biri için bu, "sistem yanlış çalışıyor" gibi görünüyordu.

### Düzeltildi

- **"Canlı Demo" bağlantısı kırıktı.** README'lerdeki en görünür düğme
  `…/kutuphane/uygulama/PHP-MySQL-Job-Queue-Is-Kuyrugu-PDO-Skip-Locked-Worker-main/` adresine gidiyordu; o adres **404**
  döndürüyordu. Vitrindeki gerçek adres `…/kutuphane/uygulama/job-queue/`.
  Her iki dildeki README'de tüm geçtiği yerler düzeltildi ve adreslerin
  200 döndüğü doğrulandı.

- **Veritabanı adı çakışması belgelendi.** Bu depo `cy_queue` veritabanını
  kullanır; `queue-worker-system` deposu da aynı adı kullanıyordu ve iki
  örneği yan yana kuran biri ikinci içe aktarmada birincinin `jobs`
  tablosunu eziyordu. Belirti, `status` isteğinin
  `SQLSTATE[42S22] Unknown column 'type'` ile 500 dönmesiydi.
  Çakışma `queue-worker-system` tarafında `cy_queue_worker` adına geçilerek
  giderildi; bu deponun şeması ve adı değişmedi.

---

## [1.0.0] — 2026-08-30

İlk genel sürüm. Kuyruk motoru, worker, arayüz ve belgelendirme üretime hazır durumda.

### Eklendi

**Kuyruk motoru**
- `SELECT … FOR UPDATE SKIP LOCKED` ile **yarışsız** rezervasyon. Klasik "önce SELECT, sonra UPDATE" kalıbının arasındaki pencere iki worker'ın **aynı işi iki kez** çalıştırmasına yol açar; bu hata tek worker'la asla oluşmadığı için testte görünmez.
- **Otomatik yedek yol**: `SKIP LOCKED` desteklenmeyen sunucularda (MariaDB 10.4 gibi) rezervasyon tek bir koşullu `UPDATE` ile yapılır ve `rowCount()` ile doğrulanır. `reserved_by` benzersiz bir damga taşır (`hostname:pid#rastgele`), böylece "benim satırımı geri oku" sorgusu kesin sonuç verir.
- Yetenek tespiti **sürüm dizgisine değil davranışa** bakar: sorgu bir kez denenir ve sonuç `static` bir değişkende hatırlanır. `"10.4.32-MariaDB"` gibi bir metni ayrıştırmak kırılgandır; dağıtımlar kendi eklerini koyar.
- **Üstel geri çekilme**: `min(BACKOFF_BASE * 2^(deneme-1), BACKOFF_CAP)`. Sabit aralıkla yeniden denemek, geçici olarak çökmüş bir servise saniyede bir vurmak ve toparlanmasını engellemek demektir.
- **Görünürlük zaman aşımı** (`RESERVE_TTL`): worker işi rezerve edip çökerse iş bu süre sonra otomatik serbest kalır. Bu sütun olmadan çöken her worker aldığı işi sonsuza dek "işleniyor" durumunda bırakırdı.
- `attempts` **rezervasyon anında** artar, işin sonunda değil — çöken bir worker'ın işi de bir denemeyi tüketmiş sayılır; yoksa sürekli çöken bir işleyici o işi sonsuza dek döndürürdü.
- **Dead-letter ayrı tabloda**: `jobs` worker'ın her turda taradığı tablodur ve küçük kalmalıdır. Ayrıca başarısız iş artık bir kuyruk kaydı değil, farklı yaşam döngüsü olan bir inceleme kaydıdır.
- `available_at` **tek sütunla iki işi birden** yapar: gecikmeli iş ve backoff beklemesi aynı soruyu sorar.
- Dead-letter'a taşıma ve yeniden kuyruğa alma **tek transaction**; iş asla iki yerde birden bulunmaz.
- Çoklu kuyruk desteği (`default`, `mails`) — uzun süren rapor işleri e-posta gönderimini bekletmesin diye.
- `generate_report` işleyicisi eklendi: kuyruğun neden var olduğunu somutlaştıran uzun süren iş örneği.

**Worker**
- CLI worker: `--once`, `--max=N`, `--queue=…`.
- `SIGTERM`/`SIGINT` alındığında **zarif kapanış**: işlenen iş bitirilir, sonra çıkılır (deploy sırasında yarım kalan iş olmaz).
- Web'den çalıştırılamaz: dosyanın başında `PHP_SAPI !== 'cli'` kontrolü **ve** `.htaccess`'te `Require all denied` — iki katman.
- Worker ile arayüz düğmesi **aynı** `process_one()` fonksiyonunu çağırır; iki ayrı işleme kodu zamanla ayrışırdı.

**Arayüz**
- Marka tasarım kalıbına taşındı: gradyan başlıklı kartlar, sayaç şeridi, toast bildirimleri, `aria-live` alanları.
- **Beş durumlu sayaç şeridi**: hazır, gecikmeli, işleniyor, **bayat rezerve**, başarısız. Bayat sayacı sıfırdan büyükse uyarı rengine geçer — o sayı, kuyrukta kimsenin işlemediği bir iş olduğu anlamına gelir.
- Worker günlüğü koyu zeminlidir çünkü bir **terminal çıktısıdır**; gerçek worker aynı satırları konsola basar. Son 200 satırla sınırlıdır.
- Otomatik mod (2 sn) ve tek adım tetikleme. Otomatik modda "kuyruk boş" satırları günlüğe basılmaz; yoksa kuyruk boşaldığında günlük saniyede bir aynı satırla dolardı.
- **İş detay modalı**: payload (biçimlendirilmiş JSON), `attempts`, `available_at`, `reserved_at`, `reserved_by`, son hata ve bir sonraki bekleme süresi. "Bu iş neden hâlâ burada?" sorusunun cevabı tek ekranda.
- Paylaşılabilir derin bağlantı `#is-10`; adresle açılan sayfa ilgili işi bulur (önce kuyrukta, bulunamazsa dead-letter'da arar).
- Bayat rezervasyonları **elle serbest bırakma** düğmesi — mekanizmayı görünür kılmak için.
- Dead-letter'da yeniden kuyruğa alma, tek tek silme ve toplu temizleme.
- Ekran 2 saniyede bir tazelenir; aynı anda iki tazeleme başlamaz (`refreshing` bayrağı).

**Veri**
- `cy_queue.sql` veritabanını kendisi oluşturur, başında `SET NAMES utf8mb4` bulunur.
- **13 iş + 4 dead-letter kaydı**, iki kuyruğa dağılmış. Zamanlar `NOW() ± INTERVAL` ile üretilir.
- Kuyruk bilerek karışık durumda açılır ve **her durumu** temsil eder: hazır, gecikmeli, backoff'ta (1. ve 2. denemede), rezerve, ve **bayat rezerve** (worker çökmüş).
- Dead-letter kayıtları bilerek farklı hata türleri taşır: geçici aksaklık (yeniden denemek mantıklı), geçersiz e-posta (kalıcı — aynı sonucu verir), bilinmeyen iş türü (kodda o handler yok). "Yeniden dene" düğmesine basmadan önce hata metnini okumak gerektiğini gösterir.
- `AUTO_INCREMENT` ileri alındı; kuyrukta tamamlanan iş satırı **silindiği** için numaralar sürekli boşalır ve paylaşılabilir bağlantılar geri dönüşüme girmemelidir.

**Mobil ve erişilebilirlik**
- Dar ekranda **yatay kaydırma yok** (360px'te ölçüldü: `scrollWidth == clientWidth`, taşan eleman yok). İkincil sütunlar gizlenir, bilgi detay modalında korunur.
- Durum rozetleri renk **ve** metin taşır — renk tek başına anlam taşımaz.
- Tablo satırları `tabindex="0"` taşır ve klavyeyle açılabilir; dokunma hedefleri en az 32–44px.

**Altyapı**
- `system/config.local.php` desteği. Bu projede ekstra bir sebep var: **worker ayrı bir süreçtir** ve aynı yapılandırmayı okur; künyeyi iki yerde tutmak birini güncelleyip diğerini unutmanın kesin yoludur.
- `APP_DEBUG` sunucu adından türetilir; CLI'da her zaman `true` sayılır — orada çıktıyı yalnızca yönetici görür ve hata ayrıntısı tam olarak orada gereklidir.
- Üç ayrı hız sınırı kovası: `enqueue`, `work` (en pahalısı), `status` (otomatik tazeleme yüzünden en sık çağrılanı).
- İş türü ve kuyruk adı **beyaz listeden** geçer (`KNOWN_JOBS`, `KNOWN_QUEUES`).
- `JSON_INVALID_UTF8_SUBSTITUTE`: hata metinleri dış servislerden gelen bozuk bir bayt içerebilir; tek bayt yüzünden kuyruğun tamamı görünmez olmamalı.

**Belgelendirme**
- Türkçe ve İngilizce README (canlı demo bölümü, 60 saniyelik deneme tablosu, beş kritik karar, worker kurulumu — systemd ve cron, güvenlik tablosu, API referansı, şema kararları, SSS).
- Ekran görüntüleri: kuyruk ekranı (worker günlüğü dolu), iş detayı, mobil görünüm.

### Düzeltildi

- **`AS delayed` sözdizimi hatası.** `DELAYED`, MySQL/MariaDB'de **ayrılmış bir kelimedir** (`INSERT DELAYED` sözdiziminden gelir) ve takma ad olarak tırnaksız kullanılamaz; sorgu 1064 veriyordu. Hata mesajı yalnızca "near 'delayed'" der, ayrılmış kelime olduğunu söylemez. Sayaç sorgusundaki bütün takma adlar backtick içine alındı.
- **`INTERVAL :param SECOND` bir `SUM()` içinde ayrıştırılamıyor.** MariaDB, INTERVAL biriminden önce bir yer tutucu görmeyi her bağlamda kabul etmiyor. `RESERVE_TTL` bir yapılandırma sabiti olduğu ve istemciden gelmediği için `(int)` dönüşümüyle SQL'e doğrudan gömüldü; bu istisna kodda açıkça belgelendi.
- **Liste ile detay farklı "bayat" cevabı veriyordu.** Liste MySQL'in `NOW()` değerine, detay ise PHP'nin `time()` değerine bakıyordu; sunucu ile veritabanının saat dilimi aynı olmak zorunda değildir. Bayatlık ve kalan süre artık **SQL'de** hesaplanır.
- **Sayaçlar tüm kuyrukları topluyor, liste yalnızca `default`'u gösteriyordu.** `mails` kuyruğuna iş eklendiğinde sayaç artıyor, liste boş kalıyordu. İkisi de aynı kuyruk koşulundan geçirildi.
- `reparent`/`clear_failed` gibi işlemler artık seçili kuyrukla sınırlıdır.
- Kullanılmayan DataTables dosyaları depodan çıkarıldı (bu örnek DataTables kullanmıyor).

### Güvenlik

- **Aynı işin iki kez çalışması** bir performans meselesi değil **doğruluk** meselesidir; `SKIP LOCKED` ve yedek yol bunun için vardır.
- **SQL Injection:** tüm sorgular prepared statement, `EMULATE_PREPARES = false`; kuyruk adı ve iş türü beyaz listeden geçer.
- **XSS:** iş türü, payload ve hata metni kullanıcı/dış servis verisidir; sunucuda `e()`, istemcide `esc()`.
- **CSRF:** oturuma bağlı 32 baytlık token, her istekte `hash_equals()` ile doğrulanır.
- **Worker'ın web'den çalıştırılması** iki katmanla engellendi.
- `.htaccess`: dizin listeleme kapalı; `.sql`, `.md`, `.json`, `.log`, `.ini`, `.bak` kapalı — `README*.md` bilinçli istisnadır. `bin/worker.php` ayrıca kapalı. `DirectoryIndex index.php` eklendi.
- `system/.htaccess` **beyaz listedir**: yalnızca `ajax.php` dışarıya açıktır.

[1.0.0]: https://github.com/CilginYazilim/job-queue/releases/tag/v1.0.0
