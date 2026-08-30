<?php
/**
 * =====================================================================
 *  YAPILANDIRMA
 *  cilginyazilim.com – Veritabanı Tabanlı İş Kuyruğu
 * =====================================================================
 */

declare(strict_types=1);

if (!defined('CY_APP')) {
    http_response_code(403);
    exit;
}

/* Worker CLI'dan çalışır ve orada oturum diye bir şey yoktur;
 * session_start() çağırmak yalnızca uyarı üretirdi. */
if (session_status() === PHP_SESSION_NONE && PHP_SAPI !== 'cli') {
    ini_set('session.use_strict_mode', '1');
    session_set_cookie_params([
        'lifetime' => 0, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax',
        'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
    ]);
    session_start();
}

/* ---------------------------------------------------------------------
 *  SUNUCUYA ÖZEL AYARLAR — system/config.local.php
 * ---------------------------------------------------------------------
 *  Canlı veritabanı adı/kullanıcı/parola BU DOSYAYA DEĞİL, yanındaki
 *  config.local.php dosyasına yazılır. Nedeni iki katmanlı:
 *
 *    1) config.php depoda durur; parolayı buraya yazmak onu GitHub'a taşır.
 *    2) config.php her dağıtımda depodaki sürümle DEĞİŞTİRİLİR — elle
 *       yapılan düzenleme bir sonraki deploy'da silinir.
 *
 *  Bu projede üçüncü bir sebep daha var: worker AYRI bir süreçtir ve
 *  aynı yapılandırmayı okur. Künyeyi iki yerde tutmak, birini
 *  güncelleyip diğerini unutmanın kesin yoludur.
 * ------------------------------------------------------------------ */
$yerelAyar = __DIR__ . '/config.local.php';

if (is_file($yerelAyar)) {
    require_once $yerelAyar;
}

/* Aşağıdakiler yalnızca config.local.php (ya da ortam değişkeni) değer
 * VERMEDİYSE devreye girer; yerel XAMPP kurulumuna göre varsayılanlardır. */
if (! defined('DB_HOST')) {
    define('DB_HOST', getenv('DB_HOST') ?: '127.0.0.1');
}
if (! defined('DB_NAME')) {
    define('DB_NAME', getenv('DB_NAME') ?: 'cy_queue');
}
if (! defined('DB_USER')) {
    define('DB_USER', getenv('DB_USER') ?: 'root');
}
if (! defined('DB_PASS')) {
    define('DB_PASS', getenv('DB_PASS') !== false ? (string) getenv('DB_PASS') : '');
}

// utf8mb4: Türkçe karakterler ve emoji dahil tüm Unicode'u destekler.
define('DB_CHARSET', 'utf8mb4');

/* ---------------------------------------------------------------------
 *  HATA AYIKLAMA — ORTAMA GÖRE OTOMATİK
 * ---------------------------------------------------------------------
 *  Bu depo hem yerel makinede (XAMPP) hem de canlı demo sunucusunda
 *  aynı dosyalarla çalışır. APP_DEBUG'ı sabit `true` bırakmak, canlıya
 *  alındığında MySQL hata metinlerini ziyaretçinin ekranına basardı;
 *  sabit `false` bırakmak ise yerelde kurulum hatasını görünmez yapardı.
 *
 *  Çözüm: varsayılan değeri SUNUCU ADINDAN türetiyoruz. CLI'da (worker)
 *  her zaman `true` sayılır — orada çıktıyı yalnızca yönetici görür ve
 *  hata ayrıntısı tam olarak orada gereklidir.
 * ------------------------------------------------------------------ */
function cy_is_local_host(): bool
{
    $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? ''));
    $host = (string) preg_replace('/:\d+$/', '', $host);

    if ($host === '' || PHP_SAPI === 'cli') {
        return true;
    }

    return in_array($host, ['localhost', '127.0.0.1', '::1', '[::1]'], true)
        || str_ends_with($host, '.local')
        || str_ends_with($host, '.test')
        || str_ends_with($host, '.localhost');
}

$cyDebugEnv = getenv('APP_DEBUG');
define('APP_DEBUG', $cyDebugEnv !== false
    ? in_array(strtolower((string) $cyDebugEnv), ['1', 'true', 'on', 'yes'], true)
    : cy_is_local_host());

error_reporting(APP_DEBUG ? E_ALL : 0);
ini_set('display_errors', APP_DEBUG ? '1' : '0');

/* =====================================================================
 *  KUYRUK AYARLARI
 * ---------------------------------------------------------------------
 *  JOB_MAX_ATTEMPTS : Bir iş kaç kez denenir. Aşılırsa iş failed_jobs'a
 *    ("dead letter") taşınır — sonsuza dek dönüp durmaz. Sonsuz deneme,
 *    kalıcı bir hatayı sonsuz bir maliyete çevirir.
 *
 *  BACKOFF_BASE / BACKOFF_CAP : Üstel geri çekilme.
 *    bekleme = min(BASE * 2^(deneme-1), CAP)  saniye
 *    1. hata → 10 sn, 2. → 20 sn, 3. → 40 sn … (CAP'e kadar)
 *
 *    NEDEN ÜSTEL: sabit aralıkla yeniden denemek, geçici olarak çökmüş
 *    bir servise saniyede bir vurmak demektir — toparlanmasını
 *    engellersiniz. Üstel geri çekilme baskıyı kendiliğinden azaltır.
 *
 *  RESERVE_TTL : GÖRÜNÜRLÜK ZAMAN AŞIMI. Bir worker işi rezerve edip
 *    çökerse (elektrik, OOM, deploy), iş bu süre sonra otomatik serbest
 *    kalır. Bu sütun olmadan çöken her worker, aldığı işi sonsuza dek
 *    "işleniyor" durumunda bırakırdı.
 *
 *  WORKER_SLEEP : Kuyruk boşken worker'ın uyuduğu saniye. Sıfır olsaydı
 *    boş kuyruk %100 CPU tüketirdi.
 * ================================================================== */
define('JOB_MAX_ATTEMPTS', 3);
define('BACKOFF_BASE', 10);
define('BACKOFF_CAP', 600);
define('RESERVE_TTL', 90);
define('WORKER_SLEEP', 3);

/* Arayüzden eklenebilecek iş türleri. Beyaz listedir: istemciden gelen
 * `type` değeri yalnızca bu dizide olabilir. */
define('KNOWN_JOBS', ['send_email', 'resize_image', 'generate_report', 'flaky_task']);

/* Kuyruk adları da beyaz listeden geçer — `queue` bir SQL koşuluna
 * girer ve serbest metin olmamalıdır. */
define('KNOWN_QUEUES', ['default', 'mails']);
define('DEFAULT_QUEUE', 'default');

/* Arayüzden tek seferde eklenebilecek en fazla iş. */
define('ENQUEUE_MAX_COUNT', 25);

/* [istek sayısı, saniye] — kovalar AYRIDIR:
 *   enqueue : veritabanına yazar
 *   work    : bir işi GERÇEKTEN çalıştırır (en pahalısı)
 *   status  : yalnızca okur ama otomatik tazeleme yüzünden en sık çağrılanıdır */
define('RATE_LIMIT_ENQUEUE', [60, 60]);
define('RATE_LIMIT_WORK', [120, 60]);
define('RATE_LIMIT_STATUS', [240, 60]);

try {
    $db = new PDO(
        sprintf('mysql:host=%s;dbname=%s;charset=%s', DB_HOST, DB_NAME, DB_CHARSET),
        DB_USER, DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
} catch (PDOException $e) {
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, 'DB hatası: ' . $e->getMessage() . "\nKurulum:  mysql -u root -p < cy_queue.sql\n");
        exit(1);
    }
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo APP_DEBUG
        ? 'Veritabanı bağlantı hatası: ' . $e->getMessage() . "\n\nKurulum:  mysql -u root -p < cy_queue.sql"
        : 'Veritabanına bağlanılamadı.';
    exit;
}
