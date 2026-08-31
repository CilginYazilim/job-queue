<div align="center">

<img src="assets/images/logo.png" alt="Çılgın Yazılım" width="90">

# Veritabanı Tabanlı İş Kuyruğu

### PHP PDO · MySQL SKIP LOCKED · Worker · Bootstrap 5 · Çılgın Yazılım Tasarım Kalıbı

**Redis yok, RabbitMQ yok — sadece MySQL. Ama doğru yapılmış hâli.**

[![PHP](https://img.shields.io/badge/PHP-8.0%2B-777BB4?style=flat-square&logo=php&logoColor=white)](https://php.net)
[![MySQL](https://img.shields.io/badge/MySQL-8.0%2B-4479A1?style=flat-square&logo=mysql&logoColor=white)](https://mysql.com)
[![Bootstrap](https://img.shields.io/badge/Bootstrap-5.2-7952B3?style=flat-square&logo=bootstrap&logoColor=white)](https://getbootstrap.com)
[![Bağımlılık](https://img.shields.io/badge/Bağımlılık-Sıfır-16a34a?style=flat-square)](#kurulum)
[![License](https://img.shields.io/badge/Lisans-MIT-16a34a?style=flat-square)](LICENSE)

**🇹🇷 Türkçe** · [🇬🇧 English](README.en.md)

[**▶ Canlı Demo**](https://cilginyazilim.com/kutuphane/uygulama/PHP-MySQL-Job-Queue-Is-Kuyrugu-PDO-Skip-Locked-Worker-main/) · [Kaynak Kütüphanesi](https://cilginyazilim.com/kutuphane/php-job-queue) · [cilginyazilim.com](https://cilginyazilim.com)

</div>

---

<div align="center">

## Canlı Demo

**Kurulum yok, kayıt yok, indirme yok — tarayıcınızdan 3 saniyede deneyin.**

<a href="https://cilginyazilim.com/kutuphane/uygulama/PHP-MySQL-Job-Queue-Is-Kuyrugu-PDO-Skip-Locked-Worker-main/"><img src="https://img.shields.io/badge/CANLI_DEMOYU_A%C3%87-0b5cb5?style=for-the-badge&logo=googlechrome&logoColor=white&labelColor=061321" alt="Canlı Demoyu Aç" height="42"></a>
<a href="https://cilginyazilim.com/kutuphane/php-job-queue"><img src="https://img.shields.io/badge/KAYNAK_KODU_%C4%B0NCELE-0ea5e9?style=for-the-badge&logo=readthedocs&logoColor=white&labelColor=061321" alt="Kaynak Kodu İncele" height="42"></a>
<a href="https://github.com/CilginYazilim/PHP-MySQL-Job-Queue-Is-Kuyrugu-PDO-Skip-Locked-Worker/archive/refs/heads/main.zip"><img src="https://img.shields.io/badge/ZIP_%C4%B0ND%C4%B0R-16a34a?style=for-the-badge&logo=github&logoColor=white&labelColor=061321" alt="ZIP İndir" height="42"></a>

<br><br>

<a href="https://cilginyazilim.com/kutuphane/uygulama/PHP-MySQL-Job-Queue-Is-Kuyrugu-PDO-Skip-Locked-Worker-main/" title="Canlı demoyu açmak için tıklayın">
  <img src="docs/screenshots/01-kuyruk.png" alt="İş kuyruğu canlı demo önizlemesi" width="860">
</a>

<sub>▲ Görsele tıklayarak demoyu açabilirsiniz</sub>

</div>

<br>

### Demoda 60 saniyede neleri deneyebilirsiniz?

| # | Şunu deneyin | Perde arkasında ne oluyor? |
|---|--------------|----------------------------|
| **1** | **▶ Worker'ı çalıştır** düğmesine basın | Kuyruktan **tek** iş alınır, çalıştırılır ve günlüğe düşer. `SELECT … FOR UPDATE SKIP LOCKED` sayesinde iki worker aynı işi asla alamaz |
| **2** | Sayaç şeridindeki **Bayat rezerve: 1** kutusuna bakın | O iş "işleniyor" görünüyor ama onu alan worker **çökmüş**. `reserved_at` alanı `RESERVE_TTL`'den eski; iş bir sonraki turda otomatik geri alınır |
| **3** | **Bayatları serbest bırak** düğmesine basın | Görünürlük zaman aşımının elle çalıştırılmış hâli. Normalde buna gerek yoktur — mekanizma kendiliğinden işler |
| **4** | Kuyrukta **`flaky_task`** satırlarına bakın | Bu iş bilerek ~%55 olasılıkla patlar. `1/3` ve yanındaki **!** işareti, son denemede hata aldığını söyler |
| **5** | Otomatik modu açın (**Otomatik 2 sn**) ve izleyin | Geri çekilme çalışır: hata alan iş `10 sn → 20 sn → 40 sn` sonra tekrar denenir. Sabit aralıkla denemek, çökmüş bir servise saniyede bir vurmak olurdu |
| **6** | 3 denemeyi tüketen bir işi bekleyin | İş **dead-letter** tablosuna taşınır ve kuyruktan çıkar. Sonsuz deneme, kalıcı bir hatayı sonsuz bir maliyete çevirirdi |
| **7** | Dead-letter'daki hata metinlerini okuyun | Üçü de farklıdır: geçici aksaklık, **geçersiz e-posta** (kalıcı), **bilinmeyen iş türü**. "Yeniden dene" düğmesine basmadan önce okunması gereken şey budur |
| **8** | Bir işe tıklayın | `payload`, `attempts`, `available_at`, `reserved_by` ve son hata tek ekranda. Adres çubuğu `#is-10` olur — **paylaşılabilir** |
| **9** | **Kuyruk** açılır listesini `mails` yapın | Ayrı kuyruk, ayrı worker demektir. Uzun süren rapor işleri e-posta gönderimini bekletmesin diye ayrılır |
| **10** | `generate_report` ekleyip worker'ı tetikleyin | İstek, işin süresi kadar bekler (~700 ms). Kuyruğun var olma sebebi tam olarak budur: bu bekleme kullanıcının tarayıcısında olmamalı |

> **İpucu:** Demoyu açıkken **F12 → Network** sekmesini açın. `status` isteğinin 2 saniyede bir nasıl tazelendiğini, `work_once` isteğinin işin süresi kadar nasıl beklediğini ve HTTP durum kodlarını (200 / 403 / 405 / 429) canlı görebilirsiniz.

### Demo alanı hakkında bilinmesi gerekenler

| Konu | Durum |
|------|-------|
| **Veriler** | `cy_queue.sql` içindeki **13 iş + 4 dead-letter kaydı**, iki kuyruğa dağılmış. Kuyruk bilerek karışık durumda açılır: hazır, gecikmeli, backoff'ta, rezerve ve **bayat rezerve**. |
| **Sıfırlama** | Demo veritabanı **düzenli aralıklarla** başlangıç haline döner; işlediğiniz işler geri gelir. |
| **Worker** | Demoda gerçek bir arka plan süreci **yoktur**; düğme worker'ı bir kez tetikler. Kendi kurulumunuzda `php bin/worker.php` sürekli çalışır. |
| **MySQL sürümü** | `SKIP LOCKED` için **MySQL 8.0+** / **MariaDB 10.6+**. Desteklenmeyen sürümde kod **kendiliğinden** koşullu `UPDATE` yoluna düşer. |
| **`APP_DEBUG`** | Canlıda **otomatik `false`** — sunucu adından türetilir, yerelde `true` kalır. |
| **Bağımlılık** | **Sıfır.** Composer yok, npm yok, Redis yok, RabbitMQ yok. Kuyruk zaten sahip olduğunuz veritabanında. |

> Demo geçici olarak kapalıysa endişelenmeyin: depoyu klonlayıp `cy_queue.sql`'i içe aktarmanız aynı ekranı kendi bilgisayarınızda **2 dakikada** ayağa kaldırır → [Kurulum](#kurulum)

---

## Bu Proje Nedir?

Kullanıcı "Kaydet"e bastığında e-posta gönderiliyorsa, o kullanıcı SMTP sunucusunun hızına bağımlıdır. Rapor üretiliyorsa 30 saniye bekler. Görsel işleniyorsa istek zaman aşımına uğrar.

Çözüm bilinir: **işi kuyruğa al, arka planda çalıştır.** Sorun, kuyruğun kendisini yazmaktır — ve internetteki çoğu örnek şunu yapar:

```sql
SELECT * FROM jobs WHERE reserved = 0 LIMIT 1;   -- worker A ve B aynı satırı okur
UPDATE jobs SET reserved = 1 WHERE id = ?;        -- ikisi de "benim" der
```

İki worker aynı anda çalıştığında bu kod **aynı işi iki kez** çalıştırır. Kullanıcı e-postayı iki kez alır, ödeme iki kez çekilir. Hata testte görünmez: tek worker'la asla oluşmaz.

Bu proje o yarışı kapatıyor — ve kuyruğun diğer dört zor sorusunu da cevaplıyor:

1. **İki worker aynı işi alırsa?** → `FOR UPDATE SKIP LOCKED`
2. **İş başarısız olursa?** → üstel geri çekilme ile yeniden deneme
3. **Sonsuza dek başarısız olursa?** → dead-letter kuyruğu
4. **Worker işi alıp çökerse?** → görünürlük zaman aşımı (`RESERVE_TTL`)
5. **Sunucum eski MySQL çalıştırıyorsa?** → otomatik yedek rezervasyon yolu

**Kimler için uygun?**

- E-posta, rapor, görsel işleme gibi işleri istek döngüsünden çıkarmak isteyenler
- Redis/RabbitMQ kurmadan önce MySQL'in yeteceğini görmek isteyenler
- Laravel'in kuyruk sürücüsünün **ne yaptığını** merak edenler
- Paylaşımlı hostingde çalışan ve ek servis kuramayanlar
- Bootstrap 5 üzerine kurulu, tekrar kullanılabilir bir tasarım kalıbı arayanlar

> **Klonla, `cy_queue.sql`'i içe aktar, çalıştır.** Başka hiçbir kurulum adımı yok. Composer yok, npm yok, internet bağlantısı bile gerekmiyor — tüm kütüphaneler proje içinde.

Bu proje, **[Çılgın Yazılım Kütüphanesi](https://cilginyazilim.com/kutuphane)** altında yayınlanan açıklamalı, üretime hazır örneklerden biridir.

---

## İçindekiler

- [Canlı Demo](#canlı-demo)
- [Ekran Görüntüleri](#ekran-görüntüleri)
- [Beş Kritik Karar](#beş-kritik-karar)
- [Neler Var?](#neler-var)
- [Güvenlik: Neyi, Nasıl Kapattık?](#güvenlik-neyi-nasıl-kapattık)
- [Kurulum](#kurulum)
- [Worker'ı Çalıştırmak](#workerı-çalıştırmak)
- [Yapılandırma](#yapılandırma)
- [Kendi Projenize Eklemek](#kendi-projenize-eklemek)
- [Çılgın Yazılım Tasarım Kalıbı](#çılgın-yazılım-tasarım-kalıbı)
- [Dosya Yapısı](#dosya-yapısı)
- [Nasıl Çalışıyor?](#nasıl-çalışıyor)
- [AJAX API Referansı](#ajax-api-referansı)
- [Veritabanı Şeması](#veritabanı-şeması)
- [Sık Sorulanlar](#sık-sorulanlar)
- [Canlı Ortama Alırken](#canlı-ortama-alırken)
- [Sorun Giderme](#sorun-giderme)
- [Yol Haritası](#yol-haritası)
- [Katkı](#katkı)
- [Lisans](#lisans)

---

## Ekran Görüntüleri

### Kuyruk ekranı

Sayaç şeridi kuyruğun beş durumunu gösterir. Worker günlüğü koyu zeminlidir çünkü bir **terminal çıktısıdır**: gerçek worker aynı satırları konsola basar. Altta bekleyen kuyruk ve dead-letter yan yana durur.

![Kuyruk ekranı](docs/screenshots/01-kuyruk.png)

### İş detayı

"Bu iş neden hâlâ burada?" sorusunun cevabı dört alandadır: `attempts`, `available_at`, `reserved_at` ve son hata. Dördü de burada. Adres çubuğu `#is-10` olur; bağlantı paylaşılabilir.

![İş detayı](docs/screenshots/02-is-detay.png)

### Mobil görünüm

Dar ekranda ikincil sütunlar gizlenir (numara ve hata metni), bilgi detay modalında korunur. **Yatay kaydırma yoktur**; dokunma hedefleri en az 32–44px'dir.

<img src="docs/screenshots/03-mobil.png" alt="Mobil görünüm" width="360">

---

## Beş Kritik Karar

### 1) `FOR UPDATE SKIP LOCKED` — yarışı kapatan tek satır

Klasik "önce SELECT, sonra UPDATE" kalıbının arasında bir pencere vardır ve iki worker o pencereye aynı anda girer. `SKIP LOCKED` bu pencereyi **sıfırlar**: satırı okurken kilitler ve başka bir worker'ın kilitlediği satırı **beklemeden atlar**.

```sql
SELECT id FROM jobs
 WHERE queue = :q
   AND available_at <= NOW()
   AND (reserved_at IS NULL OR reserved_at < (NOW() - INTERVAL :ttl SECOND))
 ORDER BY id ASC
 LIMIT 1
 FOR UPDATE SKIP LOCKED
```

`SKIP LOCKED` olmadan `FOR UPDATE` de doğru çalışır ama **beklemeli**: ikinci worker, birincisi işini bitirene kadar durur. On worker'la kuyruk tek şeritli bir yola döner. `SKIP LOCKED` her worker'a "meşgul olanı atla, sıradakini al" dedirtir.

### 2) Eski sürümler için yedek yol — sürüme değil davranışa bakmak

`SKIP LOCKED` MySQL 8.0 ve MariaDB 10.6 ile geldi. XAMPP uzun süre MariaDB 10.4 ile dağıtıldı; orada bu sözdizimi 1064 verir.

Sürüm dizgisini ayrıştırmak (`"10.4.32-MariaDB"` gibi bir metni parçalamak) kırılgandır — dağıtımlar kendi eklerini koyar. Onun yerine **sorguyu bir kez deniyoruz** ve sonucu hatırlıyoruz:

```php
function supports_skip_locked(PDO $db): bool
{
    static $supported = null;
    if ($supported !== null) { return $supported; }

    try {
        $db->beginTransaction();
        $db->query('SELECT id FROM jobs LIMIT 0 FOR UPDATE SKIP LOCKED');
        $db->commit();
        $supported = true;
    } catch (Throwable $e) {
        if ($db->inTransaction()) { $db->rollBack(); }
        $supported = false;
    }

    return $supported;
}
```

Yedek yol kilit yerine **koşullu güncelleme** kullanır. Numara şudur: rezervasyon ile sahiplik iddiası **tek bir `UPDATE`** içinde yapılır ve `WHERE` koşulu "hâlâ rezerve edilmemiş" der:

```php
UPDATE jobs SET reserved_at = NOW(), reserved_by = :stamp, attempts = attempts + 1
 WHERE queue = :q AND available_at <= NOW()
   AND (reserved_at IS NULL OR reserved_at < (NOW() - INTERVAL :ttl SECOND))
 ORDER BY id ASC LIMIT 1
```

`rowCount() === 1` ise iş bizimdir; `0` ise başkası kapmıştır. `:stamp` benzersiz bir damgadır (`hostname:pid#rastgele`) — "benim satırımı geri oku" sorgusunu mümkün kılan şey odur.

### 3) `available_at` tek sütunla iki işi birden yapar

Gecikmeli iş ("yarın gönder") ve yeniden deneme beklemesi (backoff) aynı soruyu sorar: *bu iş ne zaman işlenebilir?* İkisi için ayrı sütun tutmak (`delay_until` + `retry_at`) gereksizdir ve hangisinin geçerli olduğunu her sorguda karar vermeyi gerektirir.

```php
function backoff_seconds(int $attempts): int
{
    return (int) min(BACKOFF_BASE * (2 ** max(0, $attempts - 1)), BACKOFF_CAP);
}
```

**Neden üstel:** sabit aralıkla yeniden denemek, geçici olarak çökmüş bir servise saniyede bir vurmak demektir — toparlanmasını engellersiniz. Üstel geri çekilme baskıyı kendiliğinden azaltır. `BACKOFF_CAP` ise beklemenin saatlere çıkmasını önler.

### 4) Görünürlük zaman aşımı — çöken worker'ın işi kaybolmaz

Bir worker işi rezerve edip çökerse (elektrik, OOM, deploy) o iş sonsuza dek "işleniyor" durumunda kalır. Kimse işlemez, kimse fark etmez.

Çözüm `reserved_at` sütununun **eskiliğine** bakmaktır:

```sql
AND (reserved_at IS NULL OR reserved_at < (NOW() - INTERVAL 90 SECOND))
```

Bu tek koşul, "serbest" ile "rezerve ama sahibi kayıp" durumlarını aynı sorguda toplar. Demoda o durum ekranda **ayrı bir sayaç** olarak durur — çünkü sıfırdan büyükse bir sorun var demektir.

İkinci bir incelik: `attempts` **rezervasyon anında** artar, işin sonunda değil. Çöken bir worker'ın işi de bir denemeyi tüketmiş sayılır; yoksa sürekli çöken bir işleyici o işi sonsuza dek döndürürdü.

### 5) Dead-letter ayrı bir tablodur

Başarısız işleri `jobs` tablosunda bir `status` sütunuyla tutmak cazip görünür. İki sebeple yanlıştır:

- `jobs`, worker'ın **her turda taradığı** tablodur ve küçük kalmalıdır. Asla işlenmeyecek satırlar orada birikirse her rezervasyon sorgusu yavaşlar.
- Başarısız iş artık bir **kuyruk kaydı** değil, bir **inceleme kaydıdır**. Farklı bir yaşam döngüsü vardır: insan bakar, düzeltir, yeniden kuyruğa alır ya da siler.

Demodaki dead-letter kayıtları bilerek farklı hata türleri taşır: geçici bir aksaklık (yeniden denemek mantıklı), geçersiz bir e-posta adresi (kalıcı — aynı sonucu verir) ve bilinmeyen bir iş türü (kodda o handler yok). **"Yeniden dene" düğmesine basmadan önce hata metnini okumak gerekir.**

---

## Neler Var?

<table>
<tr><td valign="top" width="50%">

**Kuyruk motoru**

- `FOR UPDATE SKIP LOCKED` ile **yarışsız** rezervasyon
- Desteklenmeyen sürümde **otomatik** koşullu-UPDATE yedeği
- Yetenek tespiti sürüm dizgisine değil **davranışa** bakar
- Üstel geri çekilme (`10 → 20 → 40 …`, tavanlı)
- Görünürlük zaman aşımı: çöken worker'ın işi geri döner
- `attempts` rezervasyon anında artar (çökme de sayılır)
- Dead-letter ayrı tabloda; yeniden kuyruğa alma ve silme
- Çoklu kuyruk desteği (`default`, `mails`) — ayrı worker'lar için
- Gecikmeli iş: `available_at` tek sütunla iki işi birden yapar
- CLI worker: `--once`, `--max=N`, `--queue=…`, zarif kapanış (SIGTERM)

</td><td valign="top" width="50%">

**Arayüz ve altyapı**

- Sayaç şeridi: hazır / gecikmeli / işleniyor / **bayat** / başarısız
- "Bayat rezerve" sayacı sıfırdan büyükse **uyarı rengine** geçer
- Worker günlüğü — terminal çıktısının ekrandaki karşılığı
- Otomatik mod (2 sn) ve tek adım tetikleme
- İş detayı: payload, zamanlar, `reserved_by`, son hata
- Paylaşılabilir derin bağlantı `#is-10`
- Bayat rezervasyonları elle serbest bırakma düğmesi
- Üç ayrı hız sınırı kovası (enqueue / work / status)
- Çift gönderim koruması, toast bildirimleri, `aria-live`
- Mobil: 767 / 480px eşikleri, **yatay kaydırma yok**

</td></tr>
</table>

---

## Güvenlik: Neyi, Nasıl Kapattık?

| Açık | Tipik hatalı kod | Bu projede |
|------|------------------|------------|
| **Aynı işin iki kez çalışması** | `SELECT … LIMIT 1` sonra `UPDATE` | `FOR UPDATE SKIP LOCKED`; yedek yolda tek `UPDATE` + `rowCount()` kontrolü. Bu bir "performans iyileştirmesi" değil, **doğruluk** meselesidir |
| **SQL Injection** | `"… WHERE queue = '".$_POST['q']."'"` | Tüm sorgular prepared statement, `EMULATE_PREPARES = false`. Kuyruk adı ve iş türü **beyaz listeden** geçer (`KNOWN_QUEUES`, `KNOWN_JOBS`) |
| **XSS (kuyruk ekranında)** | `$('#row').html(job.type)` | İş türü, payload ve hata metni kullanıcı/dış servis verisidir. Sunucuda `e()`, istemcide `esc()` |
| **Worker'ın web'den çalıştırılması** | `bin/worker.php` erişilebilir | İki katman: dosyanın başında `PHP_SAPI !== 'cli'` kontrolü **ve** `.htaccess`'te `Require all denied` |
| **Sonsuz yeniden deneme** | `while (true) retry` | `JOB_MAX_ATTEMPTS` ve dead-letter. Kalıcı bir hata sonsuz bir maliyete dönüşmez |
| **Kaybolan iş (çöken worker)** | Sadece `reserved = 1` bayrağı | `reserved_at` + `RESERVE_TTL`. Sahipsiz kalan iş otomatik geri döner |
| **Yarım kalan durum geçişi** | Önce `INSERT`, sonra `DELETE` | Dead-letter'a taşıma ve yeniden kuyruğa alma **tek transaction**; iş asla iki yerde birden bulunmaz |
| **Bozuk kodlamanın yanıtı yutması** | `json_encode()` → `false` → boş gövde | `JSON_INVALID_UTF8_SUBSTITUTE`: dış servisten gelen bozuk bir bayt, kuyruğun tamamını görünmez yapmaz |
| **CSRF** | *(genelde hiç yok)* | Oturuma bağlı 32 baytlık token, **her** istekte, `hash_equals()` ile sabit zamanlı doğrulama |
| **Kaynak tüketimi** | Sınırsız `enqueue` | `ENQUEUE_MAX_COUNT` (25) ve **üç ayrı hız sınırı kovası**. `status` en sık, `work` en pahalı olandır |
| **Bilgi sızıntısı** | Ekrana basılan MySQL hataları | `APP_DEBUG` sunucu adından türetilir; canlıda otomatik `false`, detay `error_log()`'a gider |
| **Kurulum dosyasının indirilmesi** | `/cy_queue.sql` → HTTP 200 | `.htaccess`: `.sql`, `.md`, `.json`, `.log` … kapalı (README dosyaları bilinçli istisnadır) |

---

## Kurulum

> Sadece görmek istiyorsanız kurulum gerekmez → [**Canlı Demoyu açın**](https://cilginyazilim.com/kutuphane/uygulama/PHP-MySQL-Job-Queue-Is-Kuyrugu-PDO-Skip-Locked-Worker-main/). Aşağıdaki adımlar projeyi kendi bilgisayarınızda çalıştırmak içindir (~2 dakika).

### Gereksinimler

- PHP **8.0+** (`pdo_mysql` eklentisi; zarif kapanış için `pcntl` — isteğe bağlı)
- **MySQL 8.0+** veya **MariaDB 10.6+** önerilir (`SKIP LOCKED` için)
  Daha eski sürümlerde kod **kendiliğinden** yedek yola düşer; kurulum değişmez.
- Apache (XAMPP / WAMP / Laragon) — ya da PHP'nin yerleşik sunucusu

### Adımlar

**1 — Projeyi indirin**

```bash
git clone https://github.com/CilginYazilim/PHP-MySQL-Job-Queue-Is-Kuyrugu-PDO-Skip-Locked-Worker.git
cd PHP-MySQL-Job-Queue-Is-Kuyrugu-PDO-Skip-Locked-Worker
```

**2 — Veritabanını oluşturun**

`cy_queue.sql` veritabanını da kendisi oluşturur.

```bash
mysql -u root -p < cy_queue.sql
```

**3 — Arayüzü açın**

```bash
php -S 127.0.0.1:8000
```

XAMPP kullanıyorsanız projeyi `htdocs` altına koyup şu adresi açın:
`http://localhost/PHP-MySQL-Job-Queue-Is-Kuyrugu-PDO-Skip-Locked-Worker/`

**4 — Worker'ı ayrı bir terminalde başlatın** *(isteğe bağlı ama asıl olan budur)*

```bash
php bin/worker.php
```

Arayüzdeki **▶ Worker'ı çalıştır** düğmesi de aynı işi yapar — ama tek adımda ve gözlem için.

---

## Worker'ı Çalıştırmak

```bash
php bin/worker.php                 # varsayılan kuyruk, süresiz
php bin/worker.php --once          # tek iş işle ve çık
php bin/worker.php --max=50        # 50 iş işleyince çık
php bin/worker.php --queue=mails   # başka bir kuyruk
```

### Üretimde: süpervizör altında

Worker sürekli çalışan bir süreçtir ve **çökebilir**. Bir süpervizör onu yeniden başlatmalıdır. systemd örneği:

```ini
[Unit]
Description=CY Job Queue Worker
After=mysql.service

[Service]
ExecStart=/usr/bin/php /var/www/proje/bin/worker.php --queue=default
Restart=always
RestartSec=5
User=www-data

[Install]
WantedBy=multi-user.target
```

`--max=500` ile birlikte kullanmak da yaygındır: worker 500 iş sonra kendini kapatır, süpervizör yeniden başlatır. Bu, uzun süren PHP süreçlerinde bellek sızıntısı riskini sıfırlar.

### Cron ile (süpervizör yoksa)

```cron
* * * * * cd /var/www/proje && php bin/worker.php --max=20 >> /var/log/cy-queue.log 2>&1
```

Dakikada bir çalışır, en fazla 20 iş işler ve çıkar. Paylaşımlı hostingde en pratik yoldur.

### Zarif kapanış

`pcntl` eklentisi varsa worker `SIGTERM`/`SIGINT` aldığında **işlenen işi bitirir**, sonra çıkar. Deploy sırasında yarım kalan iş olmaz.

---

## Yapılandırma

Tüm ayarlar [system/config.php](system/config.php) içindedir.

| Sabit | Varsayılan | Ne işe yarar |
|-------|-----------|--------------|
| `JOB_MAX_ATTEMPTS` | `3` | Bu kadar denemeden sonra iş dead-letter'a taşınır |
| `BACKOFF_BASE` | `10` | İlk yeniden deneme beklemesi (saniye) |
| `BACKOFF_CAP` | `600` | Beklemenin üst sınırı — saatlere çıkmasın |
| `RESERVE_TTL` | `90` | Görünürlük zaman aşımı: çöken worker'ın işi bu süre sonra serbest kalır |
| `WORKER_SLEEP` | `3` | Kuyruk boşken worker'ın uykusu (sıfır olsaydı %100 CPU) |
| `KNOWN_JOBS` | 4 tür | Arayüzden eklenebilecek iş türleri (**beyaz liste**) |
| `KNOWN_QUEUES` | `default, mails` | İzin verilen kuyruk adları (**beyaz liste**) |
| `ENQUEUE_MAX_COUNT` | `25` | Tek seferde eklenebilecek en fazla iş |
| `RATE_LIMIT_*` | `[istek, saniye]` | enqueue / work / status için **ayrı** kovalar |

### `RESERVE_TTL` nasıl seçilir?

**En uzun işinizin süresinden belirgin biçimde uzun** olmalıdır. Kısa seçerseniz, hâlâ çalışan bir worker'ın işi "bayat" sayılır ve **ikinci kez** alınır — tam da kaçındığınız şey olur.

30 saniyelik rapor işleriniz varsa `RESERVE_TTL = 300` makuldür. Emin değilseniz büyük tarafta hata yapın: geç fark edilen bir çökme, iki kez çalışan bir işten ucuzdur.

### Şifreyi koda yazmayın

Bu projede ekstra bir sebep var: **worker ayrı bir süreçtir** ve aynı yapılandırmayı okur. Künyeyi iki yerde tutmak, birini güncelleyip diğerini unutmanın kesin yoludur.

```bash
cp system/config.local.php.example system/config.local.php
# sonra dört satırı doldurun
```

Öncelik sırası: **`config.local.php` → ortam değişkeni → yerel varsayılan.**

---

## Kendi Projenize Eklemek

**1 — İki tabloyu alın**

`jobs` ve `failed_jobs` iş türünüzden bağımsızdır; olduğu gibi kopyalayın. `idx_jobs_pick` indeksini **mutlaka** taşıyın: worker'ın her turda çalıştırdığı sorgu ona dayanır.

**2 — Kendi işleyicilerinizi yazın**

```php
// system/function.php
function run_job_handler(string $type, array $payload): string
{
    return match ($type) {
        'send_invoice' => handle_send_invoice($payload),
        'sync_stock'   => handle_sync_stock($payload),
        default        => throw new RuntimeException("Bilinmeyen iş türü: $type"),
    };
}
```

İşleyici **başarıda bir metin döndürür, hatada exception fırlatır.** Motor gerisini yapar: exception yakalanır, `attempts` kontrol edilir, ya backoff ile geri bırakılır ya dead-letter'a taşınır.

**3 — Kodunuzdan iş ekleyin**

```php
enqueue($db, 'send_invoice', ['order_id' => 4271], 'default', 0);
enqueue($db, 'sync_stock',   ['sku' => 'ABC-1'],   'default', 300);  // 5 dk sonra
```

**4 — Worker'ı süpervizör altına alın**

Yukarıdaki systemd birimi ya da cron satırı.

> **Atlamayın:** İşleyicileriniz **idempotent** olmalıdır — aynı iş iki kez çalışsa bile sonuç aynı olmalı. `SKIP LOCKED` yarışı kapatır, ama worker işi bitirip `complete_job()` çağırmadan hemen önce çökerse iş yeniden alınır. Dağıtık sistemlerde "tam bir kez" garantisi yoktur; "en az bir kez" vardır.

---

## Çılgın Yazılım Tasarım Kalıbı

[assets/css/cilginyazilim.css](assets/css/cilginyazilim.css) dosyası bu projeye değil **markaya** aittir. Bu örneğe özgü her şey (sayaç şeridi, durum rozetleri, worker günlüğü) [assets/css/style.css](assets/css/style.css) içindedir.

### Hazır bileşenler

| Sınıf | Ne işe yarar |
|-------|--------------|
| `.cy-card` / `.cy-card__header` / `__body` / `__footer` | Gradyan başlıklı ana kart |
| `.cy-brand` / `.cy-brand__mark` / `__title` / `__subtitle` | Logo kutusu + başlık bloğu |
| `.cy-btn` + `--primary` \| `--onbrand` \| `--glass` | Marka butonları |
| `.cy-badge` + `--glass` \| `--soft` | Rozetler |
| `.cy-table` | Marka görünümlü tablo |
| `.cy-modal` / `.cy-detail` | Gradyan başlıklı modal ve etiket/değer listesi |
| `.cy-toast` + `--success` \| `--danger` \| `--info` | Bildirim balonları |

**Bu örneğe özgü** (`style.css`): `.cy-stats` / `.cy-stat`, `.cy-state--ready|delayed|reserved|stale|failed`, `.cy-log`, `.cy-panel`, `.cy-worker`, `.cy-switch`, `.cy-attempts`, `.cy-warn-dot`.

### Renkleri değiştirmek

```css
:root {
    --cy-brand-900: #061321;   /* Logodaki en koyu lacivert */
    --cy-brand-600: #0b5cb5;   /* Ana marka mavisi          */
    --cy-accent:    #0ea5e9;   /* Vurgu rengi               */
    --cy-gradient:  linear-gradient(135deg, #061321, #0b5cb5 45%, #0284c7);
}
```

### Koyu tema

Kullanıcının işletim sistemi koyu temadaysa **otomatik** devreye girer. Zorlamak isterseniz: `<html data-cy-theme="dark">`

---

## Dosya Yapısı

```
job-queue/
├── index.php                 → Arayüz. Veritabanına DOKUNMAZ; üç kart + bir modal.
├── cy_queue.sql              → Şema + 13 iş + 4 dead-letter kaydı (NOW() ± INTERVAL)
├── .htaccess                 → Dizin listeleme kapalı, .sql/.md kapalı, bin/ kapalı
│
├── bin/
│   └── worker.php            → CLI worker. Web'den çalıştırılamaz (iki katman koruma).
│
├── system/
│   ├── config.php            → Ayarlar, PDO bağlantısı, APP_DEBUG türetimi
│   ├── config.local.php      → (siz oluşturursunuz) canlı künye — .gitignore'da
│   ├── config.local.php.example
│   ├── function.php          → KUYRUK MOTORU: enqueue, reserve, complete, release, fail
│   ├── ajax.php              → 8 uç nokta
│   └── .htaccess             → BEYAZ LİSTE: yalnızca ajax.php dışarıya açık
│
├── assets/
│   ├── css/
│   │   ├── cilginyazilim.css → MARKA KALIBI (projeler arası ortak — dokunmayın)
│   │   ├── style.css         → Yalnızca bu örneğe özgü stiller
│   │   └── bootstrap.min.css
│   ├── js/
│   │   ├── queue.js          → Sayaçlar, tetikleme, dead-letter, modal (6 bölüm)
│   │   ├── jquery-3.7.0.js
│   │   └── bootstrap.bundle.js
│   └── images/logo.png
│
├── docs/screenshots/
├── CHANGELOG.md
├── README.md · README.en.md
└── LICENSE                   → MIT
```

---

## Nasıl Çalışıyor?

```
                    enqueue($db, 'send_email', […], 'default', 0)
                                     │
                                     ▼
        ┌────────────────────────────────────────────────────────┐
        │  jobs                                                  │
        │    available_at = NOW() + gecikme                      │
        │    reserved_at  = NULL                                 │
        │    attempts     = 0                                    │
        └────────────────────────────────────────────────────────┘
                                     │
        worker: reserve_job()        ▼
        ┌────────────────────────────────────────────────────────┐
        │  BEGIN                                                 │
        │    SELECT id … FOR UPDATE SKIP LOCKED   ← yarış YOK    │
        │      WHERE available_at <= NOW()                       │
        │        AND (reserved_at IS NULL                        │
        │             OR reserved_at < NOW() - RESERVE_TTL)      │
        │    UPDATE reserved_at = NOW(), attempts = attempts + 1 │
        │  COMMIT                                                │
        └────────────────────────────────────────────────────────┘
                                     │
                      run_job_handler($type, $payload)
                                     │
                 ┌───────────────────┴───────────────────┐
            başarı                                    exception
                 │                                        │
                 ▼                                        ▼
        complete_job()                        attempts < JOB_MAX_ATTEMPTS ?
        satır SİLİNİR                          │                     │
                                            evet                  hayır
                                               │                     │
                                               ▼                     ▼
                                        release_job()          fail_job()
                                  reserved_at = NULL      failed_jobs'a TAŞI
                                  available_at = NOW()    jobs'tan SİL
                                    + backoff(attempts)   (tek transaction)
```

### Sorumluluk dağılımı

| Katman | Dosya | Sorumluluk |
|--------|-------|-----------|
| Sunum | `index.php` | Yalnızca HTML iskeleti. Veri yok, sorgu yok. |
| Arayüz mantığı | `assets/js/queue.js` | Sayaçlar, tetikleme, otomatik mod, modal |
| Uç noktalar | `system/ajax.php` | İstek doğrulama, beyaz listeler, JSON yanıtı |
| **Kuyruk motoru** | `system/function.php` | Rezervasyon, backoff, dead-letter — **asıl iş burada** |
| Worker | `bin/worker.php` | Döngü, sinyal yönetimi, konsol çıktısı |
| Ayar | `system/config.php` | Sabitler, PDO, `APP_DEBUG` türetimi |

> Dikkat: `bin/worker.php` ile arayüzdeki düğme **aynı** `process_one()` fonksiyonunu çağırır. İki ayrı işleme kodu yazılsaydı, ikisi zamanla ayrışır ve "arayüzde çalışıyor ama worker'da çalışmıyor" durumu doğardı.

---

## AJAX API Referansı

Tüm istekler `system/ajax.php` adresine **POST** ile gider ve **CSRF token** taşır.

<details>
<summary><b><code>status</code> — sayaçlar + iki liste</b></summary>

**İstek:** `queue`

```json
{
  "success": true,
  "queue": "default",
  "queues": ["default", "mails"],
  "counts": {"ready": 6, "delayed": 2, "reserved": 1, "stale": 1, "total": 10, "failed": 3},
  "pending": [
    {"id": 10, "type": "generate_report", "attempts": 1,
     "reserved": true, "stale": true, "in_seconds": 0, "has_error": false}
  ],
  "failed": [
    {"id": 2, "type": "send_email", "attempts": 3,
     "exception": "InvalidArgumentException: Geçersiz e-posta adresi: gecersiz-adres",
     "failed_at": "30.08.2026 20:12"}
  ],
  "max_attempts": 3,
  "reserve_ttl": 90
}
```

`reserved` ile `stale` **ayrı** alanlardır: ikisi de "rezerve edilmiş" demektir, ama `stale` olan işin sahibi kayıptır.
</details>

<details>
<summary><b><code>work_once</code> — worker'ı bir kez tetikle</b></summary>

```json
{"success": true, "queue": "default", "took_ms": 236.7,
 "status": "done", "job_id": 4, "type": "send_email",
 "message": "İş #4 (send_email) tamamlandı: e-posta gönderildi → mehmet@ornek.com"}
```

`status` dört değerden biridir:

| Değer | Anlamı |
|-------|--------|
| `done` | İş tamamlandı, satır silindi |
| `retry` | Hata alındı, backoff ile geri bırakıldı |
| `failed` | Deneme hakkı bitti, dead-letter'a taşındı |
| `empty` | İşlenecek iş yok |
</details>

<details>
<summary><b><code>enqueue</code> — kuyruğa iş ekle</b></summary>

**İstek:** `type`, `count`, `delay`, `queue`

```json
{"success": true, "description": "3 iş \"default\" kuyruğuna eklendi.",
 "ids": [121, 122, 123], "queue": "default"}
```

`type` `KNOWN_JOBS`, `queue` `KNOWN_QUEUES` beyaz listesinden geçer; geçersiz tür **422**, geçersiz kuyruk sessizce varsayılana düşer.
</details>

<details>
<summary><b><code>job_detail</code> — tek işin künyesi</b></summary>

**İstek:** `id`, `source` (`jobs` \| `failed`)

```json
{
  "success": true,
  "job": {
    "id": 10, "source": "jobs", "queue": "default", "type": "generate_report",
    "attempts": 1, "payload": "{\n    \"report\": \"stok-durumu\",\n    \"format\": \"xlsx\"\n}",
    "available_at": "30.08.2026 23:34:33", "in_seconds": 0,
    "reserved_at": "30.08.2026 23:35:33", "reserved_by": "worker-02:8891",
    "stale": true, "last_error": null,
    "created_at": "30.08.2026 23:27:33", "next_backoff": 20
  }
}
```

`stale` ve `in_seconds` **SQL'de** hesaplanır. İlk sürümde PHP'nin `time()` değeri kullanılıyordu ve sunucu ile veritabanının saat dilimi farklı olduğunda liste ile detay **birbirinden farklı** cevap veriyordu.
</details>

<details>
<summary><b><code>release_stale</code> · <code>retry_failed</code> · <code>forget_failed</code> · <code>clear_failed</code></b></summary>

`release_stale` bayatlamış rezervasyonları serbest bırakır ve kaç tanesini bıraktığını döner. Normalde gerekli değildir — mekanizma kendiliğinden işler; bu uç nokta onu **görünür** kılmak içindir.

`retry_failed` dead-letter kaydını kuyruğa geri koyar ve dead-letter satırını siler — **tek transaction**. Ayrı yazılsaydı iş hem kuyrukta hem başarısızlarda görünebilirdi.
</details>

### HTTP durum kodları

| Kod | Anlamı |
|-----|--------|
| `200` | İşlem başarılı (`status: empty` de 200'dür) |
| `400` | Geçersiz parametre ya da bilinmeyen işlem |
| `403` | CSRF token geçersiz veya oturum düşmüş |
| `404` | İş bulunamadı (işlenmiş ve silinmiş olabilir) |
| `405` | POST dışı istek |
| `422` | Bilinmeyen iş türü |
| `429` | Hız sınırı aşıldı (`retry_after` saniye döner) |
| `500` | Sunucu / veritabanı hatası |

---

## Veritabanı Şeması

```sql
CREATE TABLE `jobs` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `queue`        VARCHAR(60)  NOT NULL DEFAULT 'default',
  `type`         VARCHAR(60)  NOT NULL,
  `payload`      JSON         NOT NULL,
  `attempts`     TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `available_at` DATETIME     NOT NULL,
  `reserved_at`  DATETIME     NULL DEFAULT NULL,
  `reserved_by`  VARCHAR(100) NULL DEFAULT NULL,
  `last_error`   VARCHAR(1000) NULL DEFAULT NULL,
  `created_at`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_jobs_pick` (`queue`, `available_at`, `reserved_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `failed_jobs` (
  `id`        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `queue`     VARCHAR(60)  NOT NULL DEFAULT 'default',
  `type`      VARCHAR(60)  NOT NULL,
  `payload`   JSON         NOT NULL,
  `attempts`  TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `exception` TEXT         NOT NULL,
  `failed_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_failed_type` (`type`),
  KEY `idx_failed_queue` (`queue`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

| Karar | Neden |
|-------|-------|
| **`idx_jobs_pick` (queue, available_at, reserved_at)** | Worker'ın her turda çalıştırdığı sorgunun dayandığı indeks. Sütun **sırası** önemlidir: önce eşitlik (`queue`), sonra aralık (`available_at`). Ters sırada indeks aralık taramasından sonra kullanılamaz |
| **`available_at` DATETIME** | Gecikmeli iş ve backoff **aynı** sütunla ifade edilir; ayrı bir `retry_at` gereksizdir |
| **`reserved_at` + `reserved_by`** | Biri zaman aşımı için, diğeri "kim aldı" sorusu için. `reserved_by` yedek rezervasyon yolunda benzersiz damga da taşır |
| **`attempts` TINYINT** | 255 deneme her senaryo için fazlasıyla yeter; `INT` kullanmak satır başına 3 bayt israfıdır |
| **`payload` JSON** | İş türü başına farklı alanlar gerekir; sabit sütunlar her yeni iş türünde şema değişikliği isterdi |
| **`failed_jobs` AYRI tablo** | `jobs` worker'ın her turda taradığı tablodur ve küçük kalmalıdır. Başarısız işleri orada bir `status` sütunuyla tutmak her rezervasyon sorgusunu yavaşlatır |
| **`last_error` VARCHAR(1000)** | Kuyrukta duran iş için son hatanın **özeti** yeter; tam metin dead-letter'da `TEXT` olarak saklanır |
| **`BIGINT` id** | Kuyruk satırları sürekli oluşur ve silinir; `AUTO_INCREMENT` hızla büyür ve `INT` tavanı beklenenden erken gelir |
| **InnoDB** | Satır kilidi ve transaction desteği — `SKIP LOCKED`'ın ön şartı |

---

## Sık Sorulanlar

<details>
<summary><b>Neden Redis ya da RabbitMQ değil?</b></summary>

Çünkü çoğu proje için gerekmiyor. Zaten bir veritabanınız var, yedekleniyor, izleniyor ve ekibiniz onu biliyor. İkinci bir servis eklemek: kurulum, yedekleme, izleme, sürüm yükseltme ve bir arıza noktası daha demektir.

MySQL kuyruğu şu noktaya kadar rahat gider: dakikada birkaç bin iş, birkaç worker. Ötesinde Redis (hız) ya da RabbitMQ (yönlendirme, çoklu tüketici desenleri) anlamlı olmaya başlar.

Ayrıca bir avantajı vardır ki küçümsenmemeli: **işi ekleyen transaction ile aynı transaction içinde kuyruğa koyabilirsiniz.** Sipariş kaydı geri alınırsa e-posta işi de geri alınır. Ayrı bir kuyruk servisinde bu garanti yoktur.
</details>

<details>
<summary><b>SKIP LOCKED olmadan gerçekten iki worker aynı işi alır mı?</b></summary>

Evet — ve bu, testte asla görünmeyen hatalardandır. Tek worker'la çalıştırdığınızda hiç oluşmaz.

Klasik kalıpta `SELECT … LIMIT 1` ile `UPDATE … SET reserved = 1` arasında bir pencere vardır. İki worker aynı milisaniyede `SELECT` yaparsa ikisi de aynı `id`yi görür, ikisi de `UPDATE` eder, ikisi de işi çalıştırır. Kullanıcı e-postayı iki kez alır.

Bu projede yedek yol bile bu pencereyi kapatır: rezervasyon ve sahiplik iddiası **tek bir `UPDATE`** içindedir ve `WHERE` koşulu "hâlâ rezerve edilmemiş" der. İkinci worker'ın `UPDATE`'i 0 satır etkiler.
</details>

<details>
<summary><b>İşim iki kez çalışabilir mi? "Tam bir kez" garantisi var mı?</b></summary>

Yoktur — ve hiçbir dağıtık kuyrukta yoktur. Worker işi bitirip `complete_job()` çağırmadan hemen önce çökerse, iş `RESERVE_TTL` sonra yeniden alınır ve **ikinci kez** çalışır.

Bu yüzden işleyicileriniz **idempotent** olmalıdır: aynı iş iki kez çalışsa bile sonuç aynı olmalı. Pratikte bu, "bu siparişin faturası zaten gönderildi mi?" kontrolü ya da bir `unique` indeks demektir.

Kuyruğun garantisi "en az bir kez"dir. "Tam bir kez" davranışını **uygulama katmanı** üretir.
</details>

<details>
<summary><b>`RESERVE_TTL` değerini nasıl seçerim?</b></summary>

En uzun işinizin süresinden **belirgin biçimde uzun** olmalıdır. Kısa seçerseniz hâlâ çalışan bir worker'ın işi "bayat" sayılır ve ikinci kez alınır — tam da kaçındığınız şey.

30 saniyelik rapor işleriniz varsa 300 saniye makuldür. Emin değilseniz büyük tarafta hata yapın: geç fark edilen bir çökme, iki kez çalışan bir işten ucuzdur.
</details>

<details>
<summary><b>Kuyruk tablosu şişer mi? Temizlik gerekir mi?</b></summary>

`jobs` tablosu şişmez: tamamlanan iş **silinir**. Bu bilinçli bir karardır — worker'ın her turda taradığı tablo küçük kalmalıdır.

`failed_jobs` ise büyür. Orada bir saklama politikası belirleyin: örneğin 30 günden eski kayıtları arşivleyin ya da silin. Silmeden önce **okuyun**: dead-letter, sisteminizin nerede kırıldığını anlatan en dürüst kayıttır.

Tamamlanan işlerin kaydını tutmak isterseniz `complete_job()` içinde ayrı bir arşiv tablosuna yazabilirsiniz — ama bunun kuyruk için gerekli olmadığını, **raporlama** ihtiyacı olduğunu bilerek yapın.
</details>

<details>
<summary><b>Neden birden fazla kuyruk (`default`, `mails`) var?</b></summary>

Çünkü bütün işler eşit değildir. 40 saniye süren bir rapor işi, 200 milisaniyelik e-posta işlerinin önünde durursa e-postalar gecikir.

Ayrı kuyruk = ayrı worker demektir:

```bash
php bin/worker.php --queue=default   # raporlar, görseller
php bin/worker.php --queue=mails     # e-postalar, hızlı işler
```

Şemadaki `queue` sütunu ve `idx_jobs_pick` indeksinin ilk sütunu tam olarak bunun içindir.
</details>

<details>
<summary><b>Arayüzdeki "Worker'ı çalıştır" düğmesi üretimde de kullanılabilir mi?</b></summary>

Hayır — ve zaten kullanılmamalı. O düğme bir işi **web isteğinin içinde** çalıştırır: istek, işin süresi kadar bekler. Kuyruğun var olma sebebi tam olarak bunu önlemektir.

Düğme demoda iki iş görür: kuyruğun davranışını adım adım izletmek, ve bir işi istek içinde çalıştırmanın neden kötü fikir olduğunu **hissettirmek** (`generate_report` işini tetikleyin, isteğin nasıl beklediğini görün).
</details>

---

## Canlı Ortama Alırken

- [ ] Worker'ı bir **süpervizör** altına alın (systemd / supervisor) ya da cron ile `--once` çağırın
- [ ] `--max=N` kullanın: uzun süren PHP süreçlerinde bellek sızıntısı riskini sıfırlar
- [ ] `RESERVE_TTL` değerini **en uzun işinizden uzun** seçin
- [ ] `APP_DEBUG` zaten ortamdan türetiliyor — yine de canlıda `false` olduğunu **doğrulayın**
- [ ] `system/config.local.php` oluşturun; künyeyi `config.php`'ye **yazmayın** (worker da aynı dosyayı okur)
- [ ] `bin/worker.php` adresinin web'den **erişilemediğini** doğrulayın
- [ ] Arayüzü **giriş sistemi arkasına** alın: kimlik doğrulaması olmayan bir kuyruk paneli, herkesin iş ekleyebildiği bir uç noktadır
- [ ] `failed_jobs` için bir **saklama politikası** belirleyin
- [ ] İşleyicilerinizin **idempotent** olduğunu gözden geçirin
- [ ] Nginx kullanıyorsanız `.htaccess` çalışmaz; şu iki yolu sunucu yapılandırmasından kapatın:
  ```nginx
  location ~* \.(sql|log|ini|bak)$ { deny all; }
  location ^~ /bin/                { deny all; }
  ```

---

## Sorun Giderme

| Belirti | Çözüm |
|---------|-------|
| **"You have an error in your SQL syntax" (1064)** | Muhtemelen `SKIP LOCKED` desteklenmiyor. Kod bunu **kendiliğinden** tespit edip yedek yola düşer; hata görüyorsanız `supports_skip_locked()` değiştirilmiş olabilir. |
| **`AS delayed` yazınca 1064 alıyorum** | `DELAYED` MySQL/MariaDB'de **ayrılmış kelimedir**. Takma adı backtick içine alın: `` AS `delayed` ``. |
| **İşler "işleniyor"da takılı kaldı** | Worker çökmüş olabilir. `RESERVE_TTL` sonra otomatik serbest kalırlar; hemen istiyorsanız "Bayatları serbest bırak" düğmesini kullanın. |
| **Aynı iş iki kez çalışıyor** | Ya `RESERVE_TTL` en uzun işinizden kısa, ya da bir worker `complete_job()` öncesi çöküyor. İşleyicilerinizi idempotent yapın. |
| **Worker hiç iş almıyor** | `--queue` parametresi kuyruk adıyla eşleşiyor mu? Varsayılan `default`; demo verisinde `mails` kuyruğunda da işler var. |
| **Liste ile detay farklı "bayat" cevabı veriyor** | Bu sürümde düzeltildi: bayatlık artık **SQL'de** hesaplanıyor. Değiştirdiyseniz, PHP `time()` ile MySQL `NOW()` karşılaştırmayın — saat dilimleri farklı olabilir. |
| **HTTP 429 dönüyor** | Hız sınırı. Otomatik mod açıkken `status` dakikada 30, `work` dakikada 30 istek atar; otomatik bir test aracı sınırı kolayca aşar. |
| **`bin/worker.php` tarayıcıda 403** | Beklenen davranış: worker yalnızca komut satırından çalışır (iki katman koruma). |
| **Türkçe karakterler bozuk** | Veritabanı utf8mb4 değil ya da SQL dosyası `SET NAMES utf8mb4` olmadan aktarılmış. Dead-letter'daki hata metinleri okunamaz hâle gelir. |

---

## Yol Haritası

- [ ] İş öncelikleri (`priority` sütunu + indeks sırası)
- [ ] Zamanlanmış tekrarlayan işler (cron ifadeleriyle)
- [ ] Toplu iş ekleme (`INSERT … VALUES (…), (…)` tek sorguda)
- [ ] `completed_jobs` arşiv tablosu ve saklama politikası
- [ ] Worker havuzu izleme: hangi worker kaç iş işledi, ortalama süre
- [ ] İş zincirleme (bir iş bitince diğerini kuyruğa al)
- [ ] Web arayüzü için kullanıcı girişi ve rol tabanlı yetkilendirme
- [ ] PHPUnit testleri (`backoff_seconds`, `reserve_job`, `fail_job`)

---

## Katkı

**Bu proje herkese açıktır — dilediğiniz geliştirmeyle katkı sağlayabilirsiniz.**

📦 **Depo:** [github.com/CilginYazilim/PHP-MySQL-Job-Queue-Is-Kuyrugu-PDO-Skip-Locked-Worker](https://github.com/CilginYazilim/PHP-MySQL-Job-Queue-Is-Kuyrugu-PDO-Skip-Locked-Worker)

| Nasıl katkı sağlarım? | Nereden |
|----------------------|---------|
| 🐛 Hata bildir | [Issues](https://github.com/CilginYazilim/PHP-MySQL-Job-Queue-Is-Kuyrugu-PDO-Skip-Locked-Worker/issues) |
| 💡 Özellik öner | [Issues](https://github.com/CilginYazilim/PHP-MySQL-Job-Queue-Is-Kuyrugu-PDO-Skip-Locked-Worker/issues) |
| 🔧 Kod gönder | [Pull Requests](https://github.com/CilginYazilim/PHP-MySQL-Job-Queue-Is-Kuyrugu-PDO-Skip-Locked-Worker/pulls) |
| ❓ Soru sor | [Discussions](https://github.com/CilginYazilim/PHP-MySQL-Job-Queue-Is-Kuyrugu-PDO-Skip-Locked-Worker/discussions) |

### Katkı ölçütleri

- **Kod açıklamalı olsun.** Bu projenin temel amacı öğretmek; yorumsuz kod PR'ı geri döner.
- **Rezervasyonu tek bir atomik adımda tutun.** "Önce SELECT, sonra UPDATE" hâline döndüren PR kabul edilmez — projenin tezi budur.
- **Yedek yolu kaldırmayın.** Eski MariaDB hâlâ yaygın; kod sürüme değil davranışa bakmalıdır.
- **Zaman karşılaştırmalarını SQL'de yapın.** PHP `time()` ile MySQL `NOW()` aynı saati göstermeyebilir.
- **Tasarım değişikliklerini** `style.css` üzerinden yapın; `cilginyazilim.css` markaya aittir ve diğer projelerle **ortaktır**.

---

## Lisans

[MIT](LICENSE) — ticari kullanım dahil serbesttir.

<div align="center">

### Önce bir deneyin

<a href="https://cilginyazilim.com/kutuphane/uygulama/PHP-MySQL-Job-Queue-Is-Kuyrugu-PDO-Skip-Locked-Worker-main/"><img src="https://img.shields.io/badge/CANLI_DEMOYU_A%C3%87-0b5cb5?style=for-the-badge&logo=googlechrome&logoColor=white&labelColor=061321" alt="Canlı Demoyu Aç" height="42"></a>
<a href="https://cilginyazilim.com/kutuphane"><img src="https://img.shields.io/badge/D%C4%B0%C4%9EER_%C3%96RNEKLER-061321?style=for-the-badge&logo=bookstack&logoColor=white&labelColor=061321" alt="Diğer Örnekler" height="42"></a>

**[cilginyazilim.com](https://cilginyazilim.com)** tarafından ❤ ile geliştirildi

</div>
