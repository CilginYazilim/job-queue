<?php
/**
 * =====================================================================
 *  YARDIMCI FONKSİYONLAR
 *  cilginyazilim.com – Veritabanı Tabanlı İş Kuyruğu
 * ---------------------------------------------------------------------
 *  BÖLÜM 1  Çıktı / JSON
 *  BÖLÜM 2  CSRF
 *  BÖLÜM 3  Hız sınırı
 *  BÖLÜM 4  KUYRUK MOTORU: enqueue / reserve / complete / release / fail
 *  BÖLÜM 5  İŞ İŞLEYİCİLERİ (handlers)
 * =====================================================================
 */

declare(strict_types=1);

if (!defined('CY_APP')) {
    http_response_code(403);
    exit;
}

/* ===== BÖLÜM 1 – ÇIKTI / JSON ===================================== */

function e(?string $v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function json_response(array $p, int $s = 200): void
{
    if (!headers_sent()) {
        http_response_code($s);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
    }
    /* JSON_INVALID_UTF8_SUBSTITUTE: yanıt, işlerin payload'ını ve hata
     * metinlerini taşır. Bir exception mesajı dış bir servisten gelen
     * bozuk baytı içerebilir; bu bayrak olmadan json_encode() sessizce
     * `false` döner ve arayüz "sunucu hatası" der. Kuyruğun tamamı,
     * tek bir işin hata metnindeki bir bayt yüzünden görünmez olmamalı. */
    echo json_encode($p, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function json_success(string $d, array $x = []): void
{
    json_response(array_merge(['success' => true, 'type' => 'success', 'description' => $d], $x));
}

function json_error(string $d, int $s = 400, array $x = []): void
{
    json_response(array_merge(['success' => false, 'type' => 'danger', 'description' => $d], $x), $s);
}

/* ===== BÖLÜM 2 – CSRF =========================================== */

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function require_csrf(): void
{
    $t = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!is_string($t) || $t === '' || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $t)) {
        json_error('Oturum doğrulaması başarısız. Sayfayı yenileyin.', 403);
    }
}

/* ===== BÖLÜM 3 – HIZ SINIRI ===================================== */

function rate_limit_dir(): string
{
    $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cy_queue_rate';
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    return $dir;
}

function client_ip(): string
{
    return (string) ($_SERVER['REMOTE_ADDR'] ?? 'bilinmiyor');
}

function rate_limit(string $bucket, int $limit, int $window): void
{
    $file = rate_limit_dir() . DIRECTORY_SEPARATOR . sha1($bucket . '|' . client_ip()) . '.json';
    $h = @fopen($file, 'c+');
    if ($h === false) {
        return;
    }
    flock($h, LOCK_EX);
    $now  = microtime(true);
    $hits = json_decode((string) stream_get_contents($h), true);
    $hits = is_array($hits) ? $hits : [];
    $hits = array_values(array_filter($hits, static fn($t) => is_numeric($t) && ($now - (float) $t) < $window));
    if (count($hits) >= $limit) {
        $retry = max(1, (int) ceil($window - ($now - (float) $hits[0])));
        flock($h, LOCK_UN);
        fclose($h);
        json_error("Çok fazla istek. {$retry} saniye sonra tekrar deneyin.", 429, ['retry_after' => $retry]);
    }
    $hits[] = $now;
    ftruncate($h, 0);
    rewind($h);
    fwrite($h, json_encode($hits));
    fflush($h);
    flock($h, LOCK_UN);
    fclose($h);
}

/* =====================================================================
 *  BÖLÜM 4 – KUYRUK MOTORU
 * ---------------------------------------------------------------------
 *  Bir işin yaşam döngüsü:
 *
 *   enqueue ──► jobs (available_at = NOW + delay, reserved_at = NULL)
 *                  │
 *   reserve  ◄─────┘  worker: FOR UPDATE SKIP LOCKED ile TEK satırı kilitle,
 *                     reserved_at = NOW, reserved_by = <worker>, attempts++
 *                  │
 *          ┌───────┴────────┐
 *     başarı              hata
 *          │                │
 *     complete()       attempts < MAX ?
 *     (satır silinir)   ├─ evet → release(): reserved_at = NULL,
 *                       │          available_at = NOW + backoff(attempts)
 *                       └─ hayır → fail(): failed_jobs'a taşı, jobs'tan sil
 *
 *  FOR UPDATE SKIP LOCKED (MySQL 8.0+ / MariaDB 10.6+): iki worker aynı
 *  anda çalışsa bile aynı işi İKİ KEZ almaz — kilitli satırı atlar.
 *  Eski sürümde: kısa bir transaction içinde "UPDATE ... WHERE id =
 *  (SELECT ...)" kalıbına düşülür (yarış penceresi küçük ama sıfır değil).
 * ================================================================== */

function backoff_seconds(int $attempts): int
{
    return (int) min(BACKOFF_BASE * (2 ** max(0, $attempts - 1)), BACKOFF_CAP);
}

/** Yeni iş ekle. $delay saniye sonra işlenebilir olur. */
function enqueue(PDO $db, string $type, array $payload, string $queue = 'default', int $delay = 0): int
{
    $stmt = $db->prepare(
        'INSERT INTO jobs (queue, type, payload, available_at)
         VALUES (:q, :t, :p, (NOW() + INTERVAL :d SECOND))'
    );
    $stmt->bindValue(':q', $queue);
    $stmt->bindValue(':t', $type);
    $stmt->bindValue(':p', json_encode($payload, JSON_UNESCAPED_UNICODE));
    $stmt->bindValue(':d', max(0, $delay), PDO::PARAM_INT);
    $stmt->execute();
    return (int) $db->lastInsertId();
}

/* ---------------------------------------------------------------------
 *  SUNUCU YETENEK TESPİTİ
 * ---------------------------------------------------------------------
 *  SKIP LOCKED şu sürümlerde vardır:  MySQL 8.0+  ·  MariaDB 10.6+
 *  XAMPP uzun süre MariaDB 10.4 ile dağıtıldı; orada bu sözdizimi
 *  1064 (sözdizimi hatası) verir.
 *
 *  Sürüm dizgisini AYRIŞTIRMIYORUZ ("10.4.32-MariaDB" gibi bir metni
 *  parçalamak kırılgandır: dağıtımlar kendi eklerini koyar). Onun
 *  yerine sorguyu BİR KEZ deniyoruz ve sonucu hatırlıyoruz. Bu,
 *  "sürüme değil DAVRANIŞA bak" ilkesidir.
 *
 *  Sonuç static bir değişkende tutulur: istek başına tek yoklama.
 * ------------------------------------------------------------------ */
function supports_skip_locked(PDO $db): bool
{
    static $supported = null;

    if ($supported !== null) {
        return $supported;
    }

    try {
        /* Zararsız bir yoklama: LIMIT 0 hiçbir satır kilitlemez, ama
         * sözdizimi desteklenmiyorsa sunucu yine de hata verir.
         * Transaction içinde çalıştırıyoruz çünkü FOR UPDATE otomatik
         * commit modunda anlamsızdır. */
        $db->beginTransaction();
        $db->query('SELECT id FROM jobs LIMIT 0 FOR UPDATE SKIP LOCKED');
        $db->commit();

        $supported = true;
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $supported = false;
    }

    return $supported;
}

/**
 * İşlenebilir TEK işi rezerve et ve döndür. Yoksa null.
 *
 * İki yol vardır ve ikisi de AYNI sonucu üretir; fark yalnızca
 * yarış penceresinin genişliğindedir (bkz. aşağıdaki açıklamalar).
 *
 * @return array<string,mixed>|null
 */
function reserve_job(PDO $db, string $workerId, string $queue = 'default'): ?array
{
    return supports_skip_locked($db)
        ? reserve_job_skip_locked($db, $workerId, $queue)
        : reserve_job_fallback($db, $workerId, $queue);
}

/**
 * TERCİH EDİLEN YOL — MySQL 8.0+ / MariaDB 10.6+
 *
 * SELECT ... FOR UPDATE SKIP LOCKED satırı OKURKEN kilitler ve başka
 * bir worker'ın kilitlediği satırı BEKLEMEDEN atlar. Yarış penceresi
 * SIFIRDIR: iki worker aynı işi asla alamaz.
 *
 * @return array<string,mixed>|null
 */
function reserve_job_skip_locked(PDO $db, string $workerId, string $queue): ?array
{
    $db->beginTransaction();
    try {
        // 1) Aday: sırası gelmiş VE (rezerve değil VEYA rezervesi bayatlamış)
        $sel = $db->prepare(
            'SELECT id FROM jobs
             WHERE queue = :q
               AND available_at <= NOW()
               AND (reserved_at IS NULL OR reserved_at < (NOW() - INTERVAL :ttl SECOND))
             ORDER BY id ASC
             LIMIT 1
             FOR UPDATE SKIP LOCKED'
        );
        $sel->bindValue(':q', $queue);
        $sel->bindValue(':ttl', RESERVE_TTL, PDO::PARAM_INT);
        $sel->execute();
        $id = $sel->fetchColumn();

        if ($id === false) {
            $db->commit();
            return null;
        }

        // 2) Rezerve et. Satır hâlâ kilitli olduğu için araya kimse giremez.
        $db->prepare(
            'UPDATE jobs
             SET reserved_at = NOW(), reserved_by = :w, attempts = attempts + 1
             WHERE id = :id'
        )->execute([':w' => $workerId, ':id' => $id]);

        $row = $db->prepare('SELECT * FROM jobs WHERE id = :id');
        $row->execute([':id' => $id]);
        $job = $row->fetch();

        $db->commit();
        return $job ?: null;
    } catch (Throwable $ex) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $ex;
    }
}

/**
 * YEDEK YOL — SKIP LOCKED desteklenmeyen sürümler (ör. MariaDB 10.4)
 *
 * Kilit yerine KOŞULLU GÜNCELLEME kullanılır. Numara şudur: rezervasyon
 * ile sahiplik iddiası TEK bir UPDATE içinde yapılır ve WHERE koşulu
 * "hâlâ rezerve edilmemiş" der. İki worker aynı anda çalıştırsa bile
 * satır kilidi nedeniyle biri diğerini bekler; ikinci UPDATE geldiğinde
 * WHERE koşulu ARTIK TUTMAZ ve rowCount() 0 döner.
 *
 *     UPDATE jobs SET reserved_by = <ben> WHERE reserved_at IS NULL …
 *     ↓ rowCount() === 1  → iş benim
 *     ↓ rowCount() === 0  → başkası kaptı, tekrar dene
 *
 * Sonra "benim damgamı taşıyan satırı" geri okuyoruz. reserved_by
 * benzersiz bir işaret taşır (hostname:pid + rastgele ek), böylece
 * başka bir worker'ın satırını okumamız imkânsızdır.
 *
 * ÖDÜNLEŞİM: ORDER BY + LIMIT ile UPDATE, MySQL'de replikasyon
 * uyarısı üretebilir ve çok sayıda worker'da satır kilidi beklemesi
 * paralelliği SKIP LOCKED kadar iyi ölçeklemez. Doğruluk açısından
 * güvenlidir; performans açısından ikinci tercihtir.
 *
 * @return array<string,mixed>|null
 */
function reserve_job_fallback(PDO $db, string $workerId, string $queue): ?array
{
    /* Bu turdaki rezervasyonu benzersiz kılan damga. Aynı worker
     * (aynı hostname:pid) art arda iş alabileceği için, sadece
     * $workerId ile geri okumak eski bir satırı getirebilirdi. */
    $stamp = $workerId . '#' . bin2hex(random_bytes(4));

    $upd = $db->prepare(
        'UPDATE jobs
         SET reserved_at = NOW(), reserved_by = :w, attempts = attempts + 1
         WHERE queue = :q
           AND available_at <= NOW()
           AND (reserved_at IS NULL OR reserved_at < (NOW() - INTERVAL :ttl SECOND))
         ORDER BY id ASC
         LIMIT 1'
    );
    $upd->bindValue(':w', $stamp);
    $upd->bindValue(':q', $queue);
    $upd->bindValue(':ttl', RESERVE_TTL, PDO::PARAM_INT);
    $upd->execute();

    // Hiçbir satır güncellenmediyse ya kuyruk boş ya da başkası kaptı.
    if ($upd->rowCount() === 0) {
        return null;
    }

    /* Damgamızı taşıyan satırı geri oku. reserved_by benzersiz olduğu
     * için bu sorgu KESİNLİKLE bizim rezerve ettiğimiz satırı döndürür. */
    $sel = $db->prepare('SELECT * FROM jobs WHERE reserved_by = :w LIMIT 1');
    $sel->execute([':w' => $stamp]);
    $job = $sel->fetch();

    return $job ?: null;
}

/** İş başarıyla bitti → satırı sil. */
function complete_job(PDO $db, int $id): void
{
    $db->prepare('DELETE FROM jobs WHERE id = :id')->execute([':id' => $id]);
}

/** İş hata verdi ama deneme hakkı var → geri bırak (backoff ile). */
function release_job(PDO $db, array $job, string $error): void
{
    $delay = backoff_seconds((int) $job['attempts']);
    $db->prepare(
        'UPDATE jobs
         SET reserved_at = NULL, reserved_by = NULL,
             available_at = (NOW() + INTERVAL :d SECOND),
             last_error = :e
         WHERE id = :id'
    )->execute([':d' => $delay, ':e' => mb_substr($error, 0, 1000), ':id' => $job['id']]);
}

/** Deneme hakkı bitti → failed_jobs'a taşı, jobs'tan sil. */
function fail_job(PDO $db, array $job, string $error): void
{
    $db->beginTransaction();
    try {
        $db->prepare(
            'INSERT INTO failed_jobs (queue, type, payload, attempts, exception)
             VALUES (:q, :t, :p, :a, :e)'
        )->execute([
            ':q' => $job['queue'],
            ':t' => $job['type'],
            ':p' => $job['payload'],
            ':a' => $job['attempts'],
            ':e' => mb_substr($error, 0, 5000),
        ]);
        $db->prepare('DELETE FROM jobs WHERE id = :id')->execute([':id' => $job['id']]);
        $db->commit();
    } catch (Throwable $ex) {
        $db->rollBack();
        throw $ex;
    }
}

/**
 * Kuyruktan bir iş çek ve çalıştır. Worker döngüsü ve arayüzdeki
 * "worker'ı bir kez tetikle" düğmesi ikisi de bunu çağırır.
 * @return array{status:string, job_id:?int, type:?string, message:string}
 */
function process_one(PDO $db, string $workerId, string $queue = 'default'): array
{
    $job = reserve_job($db, $workerId, $queue);
    if ($job === null) {
        return ['status' => 'empty', 'job_id' => null, 'type' => null, 'message' => 'İşlenecek iş yok.'];
    }

    $payload = json_decode((string) $job['payload'], true) ?: [];

    try {
        $result = run_job_handler((string) $job['type'], $payload);
        complete_job($db, (int) $job['id']);
        return ['status' => 'done', 'job_id' => (int) $job['id'], 'type' => $job['type'],
                'message' => "İş #{$job['id']} ({$job['type']}) tamamlandı: $result"];
    } catch (Throwable $ex) {
        $error = get_class($ex) . ': ' . $ex->getMessage();
        if ((int) $job['attempts'] >= JOB_MAX_ATTEMPTS) {
            fail_job($db, $job, $error);
            return ['status' => 'failed', 'job_id' => (int) $job['id'], 'type' => $job['type'],
                    'message' => "İş #{$job['id']} {$job['attempts']}. denemede kalıcı başarısız → failed_jobs. ($error)"];
        }
        release_job($db, $job, $error);
        $next = backoff_seconds((int) $job['attempts']);
        return ['status' => 'retry', 'job_id' => (int) $job['id'], 'type' => $job['type'],
                'message' => "İş #{$job['id']} hata verdi ({$job['attempts']}/" . JOB_MAX_ATTEMPTS
                             . "), {$next} sn sonra tekrar denenecek. ($error)"];
    }
}

/* =====================================================================
 *  BÖLÜM 5 – İŞ İŞLEYİCİLERİ
 * ---------------------------------------------------------------------
 *  Gerçek projede burada e-posta gönderimi, görsel işleme, rapor
 *  üretimi olurdu. Demo işleyiciler yalnızca "iş yapıyormuş gibi"
 *  bekler; flaky_task ise RETRY davranışını göstermek için rastgele
 *  hata fırlatır.
 * ================================================================== */

function run_job_handler(string $type, array $payload): string
{
    return match ($type) {
        'send_email'   => handle_send_email($payload),
        'resize_image' => handle_resize_image($payload),
        'flaky_task'      => handle_flaky_task($payload),
        'generate_report' => handle_generate_report($payload),
        default           => throw new RuntimeException("Bilinmeyen iş türü: $type"),
    };
}

function handle_send_email(array $p): string
{
    $to = (string) ($p['to'] ?? 'kime@ornek.com');
    usleep(200_000); // "gönderiliyor…"
    return "e-posta gönderildi → $to";
}

function handle_resize_image(array $p): string
{
    $file = (string) ($p['file'] ?? 'gorsel.jpg');
    usleep(400_000);
    return "görsel yeniden boyutlandırıldı → $file (200x200, 400x400)";
}

/**
 * Uzun süren iş örneği. Gerçek bir raporun neden kuyruğa alındığını
 * gösterir: istek döngüsünde 3 saniye beklemek, tarayıcıyı bekletmek
 * demektir; kuyrukta beklemek kimseyi bekletmez.
 */
function handle_generate_report(array $p): string
{
    $report = (string) ($p['report'] ?? 'rapor');
    $format = (string) ($p['format'] ?? 'pdf');

    usleep(700_000);   // "hesaplanıyor…"

    return "rapor üretildi → $report.$format";
}

/**
 * ~%55 olasılıkla hata fırlatır. Kuyruktaki retry + backoff + dead
 * letter akışını gözlemlemek için.
 */
function handle_flaky_task(array $p): string
{
    if (random_int(1, 100) <= 55) {
        throw new RuntimeException('Geçici bir aksaklık (flaky_task bilerek patladı).');
    }
    usleep(150_000);
    return 'kararsız iş bu sefer başarılı oldu';
}
