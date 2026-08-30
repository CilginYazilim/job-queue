<?php
/**
 * =====================================================================
 *  WORKER  (kuyruk işleyici — sürekli çalışan süreç)
 *  cilginyazilim.com – Veritabanı Tabanlı İş Kuyruğu
 * ---------------------------------------------------------------------
 *  Çalıştırma:
 *    php bin/worker.php                 # varsayılan kuyruk, süresiz
 *    php bin/worker.php --once          # tek iş işle ve çık
 *    php bin/worker.php --max=50        # 50 iş işleyince çık
 *    php bin/worker.php --queue=mails   # başka bir kuyruk
 *
 *  Üretimde bir süpervizör (systemd, supervisor, pm2) altında tutun;
 *  çökerse yeniden başlatsın. Ya da basitçe cron ile "--once" her dakika.
 *
 *  Zarif kapanış: SIGTERM/SIGINT alınca İŞLENEN işi bitirir, sonra çıkar.
 *  Web'den çağrılamaz.
 * =====================================================================
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Bu betik yalnızca komut satırından çalıştırılabilir.\n");
}

define('CY_APP', true);
require __DIR__ . '/../system/config.php';
require __DIR__ . '/../system/function.php';

$opts    = getopt('', ['once', 'max::', 'queue::']);
$once    = isset($opts['once']);
$max     = isset($opts['max']) ? max(1, (int) $opts['max']) : 0;
$queue   = $opts['queue'] ?? 'default';
$worker  = gethostname() . ':' . getmypid();

$running = true;
if (function_exists('pcntl_signal')) {
    pcntl_async_signals(true);
    $stop = static function () use (&$running) {
        fwrite(STDOUT, "\n[worker] kapanış istendi, işlenen iş bitince çıkılacak…\n");
        $running = false;
    };
    pcntl_signal(SIGTERM, $stop);
    pcntl_signal(SIGINT, $stop);
}

fwrite(STDOUT, "[worker] başladı  worker=$worker  queue=$queue  max_attempts=" . JOB_MAX_ATTEMPTS . "\n");

$processed = 0;
while ($running) {
    try {
        $r = process_one($db, $worker, $queue);
    } catch (Throwable $e) {
        fwrite(STDERR, '[worker] motor hatası: ' . $e->getMessage() . "\n");
        sleep(WORKER_SLEEP);
        continue;
    }

    if ($r['status'] === 'empty') {
        if ($once) {
            fwrite(STDOUT, "[worker] kuyruk boş (--once), çıkılıyor.\n");
            break;
        }
        sleep(WORKER_SLEEP);
        continue;
    }

    $processed++;
    $icon = ['done' => '✓', 'retry' => '↻', 'failed' => '✗'][$r['status']] ?? '?';
    fwrite(STDOUT, sprintf("[%s] %s %s\n", date('H:i:s'), $icon, $r['message']));

    if ($once || ($max > 0 && $processed >= $max)) {
        fwrite(STDOUT, "[worker] $processed iş işlendi, çıkılıyor.\n");
        break;
    }
}

fwrite(STDOUT, "[worker] durdu. Toplam işlenen: $processed\n");
exit(0);
