<?php
/**
 * =====================================================================
 *  AJAX UÇ NOKTASI
 *  cilginyazilim.com – Veritabanı Tabanlı İş Kuyruğu
 * ---------------------------------------------------------------------
 *    action=enqueue        → Kuyruğa iş ekle
 *    action=status         → Bekleyen + başarısız işler + sayaçlar
 *    action=job_detail     → Tek işin künyesi (payload, hata, zamanlar)
 *    action=work_once      → Worker'ı BİR kez tetikle (gözlem için)
 *    action=retry_failed   → failed_jobs'taki bir işi tekrar kuyruğa al
 *    action=forget_failed  → failed_jobs'tan bir işi kalıcı sil
 *    action=clear_failed   → Tüm failed_jobs'ı temizle
 *    action=release_stale  → Bayatlamış rezervasyonları elle serbest bırak
 * =====================================================================
 */

declare(strict_types=1);

define('CY_APP', true);
require __DIR__ . '/config.php';
require __DIR__ . '/function.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('Yalnızca POST istekleri kabul edilir.', 405);
}

require_csrf();

$action = strtolower(trim((string) ($_POST['action'] ?? 'status')));

try {
    switch ($action) {
        case 'enqueue':       handle_enqueue($db);       break;
        case 'status':        handle_status($db);        break;
        case 'job_detail':    handle_job_detail($db);    break;
        case 'work_once':     handle_work_once($db);     break;
        case 'retry_failed':  handle_retry_failed($db);  break;
        case 'forget_failed': handle_forget_failed($db); break;
        case 'clear_failed':  handle_clear_failed($db);  break;
        case 'release_stale': handle_release_stale($db); break;
        default:              json_error('Bilinmeyen işlem.', 400);
    }
} catch (PDOException $e) {
    error_log('[QUEUE] DB: ' . $e->getMessage());

    /* SKIP LOCKED desteklenmiyorsa hata mesajı tek başına kimseye bir
     * şey anlatmaz. Kod zaten yedek yola düşer, ama başka bir yerde
     * aynı sözdizimi kullanılırsa ipucu işe yarar. */
    $hint = (str_contains($e->getMessage(), 'SKIP LOCKED') || $e->getCode() === '42000')
        ? ' (FOR UPDATE SKIP LOCKED için MySQL 8.0+ / MariaDB 10.6+ gerekir.)' : '';

    json_error(APP_DEBUG ? 'DB hatası: ' . $e->getMessage() . $hint : 'Beklenmeyen bir veritabanı hatası.', 500);
} catch (Throwable $e) {
    error_log('[QUEUE] Hata: ' . $e->getMessage());
    json_error(APP_DEBUG ? 'Hata: ' . $e->getMessage() : 'Beklenmeyen bir hata.', 500);
}

/** İstemciden gelen kuyruk adı — beyaz listeden geçer. */
function requested_queue(): string
{
    $q = (string) ($_POST['queue'] ?? DEFAULT_QUEUE);

    return in_array($q, KNOWN_QUEUES, true) ? $q : DEFAULT_QUEUE;
}

/* =====================================================================
 *  KUYRUĞA EKLE
 * ================================================================== */
function handle_enqueue(PDO $db): void
{
    rate_limit('enqueue', ...RATE_LIMIT_ENQUEUE);

    $type  = strtolower(trim((string) ($_POST['type'] ?? '')));
    $count = min(ENQUEUE_MAX_COUNT, max(1, (int) ($_POST['count'] ?? 1)));
    $delay = min(3600, max(0, (int) ($_POST['delay'] ?? 0)));
    $queue = requested_queue();

    if (!in_array($type, KNOWN_JOBS, true)) {
        json_error('Bilinmeyen iş türü.', 422, ['errors' => ['type' => 'Geçerli türler: ' . implode(', ', KNOWN_JOBS)]]);
    }

    /* Payload her iş için YENİDEN üretilir. Aynı nesneyi 25 kez
     * eklemek, kuyrukta birbirinden ayırt edilemeyen 25 satır bırakırdı
     * ve demo hangi işin işlendiğini göstermezdi. */
    $ids = [];
    for ($i = 0; $i < $count; $i++) {
        $ids[] = enqueue($db, $type, sample_payload($type), $queue, $delay);
    }

    json_success(
        $count . ' iş "' . $queue . '" kuyruğuna eklendi' . ($delay > 0 ? " ($delay sn gecikmeli)" : '') . '.',
        ['ids' => $ids, 'queue' => $queue]
    );
}

/** Tür başına örnek payload üretir. */
function sample_payload(string $type): array
{
    return match ($type) {
        'send_email' => [
            'to'      => 'musteri' . random_int(1, 999) . '@ornek.com',
            'subject' => ['Hoş geldiniz', 'Sipariş onayı', 'Parola sıfırlama', 'Haftalık bülten'][random_int(0, 3)],
        ],
        'resize_image' => [
            'file'  => 'yukleme_' . random_int(1000, 9999) . '.jpg',
            'sizes' => [200, 400, 800],
        ],
        'generate_report' => [
            'report' => ['aylik-satis', 'stok-durumu', 'musteri-analizi'][random_int(0, 2)],
            'format' => ['pdf', 'xlsx'][random_int(0, 1)],
        ],
        'flaky_task' => ['ref' => bin2hex(random_bytes(4))],
        default      => [],
    };
}

/* =====================================================================
 *  DURUM
 * ---------------------------------------------------------------------
 *  Sayaçlar ve listeler AYNI kuyruk için hesaplanır. İlk sürümde liste
 *  `queue = "default"` ile süzülüyordu ama sayaçlar TÜM kuyrukları
 *  topluyordu; "mails" kuyruğuna iş eklendiğinde sayaç artıyor, liste
 *  boş kalıyordu. İki farklı soruyu aynı ekranda cevaplamak, ikisini de
 *  aynı koşuldan geçirmeyi gerektirir.
 * ================================================================== */
function handle_status(PDO $db): void
{
    rate_limit('status', ...RATE_LIMIT_STATUS);

    $queue = requested_queue();

    /* RESERVE_TTL SQL'e DOĞRUDAN gömülür, yer tutucuyla değil.
     *
     * ÖLÇÜLEN SORUN: `INTERVAL :ttl SECOND` ifadesi MariaDB'de bir SUM()
     * içinde ayrıştırılamıyor ve sorgu 1064 (sözdizimi hatası) veriyor.
     * Sunucu, INTERVAL biriminden önce bir yer tutucu görmeyi her
     * bağlamda kabul etmiyor.
     *
     * Gömmek burada GÜVENLİDİR ve bu istisna bilinçlidir: RESERVE_TTL
     * bir yapılandırma SABİTİDİR, istemciden gelmez. Yine de (int)
     * dönüşümü yapıyoruz — sabitin ileride yanlışlıkla metne
     * dönüştürülmesi ihtimalini kapatmak için. Kullanıcı verisi asla
     * böyle gömülmez; bu dosyadaki diğer her değer bağlanır. */
    $ttl = (int) RESERVE_TTL;

    $pending = $db->prepare(
        "SELECT id, type, attempts, last_error, available_at, reserved_at, reserved_by,
                (reserved_at IS NOT NULL) AS `reserved`,
                (reserved_at IS NOT NULL AND reserved_at < (NOW() - INTERVAL $ttl SECOND)) AS `stale`,
                GREATEST(0, TIMESTAMPDIFF(SECOND, NOW(), available_at)) AS `in_seconds`
           FROM jobs
          WHERE queue = :q
          ORDER BY available_at ASC, id ASC
          LIMIT 100"
    );
    $pending->bindValue(':q', $queue);
    $pending->execute();

    $failed = $db->prepare(
        'SELECT id, type, attempts, exception, failed_at
           FROM failed_jobs WHERE queue = :q ORDER BY id DESC LIMIT 100'
    );
    $failed->execute([':q' => $queue]);

    /* Beş sayaç TEK sorguda. Ayrı ayrı sormak beş gidiş-dönüş demektir
     * ve sayaçlar birbirine göre TUTARSIZ olabilir: ilk sorgu ile son
     * sorgu arasında worker bir iş bitirirse toplamlar tutmaz. */
    /* TAKMA ADLAR BACKTICK İÇİNDE.
     *
     * ÖLÇÜLEN SORUN: `AS delayed` yazmak MariaDB'de 1064 veriyordu.
     * DELAYED, MySQL/MariaDB'de AYRILMIŞ BİR KELİMEDİR (`INSERT DELAYED`
     * sözdiziminden gelir) ve takma ad olarak tırnaksız kullanılamaz.
     * Hata mesajı yalnızca "near 'delayed'" der; ayrılmış kelime
     * olduğunu söylemez, bu yüzden aranması zaman alır.
     *
     * Kural: takma adı İngilizce bir ortak sözcük olan her sütunu
     * backtick içine alın. Bedeli sıfır, kazancı bu tür bir saatin
     * tamamı. */
    $c = $db->prepare(
        "SELECT
            SUM(reserved_at IS NULL AND available_at <= NOW())  AS `ready`,
            SUM(reserved_at IS NULL AND available_at >  NOW())  AS `delayed`,
            SUM(reserved_at IS NOT NULL
                AND reserved_at >= (NOW() - INTERVAL $ttl SECOND))  AS `reserved`,
            SUM(reserved_at IS NOT NULL
                AND reserved_at <  (NOW() - INTERVAL $ttl SECOND))  AS `stale`,
            COUNT(*) AS `total`
         FROM jobs WHERE queue = :q"
    );
    $c->bindValue(':q', $queue);
    $c->execute();
    $row = $c->fetch() ?: [];

    $failedCount = $db->prepare('SELECT COUNT(*) FROM failed_jobs WHERE queue = :q');
    $failedCount->execute([':q' => $queue]);

    json_response([
        'success' => true,
        'queue'   => $queue,
        'queues'  => KNOWN_QUEUES,
        'counts'  => [
            'ready'    => (int) ($row['ready'] ?? 0),
            'delayed'  => (int) ($row['delayed'] ?? 0),
            'reserved' => (int) ($row['reserved'] ?? 0),
            'stale'    => (int) ($row['stale'] ?? 0),
            'total'    => (int) ($row['total'] ?? 0),
            'failed'   => (int) $failedCount->fetchColumn(),
        ],
        'pending' => array_map(static fn($r) => [
            'id'         => (int) $r['id'],
            'type'       => $r['type'],
            'attempts'   => (int) $r['attempts'],
            'reserved'   => (bool) $r['reserved'],
            'stale'      => (bool) $r['stale'],
            'in_seconds' => (int) $r['in_seconds'],
            'has_error'  => $r['last_error'] !== null,
        ], $pending->fetchAll()),
        'failed' => array_map(static fn($r) => [
            'id'        => (int) $r['id'],
            'type'      => $r['type'],
            'attempts'  => (int) $r['attempts'],
            'exception' => $r['exception'],
            'failed_at' => date('d.m.Y H:i', strtotime((string) $r['failed_at'])),
        ], $failed->fetchAll()),
        'max_attempts' => JOB_MAX_ATTEMPTS,
        'reserve_ttl'  => RESERVE_TTL,
    ]);
}

/* =====================================================================
 *  TEK İŞ DETAYI
 * ---------------------------------------------------------------------
 *  Bir kuyruk ekranında en çok sorulan soru "bu iş neden hâlâ burada?"
 *  sorusudur. Cevap dört alandadır: attempts, available_at, reserved_at
 *  ve last_error. Dördünü de tek yerde gösteriyoruz.
 * ================================================================== */
function handle_job_detail(PDO $db): void
{
    $id     = (int) ($_POST['id'] ?? 0);
    $source = ($_POST['source'] ?? 'jobs') === 'failed' ? 'failed_jobs' : 'jobs';

    if ($id < 1) {
        json_error('Geçersiz iş numarası.', 400);
    }

    /* BAYATLIK SQL'DE HESAPLANIR, PHP'DE DEĞİL.
     *
     * ÖLÇÜLEN SORUN: detay ekranı bir işi "bayat değil" gösterirken
     * liste aynı işi "bayat" gösteriyordu. Sebep, iki yerin FARKLI
     * SAATE bakmasıydı: liste MySQL'in NOW() değerini, detay ise
     * PHP'nin time() değerini kullanıyordu. Sunucunun ve veritabanının
     * saat dilimi aynı olmak zorunda değildir — ve genellikle bir
     * paylaşımlı sunucuda değildir.
     *
     * Kural: aynı soruyu iki yerde soruyorsanız, ikisi de AYNI saate
     * bakmalıdır. Zaman karşılaştırmaları verinin yanında, yani
     * veritabanında yapılır.
     *
     * Tablo adı istemciden GELMEZ; iki sabit değerden biridir. */
    $staleExpr = $source === 'jobs'
        ? ", (reserved_at IS NOT NULL AND reserved_at < (NOW() - INTERVAL " . (int) RESERVE_TTL . " SECOND)) AS `is_stale`
             , GREATEST(0, TIMESTAMPDIFF(SECOND, NOW(), available_at)) AS `in_seconds`"
        : '';

    $stmt = $db->prepare("SELECT * $staleExpr FROM $source WHERE id = :id");
    $stmt->execute([':id' => $id]);
    $r = $stmt->fetch();

    if (!$r) {
        json_error('İş bulunamadı (işlenmiş ve kuyruktan silinmiş olabilir).', 404);
    }

    /* payload JSON sütunudur; ekranda okunabilir olsun diye yeniden
     * biçimlendiriyoruz. Bozuk bir JSON gelirse ham metni gösteriyoruz —
     * "görüntülenemiyor" demek, sorunu gizlemek olurdu. */
    $payload = json_decode((string) $r['payload'], true);
    $prettyPayload = is_array($payload)
        ? json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        : (string) $r['payload'];

    $out = [
        'id'       => (int) $r['id'],
        'source'   => $source === 'failed_jobs' ? 'failed' : 'jobs',
        'queue'    => $r['queue'],
        'type'     => $r['type'],
        'attempts' => (int) $r['attempts'],
        'payload'  => $prettyPayload,
    ];

    if ($source === 'failed_jobs') {
        $out['exception'] = $r['exception'];
        $out['failed_at'] = date('d.m.Y H:i:s', strtotime((string) $r['failed_at']));
    } else {
        $reservedAt = $r['reserved_at'];

        $out['available_at'] = date('d.m.Y H:i:s', strtotime((string) $r['available_at']));
        $out['in_seconds']   = (int) $r['in_seconds'];          // SQL'den geldi
        $out['reserved_at']  = $reservedAt ? date('d.m.Y H:i:s', strtotime((string) $reservedAt)) : null;
        $out['reserved_by']  = $r['reserved_by'];
        $out['stale']        = (bool) $r['is_stale'];           // SQL'den geldi
        $out['last_error']   = $r['last_error'];
        $out['created_at']   = date('d.m.Y H:i:s', strtotime((string) $r['created_at']));
        $out['next_backoff'] = backoff_seconds((int) $r['attempts'] + 1);
    }

    json_response(['success' => true, 'job' => $out]);
}

/* =====================================================================
 *  WORKER'I BİR KEZ TETİKLE
 * ---------------------------------------------------------------------
 *  Bu uç nokta cron'un YERİNE GEÇMEZ; gözlem içindir. Gerçek kurulumda
 *  `php bin/worker.php` sürekli çalışır ya da cron her dakika
 *  `--once` çağırır. Arayüzden tetiklemek, kuyruğun nasıl davrandığını
 *  adım adım izleyebilmek için vardır — ve web isteğinin içinde bir iş
 *  çalıştırmanın neden kötü bir fikir olduğunu da gösterir: istek,
 *  işin süresi kadar bekler.
 * ================================================================== */
function handle_work_once(PDO $db): void
{
    rate_limit('work', ...RATE_LIMIT_WORK);

    $queue  = requested_queue();
    $worker = 'web-' . substr(session_id(), 0, 8);

    $started = microtime(true);
    $result  = process_one($db, $worker, $queue);

    json_response([
        'success'  => true,
        'queue'    => $queue,
        'took_ms'  => round((microtime(true) - $started) * 1000, 1),
    ] + $result);
}

/* =====================================================================
 *  BAYATLAMIŞ REZERVASYONLARI SERBEST BIRAK
 * ---------------------------------------------------------------------
 *  Normalde buna gerek yoktur: rezervasyon süresi dolan iş, bir sonraki
 *  rezervasyon sorgusunda zaten yeniden alınabilir hâle gelir. Bu düğme
 *  o mekanizmayı GÖRÜNÜR kılmak için vardır — ve gerçek bir operasyonda
 *  "şu çöken worker'ın işlerini hemen geri al" demenin karşılığıdır.
 * ================================================================== */
function handle_release_stale(PDO $db): void
{
    $queue = requested_queue();

    // TTL sabiti gömülür (bkz. handle_status içindeki açıklama).
    $ttl = (int) RESERVE_TTL;

    $stmt = $db->prepare(
        "UPDATE jobs
            SET reserved_at = NULL, reserved_by = NULL
          WHERE queue = :q
            AND reserved_at IS NOT NULL
            AND reserved_at < (NOW() - INTERVAL $ttl SECOND)"
    );
    $stmt->bindValue(':q', $queue);
    $stmt->execute();

    $n = $stmt->rowCount();

    json_success(
        $n === 0
            ? 'Bayatlamış rezervasyon yok.'
            : $n . ' bayat rezervasyon serbest bırakıldı; işler yeniden alınabilir.',
        ['released' => $n]
    );
}

/* =====================================================================
 *  BAŞARISIZ İŞ YÖNETİMİ
 * ================================================================== */
function handle_retry_failed(PDO $db): void
{
    $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
    if (!$id) {
        json_error('Geçersiz kayıt.', 400);
    }

    $stmt = $db->prepare('SELECT queue, type, payload FROM failed_jobs WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();

    if (!$row) {
        json_error('Başarısız iş bulunamadı.', 404);
    }

    /* İki işlem TEK transaction'da: kuyruğa geri koymak ve dead-letter
     * kaydını silmek. Ayrı yazılsaydı, ikincisi başarısız olduğunda iş
     * hem kuyrukta hem başarısızlarda görünürdü — ve bir dahaki sefere
     * iki kez çalışırdı. */
    $db->beginTransaction();
    try {
        $db->prepare(
            'INSERT INTO jobs (queue, type, payload, available_at) VALUES (:q, :t, :p, NOW())'
        )->execute([':q' => $row['queue'], ':t' => $row['type'], ':p' => $row['payload']]);

        $db->prepare('DELETE FROM failed_jobs WHERE id = :id')->execute([':id' => $id]);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }

    json_success('İş yeniden kuyruğa alındı (deneme sayacı sıfırlandı).');
}

function handle_forget_failed(PDO $db): void
{
    $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
    if (!$id) {
        json_error('Geçersiz kayıt.', 400);
    }

    $stmt = $db->prepare('DELETE FROM failed_jobs WHERE id = :id');
    $stmt->execute([':id' => $id]);

    if ($stmt->rowCount() === 0) {
        json_error('Kayıt bulunamadı.', 404);
    }

    json_success('Başarısız iş silindi.');
}

function handle_clear_failed(PDO $db): void
{
    $queue = requested_queue();

    $stmt = $db->prepare('DELETE FROM failed_jobs WHERE queue = :q');
    $stmt->execute([':q' => $queue]);

    json_success($stmt->rowCount() . ' başarısız iş temizlendi.');
}
