/* =====================================================================
 *  İŞ KUYRUĞU – ARAYÜZ MANTIĞI
 *  cilginyazilim.com – Veritabanı Tabanlı İş Kuyruğu
 * ---------------------------------------------------------------------
 *  BU DOSYANIN ÖĞRETTİĞİ ASIL KAVRAM: BU EKRAN BİR WORKER DEĞİLDİR.
 *
 *  "Worker'ı bir kez tetikle" düğmesi cron'un YERİNE GEÇMEZ; kuyruğun
 *  davranışını adım adım görebilmek için vardır. Gerçek kurulumda
 *  `php bin/worker.php` sürekli çalışır ve hiç kimse tarayıcı açmaz.
 *
 *  Düğmenin ikinci bir işlevi daha var ve o da eğitseldir: bir işi web
 *  isteğinin İÇİNDE çalıştırmanın neden kötü fikir olduğunu gösterir.
 *  generate_report işini tetikleyin — istek, işin süresi kadar bekler.
 *  Kuyruğun var olma sebebi tam olarak budur.
 *
 *  İKİNCİ KAVRAM: OTOMATİK TAZELEME, İŞLEM SIRASINI BOZMAMALIDIR.
 *  Ekran 2 saniyede bir tazelenir. Kullanıcı tam o anda bir düğmeye
 *  basarsa iki istek çakışabilir; bu yüzden her yazma işleminden SONRA
 *  tazeleme çağrılır ve tazeleme sırasında yeni bir tazeleme
 *  başlatılmaz (busy bayrağı).
 *
 *  BÖLÜMLER
 *    1  Yardımcılar
 *    2  Durum tazeleme ve çizim
 *    3  Kuyruğa ekleme
 *    4  Worker tetikleme ve otomatik mod
 *    5  Dead-letter işlemleri
 *    6  İş detayı ve derin bağlantı
 * ================================================================== */

/* global jQuery, bootstrap */

(function ($) {
    'use strict';

    var ENDPOINT = 'system/ajax.php';

    var MAX_ATTEMPTS = window.CY_MAX_ATTEMPTS || 3;
    var RESERVE_TTL  = window.CY_RESERVE_TTL || 90;

    var autoTimer = null;
    var refreshing = false;      // aynı anda iki tazeleme olmasın
    var currentQueue = 'default';
    var modalJob;

    /* =================================================================
     *  BÖLÜM 1 – YARDIMCILAR
     * ================================================================= */

    function toast(msg, type) {
        var el = $('<div class="cy-toast cy-toast--' + (type || 'info') + '" role="status"></div>').text(msg);

        $('#cy-toast-area').append(el);

        setTimeout(function () {
            el.addClass('cy-toast--out');
            setTimeout(function () { el.remove(); }, 300);
        }, 3600);
    }

    function post(action, data) {
        data = data || {};
        data.action = action;
        data.csrf_token = window.CY_CSRF;

        // Kuyruk adı HER isteğe eklenir: sunucu onu beyaz listeden geçirir.
        if (data.queue === undefined) {
            data.queue = currentQueue;
        }

        return $.ajax({ url: ENDPOINT, method: 'POST', data: data, dataType: 'json' });
    }

    function failMessage(xhr) {
        return (xhr.responseJSON || {}).description || 'Beklenmeyen bir hata oluştu.';
    }

    /** İş türü, hata metni, payload — hepsi kullanıcı verisidir. */
    function esc(s) {
        return $('<i>').text(s == null ? '' : s).html();
    }

    function busy($btn, on) {
        $btn.prop('disabled', on).toggleClass('is-busy', on);
    }

    /** Saniyeyi okunur süreye çevirir: 95 → "1 dk 35 sn" */
    function humanSeconds(s) {
        if (s < 60) { return s + ' sn'; }
        var m = Math.floor(s / 60);
        var r = s % 60;
        return r === 0 ? m + ' dk' : m + ' dk ' + r + ' sn';
    }

    function logLine(text, kind) {
        var $log = $('#log');
        var stamp = new Date().toTimeString().slice(0, 8);
        var icon = { done: '✓', retry: '↻', failed: '✗', empty: '·' }[kind] || '·';

        // Günlük sınırsız büyümesin: son 200 satır yeter.
        var lines = ($log.text() === 'worker günlüğü…' ? '' : $log.text()).split('\n');
        lines.push('[' + stamp + '] ' + icon + ' ' + text);
        if (lines.length > 200) {
            lines = lines.slice(-200);
        }

        $log.text(lines.join('\n').replace(/^\n/, ''));
        $log.scrollTop($log[0].scrollHeight);
    }

    /* =================================================================
     *  BÖLÜM 2 – DURUM TAZELEME VE ÇİZİM
     * ================================================================= */

    function refresh() {
        if (refreshing) { return; }
        refreshing = true;

        post('status')
            .done(render)
            .fail(function (xhr) {
                // Otomatik mod açıkken her hatada toast basmak ekranı doldurur.
                if (!$('#autotick').is(':checked')) {
                    toast(failMessage(xhr), 'danger');
                }
            })
            .always(function () { refreshing = false; });
    }

    function render(res) {
        var c = res.counts;

        $('#stat-ready').text(c.ready);
        $('#stat-delayed').text(c.delayed);
        $('#stat-reserved').text(c.reserved);
        $('#stat-stale').text(c.stale);
        $('#stat-failed').text(c.failed);
        $('#stat-total-badge').text(c.total);
        $('#pending-badge').text(c.total + ' iş');

        /* Bayat rezerve sayacı sıfırdan büyükse VURGULANIR: o sayı,
         * kuyrukta kimsenin işlemediği bir iş olduğu anlamına gelir. */
        $('.cy-stat--stale').toggleClass('is-warn', c.stale > 0);
        $('#btn-release').prop('disabled', c.stale === 0);

        renderPending(res.pending);
        renderFailed(res.failed);
    }

    function renderPending(rows) {
        var $b = $('#pending').empty();

        if (!rows.length) {
            $b.append('<tr><td colspan="4" class="cy-empty-cell">Bu kuyrukta bekleyen iş yok.</td></tr>');
            return;
        }

        rows.forEach(function (j) {
            /* Bir işin durumu tek bir sütun değil, ÜÇ alanın birleşimidir:
             *   reserved_at + bayatlık  → işleniyor / bayat
             *   available_at gelecekte  → gecikmeli (kaç sn kaldı)
             *   ikisi de değilse        → hazır */
            var state, cls;

            if (j.stale) {
                /* Metin KISA tutulur: "bayat rezerve (90 sn geçti)" dar
                 * ekranda tabloyu taşırıyordu. Süre bilgisi zaten sayaç
                 * şeridinde ve detay modalında duruyor; satırın işi
                 * durumu bir bakışta söylemek. */
                state = 'bayat rezerve';
                cls = 'stale';
            } else if (j.reserved) {
                state = 'işleniyor';
                cls = 'reserved';
            } else if (j.in_seconds > 0) {
                state = humanSeconds(j.in_seconds) + ' sonra';
                cls = 'delayed';
            } else {
                state = 'hazır';
                cls = 'ready';
            }

            $b.append(
                '<tr class="js-job" data-id="' + j.id + '" data-source="jobs" tabindex="0">' +
                    '<td><span class="cy-id">#' + j.id + '</span></td>' +
                    '<td><code class="cy-type">' + esc(j.type) + '</code></td>' +
                    '<td><span class="cy-attempts' + (j.attempts >= MAX_ATTEMPTS - 1 ? ' is-last' : '') + '">' +
                        j.attempts + '<i>/</i>' + MAX_ATTEMPTS + '</span>' +
                        (j.has_error ? ' <span class="cy-warn-dot" title="Son denemede hata aldı">!</span>' : '') +
                    '</td>' +
                    '<td><span class="cy-state cy-state--' + cls + '"' +
                        (j.stale ? ' title="Rezervasyon ' + RESERVE_TTL + ' saniyeden eski — worker yanıt vermiyor"' : '') +
                        '>' + esc(state) + '</span></td>' +
                '</tr>'
            );
        });
    }

    function renderFailed(rows) {
        var $b = $('#failed').empty();

        if (!rows.length) {
            $b.append('<tr><td colspan="4" class="cy-empty-cell">Başarısız iş yok.</td></tr>');
            return;
        }

        rows.forEach(function (f) {
            /* Hata metni kısaltılır ama title'da tamamı durur; uzun bir
             * exception, tabloyu okunamaz hâle getirirdi. */
            var short = f.exception.length > 60 ? f.exception.slice(0, 60) + '…' : f.exception;

            $b.append(
                '<tr class="js-job" data-id="' + f.id + '" data-source="failed" tabindex="0">' +
                    '<td><span class="cy-id">#' + f.id + '</span></td>' +
                    '<td><code class="cy-type">' + esc(f.type) + '</code></td>' +
                    '<td><span class="cy-exception" title="' + esc(f.exception) + '">' + esc(short) + '</span></td>' +
                    '<td class="text-end">' +
                        '<div class="cy-actions">' +
                            '<button type="button" class="cy-btn-icon cy-btn-icon--retry js-retry" data-id="' + f.id +
                                '" title="Yeniden kuyruğa al" aria-label="' + f.id + ' numaralı işi yeniden kuyruğa al">↻</button>' +
                            '<button type="button" class="cy-btn-icon cy-btn-icon--delete js-forget" data-id="' + f.id +
                                '" title="Kalıcı sil" aria-label="' + f.id + ' numaralı işi sil">🗑</button>' +
                        '</div>' +
                    '</td>' +
                '</tr>'
            );
        });
    }

    /* =================================================================
     *  BÖLÜM 3 – KUYRUĞA EKLEME
     * ================================================================= */

    $('#btn-enqueue').on('click', function () {
        var $b = $(this);
        busy($b, true);

        post('enqueue', {
            type:  $('#type').val(),
            count: $('#count').val(),
            delay: $('#delay').val(),
            queue: $('#queue').val()
        })
            .done(function (res) {
                toast(res.description, 'success');

                /* Eklenen iş başka bir kuyruğa gittiyse ekranı O kuyruğa
                 * çeviriyoruz. Aksi hâlde kullanıcı "ekledim ama listede
                 * yok" der ve haklı olur. */
                if (res.queue !== currentQueue) {
                    currentQueue = res.queue;
                    toast('Görüntülenen kuyruk: ' + res.queue, 'info');
                }

                refresh();
            })
            .fail(function (xhr) { toast(failMessage(xhr), 'danger'); })
            .always(function () { busy($b, false); });
    });

    // Kuyruk seçimi değişince görüntülenen kuyruk da değişsin.
    $('#queue').on('change', function () {
        currentQueue = $(this).val();
        refresh();
    });

    /* =================================================================
     *  BÖLÜM 4 – WORKER TETİKLEME
     * ================================================================= */

    function tick(silent) {
        return post('work_once')
            .done(function (res) {
                if (res.status === 'empty') {
                    if (!silent) {
                        logLine('Kuyrukta işlenecek iş yok. (' + res.took_ms + ' ms)', 'empty');
                    }
                    return;
                }

                logLine(res.message + '  (' + res.took_ms + ' ms)', res.status);
                refresh();
            })
            .fail(function (xhr) {
                logLine('HATA: ' + failMessage(xhr), 'failed');

                // Hız sınırına takıldıysak otomatik modu durdur.
                if (xhr.status === 429) {
                    $('#autotick').prop('checked', false).trigger('change');
                }
            });
    }

    $('#btn-tick, #add_button').on('click', function () {
        var $b = $(this);
        busy($b, true);
        tick(false).always(function () { busy($b, false); });
    });

    $('#autotick').on('change', function () {
        clearInterval(autoTimer);

        if (!this.checked) {
            logLine('Otomatik mod kapatıldı.', 'empty');
            return;
        }

        logLine('Otomatik mod açık: 2 saniyede bir bir iş işlenecek.', 'empty');

        /* Otomatik modda "boş kuyruk" satırlarını günlüğe basmıyoruz
         * (silent = true); yoksa kuyruk boşaldığında günlük saniyede bir
         * "iş yok" satırıyla dolar ve gerçek satırlar kaybolur. */
        autoTimer = setInterval(function () { tick(true); }, 2000);
    });

    $('#btn-release').on('click', function () {
        var $b = $(this);
        busy($b, true);

        post('release_stale')
            .done(function (res) {
                toast(res.description, res.released > 0 ? 'success' : 'info');
                if (res.released > 0) {
                    logLine(res.description, 'retry');
                }
                refresh();
            })
            .fail(function (xhr) { toast(failMessage(xhr), 'danger'); })
            .always(function () { busy($b, false); });
    });

    /* =================================================================
     *  BÖLÜM 5 – DEAD-LETTER İŞLEMLERİ
     * ================================================================= */

    $('#failed').on('click', '.js-retry', function (ev) {
        // stopPropagation: satırın kendisi detay modalını açıyor.
        ev.stopPropagation();

        var $b = $(this);
        busy($b, true);

        post('retry_failed', { id: $b.data('id') })
            .done(function (res) {
                toast(res.description, 'success');
                logLine(res.description, 'retry');
                refresh();
            })
            .fail(function (xhr) { toast(failMessage(xhr), 'danger'); })
            .always(function () { busy($b, false); });
    });

    $('#failed').on('click', '.js-forget', function (ev) {
        ev.stopPropagation();

        var $b = $(this);
        busy($b, true);

        post('forget_failed', { id: $b.data('id') })
            .done(function (res) { toast(res.description, 'success'); refresh(); })
            .fail(function (xhr) { toast(failMessage(xhr), 'danger'); })
            .always(function () { busy($b, false); });
    });

    $('#btn-clear-failed').on('click', function () {
        var $b = $(this);
        busy($b, true);

        post('clear_failed')
            .done(function (res) { toast(res.description, 'success'); refresh(); })
            .fail(function (xhr) { toast(failMessage(xhr), 'danger'); })
            .always(function () { busy($b, false); });
    });

    /* =================================================================
     *  BÖLÜM 6 – İŞ DETAYI VE DERİN BAĞLANTI
     * ================================================================= */

    function row(label, value) {
        return '<dt>' + esc(label) + '</dt><dd>' + value + '</dd>';
    }

    function openJob(id, source, updateHash) {
        post('job_detail', { id: id, source: source })
            .done(function (res) {
                var j = res.job;

                $('#modal-job-title').text('İş #' + j.id);

                var badges =
                    '<span class="cy-badge cy-badge--soft"><code>' + esc(j.type) + '</code></span>' +
                    '<span class="cy-badge cy-badge--soft">kuyruk: ' + esc(j.queue) + '</span>' +
                    '<span class="cy-badge cy-badge--soft">deneme: ' + j.attempts + '/' + MAX_ATTEMPTS + '</span>';

                if (j.source === 'failed') {
                    badges += '<span class="cy-state cy-state--failed">dead-letter</span>';
                } else if (j.stale) {
                    badges += '<span class="cy-state cy-state--stale">bayat rezerve</span>';
                } else if (j.reserved_at) {
                    badges += '<span class="cy-state cy-state--reserved">işleniyor</span>';
                } else if (j.in_seconds > 0) {
                    badges += '<span class="cy-state cy-state--delayed">gecikmeli</span>';
                } else {
                    badges += '<span class="cy-state cy-state--ready">hazır</span>';
                }

                $('#job-badges').html(badges);

                var meta;
                if (j.source === 'failed') {
                    meta = row('Başarısız oldu', esc(j.failed_at)) +
                           row('Toplam deneme', '<b>' + j.attempts + '</b>');
                } else {
                    meta = row('Oluşturuldu', esc(j.created_at)) +
                           row('İşlenebilir', esc(j.available_at) +
                               (j.in_seconds > 0 ? ' <b>(' + humanSeconds(j.in_seconds) + ' sonra)</b>' : ' <b>(şimdi)</b>')) +
                           row('Rezerve', j.reserved_at
                               ? esc(j.reserved_at) + ' — <code>' + esc(j.reserved_by) + '</code>'
                               : '<span class="cy-muted">hayır</span>') +
                           row('Sonraki bekleme', j.next_backoff + ' sn');
                }

                $('#job-meta').html(meta);
                $('#job-payload').text(j.payload);

                var err = j.source === 'failed' ? j.exception : j.last_error;
                $('#job-error-wrap').toggleClass('d-none', !err);
                $('#job-error').text(err || '');

                $('#job-note').text(
                    j.source === 'failed'
                        ? 'Bu iş deneme hakkını tüketti ve kuyruktan çıkarıldı. Yeniden kuyruğa almadan önce hata metnini okuyun: kalıcı bir hata aynı sonucu verir.'
                        : (j.stale
                            ? 'Bu işi alan worker yanıt vermiyor. Rezervasyon süresi dolduğu için iş bir sonraki tetiklemede yeniden alınabilir.'
                            : (j.in_seconds > 0
                                ? 'Bu iş henüz sırası gelmediği için bekliyor; available_at alanı gelecekte.'
                                : 'Bu iş işlenmeye hazır — bir sonraki worker turunda alınacak.'))
                );

                if (updateHash !== false) {
                    history.replaceState(null, '', location.pathname + '#is-' + j.id);
                }

                modalJob.show();
            })
            .fail(function (xhr) { toast(failMessage(xhr), 'danger'); });
    }

    $(document).on('click', '.js-job', function () {
        openJob($(this).data('id'), $(this).data('source'));
    });

    // Klavyeyle de açılabilmeli: satırlar tabindex="0" taşır.
    $(document).on('keydown', '.js-job', function (ev) {
        if (ev.key === 'Enter' || ev.key === ' ') {
            ev.preventDefault();
            openJob($(this).data('id'), $(this).data('source'));
        }
    });

    // Modal kapanınca #is-42 adresten silinir; geride kalması yanıltıcı olur.
    $('#modal-job').on('hidden.bs.modal', function () {
        if (location.hash) {
            history.replaceState(null, '', location.pathname);
        }
    });

    /* =================================================================
     *  AÇILIŞ
     * ================================================================= */

    $(function () {
        modalJob = new bootstrap.Modal(document.getElementById('modal-job'));

        currentQueue = $('#queue').val() || 'default';

        refresh();

        /* Ekran 2 saniyede bir kendini tazeler. Bu bir "canlı kuyruk"
         * ekranıdır: gecikmeli işlerin geri sayımı ve rezervasyonların
         * bayatlaması ancak böyle görünür. */
        setInterval(refresh, 2000);

        var m = /^#is-(\d+)$/.exec(location.hash);
        if (m) {
            // Kaynağı bilmiyoruz; önce kuyrukta ara, bulunamazsa dead-letter'a bak.
            post('job_detail', { id: parseInt(m[1], 10), source: 'jobs' })
                .done(function () { openJob(parseInt(m[1], 10), 'jobs', false); })
                .fail(function () { openJob(parseInt(m[1], 10), 'failed', false); });
        }
    });

})(jQuery);
