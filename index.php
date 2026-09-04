<?php
/**
 * =====================================================================
 *  ARAYÜZ (Sunum Katmanı)
 *  cilginyazilim.com – Veritabanı Tabanlı İş Kuyruğu
 * ---------------------------------------------------------------------
 *  Ekran üç bölümden oluşur ve sırası bilinçlidir:
 *
 *    1) SAYAÇ ŞERİDİ  → kuyruğun o anki hâli: hazır, gecikmeli,
 *       rezerve, BAYAT rezerve, başarısız
 *    2) İKİ TABLO     → solda bekleyen kuyruk, sağda dead-letter
 *    3) WORKER GÜNLÜĞÜ → tetiklenen her işin sonucu, satır satır
 *
 *  "Bayat rezerve" sayacı ayrı durur ve bu ekranın en öğretici
 *  parçasıdır: bir iş "işleniyor" görünüyor olabilir ama onu alan
 *  worker çökmüş olabilir. O sayaç sıfırdan büyükse, kuyrukta kimsenin
 *  işlemediği bir iş var demektir.
 *
 *  Bu dosya veritabanına DOKUNMAZ. Tüm veri system/ajax.php üzerinden,
 *  AJAX ile gelir.
 * =====================================================================
 */

declare(strict_types=1);

define('CY_APP', true);
require __DIR__ . '/system/config.php';
require __DIR__ . '/system/function.php';

$token = csrf_token();
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="author" content="Çılgın Yazılım - cilginyazilim.com">
    <meta name="description" content="PHP PDO ve MySQL ile veritabanı tabanlı iş kuyruğu: FOR UPDATE SKIP LOCKED ile güvenli rezervasyon, üstel geri çekilme, görünürlük zaman aşımı, dead-letter kuyruğu ve cron worker.">
    <meta name="theme-color" content="#0b5cb5">

    <title>Veritabanı Tabanlı İş Kuyruğu | Çılgın Yazılım</title>

    <link rel="icon" type="image/png" href="assets/images/logo.png">

    <!--
        CSS YÜKLEME SIRASI ÖNEMLİDİR:
          1) bootstrap      → temel çatı
          2) cilginyazilim  → MARKA TASARIM KALIBI (Bootstrap'i ezer)
          3) style          → yalnızca bu sayfaya özel eklemeler
    -->
    <link rel="stylesheet" href="assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/css/cilginyazilim.css">
    <link rel="stylesheet" href="assets/css/style.css">
</head>

<body class="cy-app">

<!-- Sayfanın en üstündeki ince marka şeridi -->
<div class="cy-topbar"></div>

<div class="container py-4 py-lg-5">

    <div class="cy-card mb-4">

        <div class="cy-card__header">
            <div class="cy-header-top d-flex flex-wrap justify-content-between align-items-center gap-3">

                <a class="cy-brand" href="https://cilginyazilim.com" target="_blank" rel="noopener">
                    <span class="cy-brand__mark">
                        <img src="assets/images/logo.png" alt="Çılgın Yazılım logosu">
                    </span>
                    <div>
                        <h1 class="cy-brand__title">Veritabanı Tabanlı İş Kuyruğu</h1>
                        <p class="cy-brand__subtitle">
                            SKIP LOCKED &middot; Üstel geri çekilme &middot; Görünürlük zaman aşımı &middot; Dead-letter
                        </p>
                    </div>
                </a>

                <div class="cy-header-controls d-flex align-items-center gap-2 flex-wrap">
                    <span class="cy-badge cy-badge--glass">
                        <strong id="stat-total-badge">0</strong> iş
                    </span>

                    <a class="btn cy-btn cy-btn--glass"
                       href="https://github.com/CilginYazilim/job-queue"
                       target="_blank" rel="noopener" title="Projeyi GitHub'da aç">
                        <svg width="16" height="16" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true" style="vertical-align:-2px">
                            <path d="M8 0C3.58 0 0 3.58 0 8c0 3.54 2.29 6.53 5.47 7.59.4.07.55-.17.55-.38 0-.19-.01-.82-.01-1.49-2.01.37-2.53-.49-2.69-.94-.09-.23-.48-.94-.82-1.13-.28-.15-.68-.52-.01-.53.63-.01 1.08.58 1.23.82.72 1.21 1.87.87 2.33.66.07-.52.28-.87.51-1.07-1.78-.2-3.64-.89-3.64-3.95 0-.87.31-1.59.82-2.15-.08-.2-.36-1.02.08-2.12 0 0 .67-.21 2.2.82.64-.18 1.32-.27 2-.27s1.36.09 2 .27c1.53-1.04 2.2-.82 2.2-.82.44 1.1.16 1.92.08 2.12.51.56.82 1.27.82 2.15 0 3.07-1.87 3.75-3.65 3.95.29.25.54.73.54 1.48 0 1.07-.01 1.93-.01 2.2 0 .21.15.46.55.38A8.01 8.01 0 0 0 16 8c0-4.42-3.58-8-8-8Z"/>
                        </svg>
                        <span class="cy-header-controls__label">GitHub</span>
                    </a>

                    <!-- id="add_button" — marka CSS'inin mobil kuralları bu
                         kimliği hedefler (dar ekranda tam genişliğe geçer).

                         Demonun giriş kapısı: worker'ı bir kez tetikler ve
                         kuyruğun canlı davranışını ilk saniyede gösterir. -->
                    <button type="button" id="add_button" class="btn cy-btn cy-btn--onbrand">
                        <span aria-hidden="true">▶</span> Worker'ı çalıştır
                    </button>
                </div>
            </div>
        </div>

        <div class="cy-card__body">

            <!-- ---------------------------------------------------------
                 SAYAÇ ŞERİDİ — kuyruğun nabzı
                 --------------------------------------------------------- -->
            <div class="cy-stats" id="cy-stats">
                <div class="cy-stat cy-stat--ready">
                    <span class="cy-stat__value" id="stat-ready">0</span>
                    <span class="cy-stat__label">Hazır</span>
                </div>
                <div class="cy-stat cy-stat--delayed">
                    <span class="cy-stat__value" id="stat-delayed">0</span>
                    <span class="cy-stat__label">Gecikmeli</span>
                </div>
                <div class="cy-stat cy-stat--reserved">
                    <span class="cy-stat__value" id="stat-reserved">0</span>
                    <span class="cy-stat__label">İşleniyor</span>
                </div>
                <div class="cy-stat cy-stat--stale">
                    <span class="cy-stat__value" id="stat-stale">0</span>
                    <span class="cy-stat__label">Bayat rezerve</span>
                </div>
                <div class="cy-stat cy-stat--failed">
                    <span class="cy-stat__value" id="stat-failed">0</span>
                    <span class="cy-stat__label">Başarısız</span>
                </div>
            </div>

            <!-- ---------------------------------------------------------
                 KUYRUĞA İŞ EKLE
                 --------------------------------------------------------- -->
            <section class="cy-panel">
                <h2 class="cy-panel__title">Kuyruğa iş ekle</h2>

                <div class="row g-2 align-items-end">
                    <div class="col-sm-6 col-lg-3">
                        <label class="form-label" for="type">İş türü</label>
                        <select id="type" class="form-select">
                            <option value="send_email">send_email</option>
                            <option value="resize_image">resize_image</option>
                            <option value="generate_report">generate_report</option>
                            <option value="flaky_task">flaky_task &mdash; ~%55 hata</option>
                        </select>
                    </div>

                    <div class="col-6 col-lg-2">
                        <label class="form-label" for="count">Adet</label>
                        <input id="count" type="number" class="form-control" value="3" min="1" max="<?= ENQUEUE_MAX_COUNT ?>" inputmode="numeric">
                    </div>

                    <div class="col-6 col-lg-2">
                        <label class="form-label" for="delay">Gecikme (sn)</label>
                        <input id="delay" type="number" class="form-control" value="0" min="0" max="3600" inputmode="numeric">
                    </div>

                    <div class="col-sm-6 col-lg-3">
                        <label class="form-label" for="queue">Kuyruk</label>
                        <select id="queue" class="form-select">
                            <?php foreach (KNOWN_QUEUES as $q): ?>
                                <option value="<?= e($q) ?>"><?= e($q) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-sm-6 col-lg-2 d-grid">
                        <button type="button" id="btn-enqueue" class="btn cy-btn cy-btn--primary">Ekle</button>
                    </div>
                </div>

                <p class="cy-hint mt-2 mb-0">
                    <b>flaky_task</b> bilerek ~%55 olasılıkla patlar; <b><?= JOB_MAX_ATTEMPTS ?></b> denemede
                    başarısız olursa dead-letter kuyruğuna düşer. Geri çekilme:
                    <?= BACKOFF_BASE ?> sn &rarr; <?= BACKOFF_BASE * 2 ?> sn &rarr; <?= BACKOFF_BASE * 4 ?> sn &hellip;
                </p>
            </section>

            <!-- ---------------------------------------------------------
                 WORKER DENETİMİ
                 --------------------------------------------------------- -->
            <section class="cy-panel cy-panel--worker">
                <div class="cy-worker">
                    <div class="cy-worker__info">
                        <h2 class="cy-panel__title mb-1">Worker</h2>
                        <p class="cy-hint mb-0">
                            Gerçek kurulumda <code>php bin/worker.php</code> sürekli çalışır.
                            Buradaki düğme onu <b>bir kez</b> tetikler — kuyruğun davranışını adım adım izlemek için.
                        </p>
                    </div>

                    <div class="cy-worker__actions">
                        <button type="button" id="btn-tick" class="btn cy-btn cy-btn--primary">Bir iş işle</button>

                        <label class="cy-switch">
                            <input type="checkbox" id="autotick">
                            <span>Otomatik (2 sn)</span>
                        </label>

                        <button type="button" id="btn-release" class="btn cy-btn" title="Rezervasyon süresi dolan işleri serbest bırak">
                            Bayatları serbest bırak
                        </button>
                    </div>
                </div>

                <pre id="log" class="cy-log" aria-live="polite" aria-label="Worker günlüğü">worker günlüğü…</pre>
            </section>
        </div>

        <div class="cy-card__footer d-flex flex-wrap justify-content-between gap-2">
            <span>Rezervasyon süresi (<code>RESERVE_TTL</code>) <b><?= RESERVE_TTL ?> sn</b> — çöken worker'ın işi bu süre sonra serbest kalır.</span>
            <span>PHP <?= e(PHP_VERSION) ?></span>
        </div>
    </div>


    <!-- =================================================================
         İKİ TABLO — bekleyen kuyruk ve dead-letter
         ================================================================= -->
    <div class="row g-3">

        <div class="col-lg-7">
            <div class="cy-card h-100">
                <div class="cy-card__header">
                    <div class="cy-header-top d-flex flex-wrap justify-content-between align-items-center gap-2">
                        <div>
                            <h2 class="cy-brand__title mb-0">Kuyruk</h2>
                            <p class="cy-brand__subtitle mb-0">Bekleyen, gecikmeli ve işlenen işler</p>
                        </div>
                        <span class="cy-badge cy-badge--glass" id="pending-badge">0 iş</span>
                    </div>
                </div>
                <div class="cy-card__body p-0">
                    <div class="table-responsive">
                        <table class="table cy-table cy-jobs mb-0">
                            <thead>
                                <tr>
                                    <th scope="col">#</th>
                                    <th scope="col">Tür</th>
                                    <th scope="col">Deneme</th>
                                    <th scope="col">Durum</th>
                                </tr>
                            </thead>
                            <tbody id="pending"></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-5">
            <div class="cy-card h-100">
                <div class="cy-card__header">
                    <div class="cy-header-top d-flex flex-wrap justify-content-between align-items-center gap-2">
                        <div>
                            <h2 class="cy-brand__title mb-0">Dead-letter</h2>
                            <p class="cy-brand__subtitle mb-0">Deneme hakkı biten işler</p>
                        </div>
                        <button type="button" id="btn-clear-failed" class="btn cy-btn cy-btn--glass">
                            Temizle
                        </button>
                    </div>
                </div>
                <div class="cy-card__body p-0">
                    <div class="table-responsive">
                        <table class="table cy-table cy-failed mb-0">
                            <thead>
                                <tr>
                                    <th scope="col">#</th>
                                    <th scope="col">Tür</th>
                                    <th scope="col">Hata</th>
                                    <th scope="col" class="text-end">İşlem</th>
                                </tr>
                            </thead>
                            <tbody id="failed"></tbody>
                        </table>
                    </div>
                </div>
                <div class="cy-card__footer">
                    <span>Yeniden kuyruğa almadan önce <b>hata metnini okuyun</b>: kalıcı bir hata aynı sonucu verir.</span>
                </div>
            </div>
        </div>
    </div>

    <div class="cy-footer-note mt-4">
        <p class="mb-1">
            Bu açık kaynak örnek, <a href="https://cilginyazilim.com" target="_blank" rel="noopener">cilginyazilim.com</a>
            tarafından geliştirilmiştir. MIT lisanslıdır; dilediğiniz gibi indirip kullanabilirsiniz.
        </p>
        <p class="mb-1">
            Katkı sağlamak ister misiniz? Depoyu çatallayın (fork) ve pull request gönderin:
            <a href="https://github.com/CilginYazilim/job-queue"
               target="_blank" rel="noopener">github.com/CilginYazilim</a>
        </p>
        <p class="mb-0">
            Aynı tasarım kalıbıyla hazırlanmış diğer açıklamalı örnekler:
            <a href="https://cilginyazilim.com/kutuphane" target="_blank" rel="noopener">cilginyazilim.com/kutuphane</a>
        </p>
    </div>
</div>


<!-- =====================================================================
     MODAL – İŞ DETAYI
     ---------------------------------------------------------------------
     Bir kuyruk ekranında en çok sorulan soru "bu iş neden hâlâ burada?"
     sorusudur. Cevap dört alandadır: attempts, available_at, reserved_at
     ve last_error. Dördü de burada, tek ekranda durur.
     ===================================================================== -->
<div class="modal fade" id="modal-job" tabindex="-1" aria-labelledby="modal-job-title" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content cy-modal">
            <div class="modal-header">
                <h5 class="modal-title" id="modal-job-title">İş</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Kapat"></button>
            </div>
            <div class="modal-body">
                <div id="job-badges" class="cy-badges mb-3"></div>
                <dl class="cy-detail" id="job-meta"></dl>

                <h3 class="cy-section-title mt-3">Payload</h3>
                <pre class="cy-log cy-log--payload" id="job-payload"></pre>

                <div id="job-error-wrap" class="d-none">
                    <h3 class="cy-section-title mt-3">Son hata</h3>
                    <pre class="cy-log cy-log--error" id="job-error"></pre>
                </div>

                <p class="cy-hint mt-3 mb-0" id="job-note"></p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn cy-btn" data-bs-dismiss="modal">Kapat</button>
            </div>
        </div>
    </div>
</div>


<div id="cy-toast-area" class="cy-toast-area" aria-live="polite" aria-atomic="true"></div>

<script>
window.CY_CSRF = <?= json_encode($token) ?>;
/* Sunucudaki sabitleri istemciye TAŞIYORUZ, elle kopyalamıyoruz.
 * İki taraf ayrışırsa ekranda yazan sayı ile davranış birbirini
 * tutmaz — ve hangisinin doğru olduğu belli olmaz. */
window.CY_MAX_ATTEMPTS = <?= (int) JOB_MAX_ATTEMPTS ?>;
window.CY_RESERVE_TTL  = <?= (int) RESERVE_TTL ?>;
</script>
<script src="assets/js/jquery-3.7.0.js"></script>
<script src="assets/js/bootstrap.bundle.js"></script>
<script src="assets/js/queue.js"></script>
</body>
</html>
