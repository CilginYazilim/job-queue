-- =====================================================================
--  cilginyazilim.com – Veritabanı Tabanlı İş Kuyruğu
--    mysql -u root -p < cy_queue.sql
--  NOT: reserve_job() FOR UPDATE SKIP LOCKED kullanır →
--       MySQL 8.0+ veya MariaDB 10.6+ önerilir. Desteklenmeyen
--       sürümlerde kod otomatik olarak koşullu UPDATE yoluna düşer.
-- =====================================================================

-- ---------------------------------------------------------------------
--  ÖNEMLİ: İSTEMCİ KARAKTER SETİ
-- ---------------------------------------------------------------------
--  Bu satır olmadan `mysql -u root -p < dosya.sql` komutu, İSTEMCİNİN
--  varsayılan karakter setini kullanır. Windows'ta bu genellikle latin1
--  ya da cp1254'tür; dosyadaki UTF-8 baytları latin1 sanılıp yeniden
--  kodlanır ve veri ÇİFT KODLANMIŞ (mojibake) olarak girer:
--
--      "başarısız"  →  "baÅŸarÄ±sÄ±z"
--
--  Bu projede sonuç özellikle can sıkıcıdır: hata metinleri (exception)
--  okunamaz hâle gelir ve dead-letter kuyruğu, tam da okunmak için var
--  olan bilgiyi kaybeder.
-- ---------------------------------------------------------------------
SET NAMES utf8mb4;

CREATE DATABASE IF NOT EXISTS `cy_queue`
  DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `cy_queue`;

DROP TABLE IF EXISTS `failed_jobs`;
DROP TABLE IF EXISTS `jobs`;


-- =====================================================================
--  jobs — BEKLEYEN KUYRUK
-- ---------------------------------------------------------------------
--  DÖRT SÜTUN BÜTÜN MEKANİZMAYI TAŞIR:
--
--    available_at : bu andan ÖNCE işlenmez. Hem gecikmeli iş ("yarın
--                   gönder") hem üstel geri çekilme (backoff) bu tek
--                   sütunla ifade edilir — ayrı bir "retry_at" gerekmez.
--
--    reserved_at  : bir worker işi kilitledi. NULL ise iş serbesttir.
--                   RESERVE_TTL sonra bayatlar: worker çökerse iş
--                   sonsuza dek "işleniyor" durumunda kalmaz.
--
--    reserved_by  : hangi worker aldı (hostname:pid). Yedek rezervasyon
--                   yolunda benzersiz bir damga da taşır; "benim
--                   satırımı geri oku" sorgusunu mümkün kılan şey odur.
--
--    attempts     : kaçıncı deneme. Rezervasyon anında artar — çöken
--                   bir worker'ın işi de bir denemeyi tüketmiş sayılır,
--                   yoksa sonsuza dek dönerdi.
--
--  `idx_jobs_pick` (queue, available_at, reserved_at): worker'ın "sırası
--  gelmiş, rezerve edilmemiş ilk iş" sorgusunun dayandığı indekstir.
--  Sütun SIRASI önemlidir: önce eşitlik (queue), sonra aralık
--  (available_at). Ters sırada indeks aralık taramasından sonra
--  kullanılamaz hâle gelir.
--
--  `queue` sütunu bir süs değildir: farklı iş türlerini farklı
--  worker'lara dağıtmanın standart yoludur. Uzun süren rapor işleri
--  "reports" kuyruğunda beklerken, e-posta kuyruğu tıkanmaz.
-- =====================================================================
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


-- =====================================================================
--  failed_jobs — DEAD LETTER (deneme hakkı biten işler)
-- ---------------------------------------------------------------------
--  NEDEN AYRI TABLO: `jobs` tablosu worker'ın her turda taradığı
--  tablodur ve KÜÇÜK kalmalıdır. Başarısız işleri orada bir `status`
--  sütunuyla tutmak, kuyruğu zamanla asla işlenmeyecek satırlarla
--  şişirir ve her rezervasyon sorgusunu yavaşlatır.
--
--  İkinci sebep: başarısız iş artık bir KUYRUK KAYDI değil, bir
--  İNCELEME KAYDIDIR. Farklı bir yaşam döngüsü vardır — insan bakar,
--  düzeltir, yeniden kuyruğa alır ya da siler.
-- =====================================================================
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


-- =====================================================================
--  ÖRNEK VERİ
-- ---------------------------------------------------------------------
--  Kuyruk BOŞ AÇILMAMALIDIR: ziyaretçi "worker'ı tetikle" düğmesine
--  bastığında bir şey olmalı, ve daha ilk bakışta bir kuyruğun hangi
--  DURUMLARI barındırdığı görünmelidir.
--
--  Örnek küme bütün durumları temsil eder:
--
--    · HAZIR       — available_at geçmişte, reserved_at NULL
--    · GECİKMELİ   — available_at gelecekte ("30 sn sonra")
--    · BACKOFF'TA  — bir kez patlamış, attempts=1, available_at ileri
--                    atılmış ve last_error dolu
--    · REZERVE     — bir worker almış, hâlâ işliyor
--    · BAYAT REZERVE — worker çökmüş; reserved_at RESERVE_TTL'den
--                    eski, iş otomatik geri alınacak (görünürlük
--                    zaman aşımı bu satırda somutlaşır)
--    · İKİNCİ KUYRUK — 'mails' kuyruğunda bekleyen işler; `queue`
--                    sütununun neye yaradığı ancak böyle görünür
--
--  Zamanlar `NOW() ± INTERVAL` ile üretilir. Sabit tarih yazsaydık,
--  dosya bir hafta sonra kurulduğunda bütün "gecikmeli" işler çoktan
--  vadesi gelmiş görünür ve demo o durumu hiç göstermezdi.
-- =====================================================================

INSERT INTO `jobs` (`queue`, `type`, `payload`, `attempts`, `available_at`, `reserved_at`, `reserved_by`, `last_error`, `created_at`) VALUES

-- ---- HAZIR: sırası gelmiş, hemen işlenebilir ----
('default', 'send_email',   JSON_OBJECT('to', 'ayse@ornek.com',  'subject', 'Hoş geldiniz'),
 0, NOW() - INTERVAL 4 MINUTE, NULL, NULL, NULL, NOW() - INTERVAL 4 MINUTE),

('default', 'resize_image', JSON_OBJECT('file', 'kapak-2048.jpg', 'sizes', JSON_ARRAY(200, 400, 800)),
 0, NOW() - INTERVAL 3 MINUTE, NULL, NULL, NULL, NOW() - INTERVAL 3 MINUTE),

('default', 'flaky_task',   JSON_OBJECT('ref', 'demo-01'),
 0, NOW() - INTERVAL 2 MINUTE, NULL, NULL, NULL, NOW() - INTERVAL 2 MINUTE),

('default', 'send_email',   JSON_OBJECT('to', 'mehmet@ornek.com', 'subject', 'Sipariş onayı'),
 0, NOW() - INTERVAL 1 MINUTE, NULL, NULL, NULL, NOW() - INTERVAL 1 MINUTE),

-- ---- BACKOFF'TA: bir kez patlamış, geri çekilme süresi işliyor ----
('default', 'flaky_task',   JSON_OBJECT('ref', 'demo-02'),
 1, NOW() + INTERVAL 8 SECOND, NULL, NULL,
 'RuntimeException: Geçici bir aksaklık (flaky_task bilerek patladı).', NOW() - INTERVAL 6 MINUTE),

-- ---- BACKOFF'TA (ikinci deneme): son şansı kaldı ----
('default', 'flaky_task',   JSON_OBJECT('ref', 'demo-03'),
 2, NOW() + INTERVAL 25 SECOND, NULL, NULL,
 'RuntimeException: Geçici bir aksaklık (flaky_task bilerek patladı).', NOW() - INTERVAL 9 MINUTE),

-- ---- GECİKMELİ: bilinçli olarak ileri tarihlenmiş ----
('default', 'send_email',   JSON_OBJECT('to', 'bulten@ornek.com', 'subject', 'Haftalık bülten'),
 0, NOW() + INTERVAL 45 SECOND, NULL, NULL, NULL, NOW() - INTERVAL 30 SECOND),

('default', 'generate_report', JSON_OBJECT('report', 'aylik-satis', 'format', 'pdf'),
 0, NOW() + INTERVAL 90 SECOND, NULL, NULL, NULL, NOW() - INTERVAL 20 SECOND),

-- ---- REZERVE: bir worker şu anda işliyor ----
('default', 'resize_image', JSON_OBJECT('file', 'urun-4096.png', 'sizes', JSON_ARRAY(200, 400)),
 1, NOW() - INTERVAL 10 SECOND, NOW() - INTERVAL 8 SECOND, 'worker-01:4312', NULL, NOW() - INTERVAL 2 MINUTE),

-- ---- BAYAT REZERVE: worker çökmüş, RESERVE_TTL geçmiş ----
--      Bu satır demonun en öğretici kaydıdır: iş "işleniyor" görünür
--      ama kimse işlemiyordur. Görünürlük zaman aşımı devreye girer ve
--      bir sonraki tetiklemede iş yeniden alınır.
('default', 'generate_report', JSON_OBJECT('report', 'stok-durumu', 'format', 'xlsx'),
 1, NOW() - INTERVAL 5 MINUTE, NOW() - INTERVAL 4 MINUTE, 'worker-02:8891', NULL, NOW() - INTERVAL 12 MINUTE),

-- ---- İKİNCİ KUYRUK: 'mails' ----
--      Ayrı kuyruk, ayrı worker demektir. Uzun süren rapor işleri
--      e-posta gönderimini bekletmesin diye ayrılır.
('mails', 'send_email', JSON_OBJECT('to', 'kampanya1@ornek.com', 'subject', 'Kampanya'),
 0, NOW() - INTERVAL 2 MINUTE, NULL, NULL, NULL, NOW() - INTERVAL 2 MINUTE),

('mails', 'send_email', JSON_OBJECT('to', 'kampanya2@ornek.com', 'subject', 'Kampanya'),
 0, NOW() - INTERVAL 2 MINUTE, NULL, NULL, NULL, NOW() - INTERVAL 2 MINUTE),

('mails', 'send_email', JSON_OBJECT('to', 'kampanya3@ornek.com', 'subject', 'Kampanya'),
 0, NOW() + INTERVAL 20 SECOND, NULL, NULL, NULL, NOW() - INTERVAL 1 MINUTE);


-- ---------------------------------------------------------------------
--  DEAD LETTER — deneme hakkı bitmiş işler
-- ---------------------------------------------------------------------
--  Farklı hata türleri bilinçli olarak karışıktır: biri geçici bir
--  aksaklık (yeniden denemek mantıklı), biri kalıcı bir veri hatası
--  (yeniden denemek aynı sonucu verir), biri de bilinmeyen bir iş türü
--  (kodda o handler yok). Bu ayrım, "yeniden kuyruğa al" düğmesine
--  basmadan önce hata metnini OKUMAK gerektiğini gösterir.
-- ---------------------------------------------------------------------
INSERT INTO `failed_jobs` (`queue`, `type`, `payload`, `attempts`, `exception`, `failed_at`) VALUES
('default', 'flaky_task', JSON_OBJECT('ref', 'eski-07'), 3,
 'RuntimeException: Geçici bir aksaklık (flaky_task bilerek patladı).',
 NOW() - INTERVAL 26 MINUTE),

('default', 'send_email', JSON_OBJECT('to', 'gecersiz-adres', 'subject', 'Fatura'), 3,
 'InvalidArgumentException: Geçersiz e-posta adresi: gecersiz-adres',
 NOW() - INTERVAL 3 HOUR),

('default', 'resize_image', JSON_OBJECT('file', 'bulunamayan.jpg'), 3,
 'RuntimeException: Kaynak dosya bulunamadı: bulunamayan.jpg',
 NOW() - INTERVAL 8 HOUR),

('mails', 'send_newsletter', JSON_OBJECT('list', 'haftalik', 'size', 12500), 3,
 'RuntimeException: Bilinmeyen iş türü: send_newsletter',
 NOW() - INTERVAL 1 DAY);

-- ---------------------------------------------------------------------
--  AUTO_INCREMENT'i ileri alıyoruz.
--  İş numarası paylaşılabilir bir bağlantıdır (#is-42); silinmiş bir
--  numarayı yeni işe vermek, eski bağlantıyı BAŞKA bir işe götürür.
--  Kuyrukta bu özellikle önemlidir: tamamlanan iş satırı SİLİNİR, yani
--  numaralar sürekli boşalır.
-- ---------------------------------------------------------------------
ALTER TABLE `jobs` AUTO_INCREMENT = 120;
ALTER TABLE `failed_jobs` AUTO_INCREMENT = 40;
