<?php
/**
 * =====================================================================
 *  YAPILANDIRMA
 *  cilginyazilim.com – Veritabanı Tabanlı İş Kuyruğu
 * =====================================================================
 */

declare(strict_types=1);

/* ---------------------------------------------------------------------
 *  .env DESTEĞİ
 * ---------------------------------------------------------------------
 *  Veritabanı bilgileri bu dosyanın İÇİNDE durmak zorunda değil.
 *  Depo kökündeki ".env" dosyasına yazarsanız buradaki varsayılanlar
 *  devreye girmez — ve ".env" .gitignore içinde olduğu için parolanız
 *  depoya hiç girmez.
 *
 *  NEDEN AYRI BİR DOSYA?
 *  config.php DEPODA durur ve her dağıtımda depodaki sürümle
 *  DEĞİŞTİRİLİR; içine elle yazdığınız parola bir sonraki deploy'da
 *  silinir. .env ise deploy'un dokunmadığı bir dosyadır: bir kez
 *  oluşturursunuz, kalıcıdır.
 *
 *  DEĞER ARAMA SIRASI
 *      1. config.local.php içinde define() edilmişse o kazanır
 *         (bu dosyada varsa; aşağıdaki "! defined()" kontrolleri)
 *      2. .env dosyası
 *      3. Sunucunun gerçek ortam değişkeni (Apache SetEnv, systemd…)
 *      4. Bu dosyadaki varsayılan
 *
 *  cy_env() bilerek getenv() ile AYNI şeyi döndürür (değer ya da
 *  false). Böylece aşağıdaki satırlar olduğu gibi çalışmaya devam
 *  eder; "?:" ve "!== false" kalıplarının hiçbiri değişmedi.
 * ------------------------------------------------------------------ */
if (! function_exists('cy_env')) {
    /**
     * .env dosyasından (yoksa ortamdan) bir değer okur.
     *
     * @return string|false Değer yoksa false — getenv() ile aynı sözleşme.
     */
    function cy_env(string $key): string|false
    {
        static $env = null;

        if ($env === null) {
            $env  = [];
            $file = dirname(__DIR__) . '/.env';

            if (is_file($file) && is_readable($file)) {
                /* IGNORE_NEW_LINES + SKIP_EMPTY_LINES: satır sonlarını ve
                 * boş satırları baştan eler; ayrıştırma sadeleşir. */
                $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

                foreach ($lines as $line) {
                    $line = trim($line);

                    // Yorum satırı ya da "=" içermeyen satır atlanır.
                    if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
                        continue;
                    }

                    [$name, $value] = explode('=', $line, 2);

                    $name  = trim($name);
                    $value = trim($value);

                    /* Tırnak içindeki değerlerden tırnakları at:
                     * DB_PASS="a b c" → a b c
                     * Tırnak zorunlu değildir; yalnızca boşluk içeren
                     * parolalar için gerekir. */
                    if (strlen($value) >= 2
                        && ($value[0] === '"' || $value[0] === "'")
                        && $value[strlen($value) - 1] === $value[0]
                    ) {
                        $value = substr($value, 1, -1);
                    }

                    if ($name !== '') {
                        $env[$name] = $value;
                    }
                }
            }
        }

        // .env'de varsa o; yoksa sunucunun gerçek ortam değişkeni.
        return $env[$key] ?? getenv($key);
    }
}

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
    define('DB_HOST', cy_env('DB_HOST') ?: '127.0.0.1');
}
if (! defined('DB_NAME')) {
    define('DB_NAME', cy_env('DB_NAME') ?: 'cy_queue');
}
if (! defined('DB_USER')) {
    define('DB_USER', cy_env('DB_USER') ?: 'root');
}
if (! defined('DB_PASS')) {
    define('DB_PASS', cy_env('DB_PASS') !== false ? (string) cy_env('DB_PASS') : '');
}

// utf8mb4: Türkçe karakterler ve emoji dahil tüm Unicode'u destekler.
define('DB_CHARSET', 'utf8mb4');

/* ---------------------------------------------------------------------
 *  ZAMAN DİLİMİ
 * ---------------------------------------------------------------------
 *  ÖLÇÜLEN SORUN: php.ini'de date.timezone çoğu XAMPP kurulumunda
 *  sunucunun coğrafi diliminden farklıdır. Bu makinede PHP
 *  "Europe/Berlin", MySQL ise sistem dilimi (Europe/Istanbul)
 *  kullanıyordu; aynı anı anlatan iki satır BİR SAAT farklı görünüyordu:
 *
 *      worker günlüğü (PHP date)  : 14:03:17
 *      veritabanı  (MySQL NOW())  : 15:03:17
 *
 *  Bu depodaki zaman ARİTMETİĞİ bilinçli olarak SQL tarafında yapılır
 *  (NOW(), INTERVAL, TIMESTAMPDIFF), bu yüzden hesaplar zaten doğrudur.
 *  Kayan şey, PHP'nin ekrana/günlüğe bastığı saatti — ve demoyu
 *  deneyen biri için bu, "sistem yanlış çalışıyor" gibi görünür.
 *
 *  Çözüm: dilimi ORTAMA bırakmak yerine açıkça sabitliyoruz. Kendi
 *  sunucunuzda farklı bir dilim istiyorsanız APP_TIMEZONE ortam
 *  değişkenini tanımlamanız yeterlidir; kod değiştirmenize gerek yok.
 * ------------------------------------------------------------------ */
define('APP_TIMEZONE', cy_env('APP_TIMEZONE') ?: 'Europe/Istanbul');

// @ kullanmıyoruz: geçersiz bir dilim adı sessizce yutulmamalı.
if (in_array(APP_TIMEZONE, timezone_identifiers_list(), true)) {
    date_default_timezone_set(APP_TIMEZONE);
}

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

$cyDebugEnv = cy_env('APP_DEBUG');
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
