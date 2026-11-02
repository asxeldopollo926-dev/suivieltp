/* SuiviLTP — comportements communs (menu mobile, modales, confirmations, AJAX sécurisé) */
(function () {
    'use strict';

    var csrfMeta = document.querySelector('meta[name="csrf-token"]');
    var CSRF = csrfMeta ? csrfMeta.getAttribute('content') : '';

    // ------------------------------------------------------------------
    // Fenêtres modales : data-modal-open="id", data-modal-close, clic extérieur, Échap
    // ------------------------------------------------------------------
    function openModal(modal) {
        if (typeof modal === 'string') { modal = document.getElementById(modal); }
        if (!modal) { return; }
        modal.classList.add('open');
        var focusable = modal.querySelector('input:not([type=hidden]), select, textarea, button');
        if (focusable) { setTimeout(function () { focusable.focus(); }, 30); }
    }
    function closeModal(modal) {
        if (typeof modal === 'string') { modal = document.getElementById(modal); }
        if (modal) { modal.classList.remove('open'); }
    }

    document.addEventListener('click', function (event) {
        var opener = event.target.closest('[data-modal-open]');
        if (opener) {
            event.preventDefault();
            openModal(opener.getAttribute('data-modal-open'));
            return;
        }
        var closer = event.target.closest('[data-modal-close]');
        if (closer) {
            event.preventDefault();
            closeModal(closer.closest('.modal'));
            return;
        }
        if (event.target.classList && event.target.classList.contains('modal')) {
            closeModal(event.target);
        }
        if (event.target.closest('.menu-toggle')) {
            document.body.classList.toggle('sidebar-open');
        }
        if (event.target.classList && event.target.classList.contains('sidebar-backdrop')) {
            document.body.classList.remove('sidebar-open');
        }
        var dismiss = event.target.closest('.alert-close');
        if (dismiss) {
            dismiss.closest('.alert').remove();
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            document.querySelectorAll('.modal.open').forEach(closeModal);
            document.body.classList.remove('sidebar-open');
        }
    });

    // ------------------------------------------------------------------
    // Confirmation avant une action sensible : <form data-confirm="Message ?">
    // ------------------------------------------------------------------
    var confirmModal = null;
    function buildConfirmModal() {
        confirmModal = document.createElement('div');
        confirmModal.className = 'modal';
        confirmModal.innerHTML =
            '<div class="modal-dialog" role="alertdialog" aria-modal="true" style="max-width:440px">' +
            '<div class="modal-header"><h2>Confirmation</h2></div>' +
            '<div class="modal-body"><p class="confirm-message mb-0"></p></div>' +
            '<div class="modal-footer">' +
            '<button type="button" class="btn btn-secondary" data-confirm-cancel>Annuler</button>' +
            '<button type="button" class="btn btn-danger" data-confirm-ok>Confirmer</button>' +
            '</div></div>';
        document.body.appendChild(confirmModal);
    }

    function askConfirm(message, okLabel) {
        if (!confirmModal) { buildConfirmModal(); }
        confirmModal.querySelector('.confirm-message').textContent = message;
        var ok = confirmModal.querySelector('[data-confirm-ok]');
        var cancel = confirmModal.querySelector('[data-confirm-cancel]');
        ok.textContent = okLabel || 'Confirmer';
        return new Promise(function (resolve) {
            function done(value) {
                ok.removeEventListener('click', onOk);
                cancel.removeEventListener('click', onCancel);
                closeModal(confirmModal);
                resolve(value);
            }
            function onOk() { done(true); }
            function onCancel() { done(false); }
            ok.addEventListener('click', onOk);
            cancel.addEventListener('click', onCancel);
            openModal(confirmModal);
            setTimeout(function () { cancel.focus(); }, 30);
        });
    }

    document.addEventListener('submit', function (event) {
        var form = event.target;
        var message = form.getAttribute('data-confirm');
        if (!message || form.dataset.confirmed === '1') { return; }
        event.preventDefault();
        askConfirm(message, form.getAttribute('data-confirm-label')).then(function (yes) {
            if (yes) {
                form.dataset.confirmed = '1';
                if (form.requestSubmit) { form.requestSubmit(event.submitter || undefined); } else { form.submit(); }
                setTimeout(function () { form.dataset.confirmed = ''; }, 0);
            }
        });
    }, true);

    // ------------------------------------------------------------------
    // Envoi automatique d'un formulaire lors d'un changement : <select data-autosubmit>
    // ------------------------------------------------------------------
    document.addEventListener('change', function (event) {
        if (event.target.matches('[data-autosubmit]')) {
            var form = event.target.form;
            if (form.requestSubmit) { form.requestSubmit(); } else { form.submit(); }
        }
    });

    // ------------------------------------------------------------------
    // Copier dans le presse-papiers : <button data-copy="texte">
    // ------------------------------------------------------------------
    document.addEventListener('click', function (event) {
        var btn = event.target.closest('[data-copy]');
        if (!btn || !navigator.clipboard) { return; }
        navigator.clipboard.writeText(btn.getAttribute('data-copy')).then(function () {
            var old = btn.innerHTML;
            btn.textContent = 'Copié !';
            setTimeout(function () { btn.innerHTML = old; }, 1200);
        });
    });

    // ------------------------------------------------------------------
    // AJAX avec jeton CSRF : App.post(url, FormData|objet) -> Promise<json>
    // ------------------------------------------------------------------
    function post(url, data) {
        var body = data instanceof FormData ? data : new URLSearchParams(data || {});
        return fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'X-CSRF-Token': CSRF, 'Accept': 'application/json' },
            body: body
        }).then(function (response) {
            return response.json().catch(function () {
                return { success: false, message: 'Réponse inattendue du serveur.' };
            });
        });
    }

    // ------------------------------------------------------------------
    // Listes liées Classe / Série : la série est désactivée pour les classes qui n'en ont pas
    // (ex. 6e à 3e au collège). Rendu côté serveur par classe_serie_selects().
    // ------------------------------------------------------------------
    function syncSerie(classeSelect) {
        var scope = classeSelect.form || classeSelect.closest('form, .filters, tr') || document;
        var serie = scope.querySelector('[data-serie-select]');
        if (!serie) { return; }
        var sans = [];
        try { sans = JSON.parse(classeSelect.getAttribute('data-sans-serie') || '[]'); } catch (e) { sans = []; }
        var noSerie = classeSelect.value !== '' && sans.indexOf(classeSelect.value) !== -1;
        if (serie.dataset.wasRequired === undefined) { serie.dataset.wasRequired = serie.required ? '1' : '0'; }
        if (noSerie) {
            serie.value = '';
            serie.disabled = true;
            serie.required = false;
        } else {
            serie.disabled = false;
            serie.required = serie.dataset.wasRequired === '1';
        }
    }
    document.querySelectorAll('[data-classe-select]').forEach(syncSerie);
    document.addEventListener('change', function (event) {
        if (event.target.matches('[data-classe-select]')) { syncSerie(event.target); }
    }, true);

    window.App = { openModal: openModal, closeModal: closeModal, confirm: askConfirm, post: post, csrf: CSRF };
})();
