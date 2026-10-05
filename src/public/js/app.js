// Foodtruck - petits comportements d'interface (sans dépendance ni étape de build)
(function () {
    'use strict';

    // Confirmation avant une action destructive : <button data-confirm="Message ?">
    document.addEventListener('click', function (event) {
        var button = event.target.closest('[data-confirm]');
        if (button && !window.confirm(button.getAttribute('data-confirm'))) {
            event.preventDefault();
        }
    });

    // Copier un lien : <input data-copy-source> + <button data-copy>
    document.addEventListener('click', function (event) {
        var button = event.target.closest('[data-copy]');
        if (!button) return;
        var input = button.parentElement.querySelector('[data-copy-source]');
        if (!input) return;
        input.select();
        var done = function () {
            button.textContent = 'Copié !';
            setTimeout(function () { button.textContent = 'Copier'; }, 2000);
        };
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(input.value).then(done);
        } else {
            document.execCommand('copy');
            done();
        }
    });

    // Catégorie → coefficient proposé par défaut
    var defaults = { 'adulte': 1, 'enfant': 0.6, 'tout-petit': 0 };
    var form = document.querySelector('[data-coefficients]');
    if (form) {
        try { defaults = JSON.parse(form.getAttribute('data-coefficients')); } catch (e) { /* valeurs par défaut */ }
    }

    document.addEventListener('change', function (event) {
        if (!event.target.matches('[data-category]')) return;
        var row = event.target.closest('.member-row');
        var input = row && row.querySelector('[data-coefficient]');
        if (input && defaults[event.target.value] !== undefined) {
            input.value = defaults[event.target.value];
            updateTotal();
        }
    });

    // Assistant : ajout / retrait de personnes et total des parts
    var container = document.querySelector('[data-members]');
    var template = document.querySelector('[data-member-template]');
    var totalEl = document.querySelector('[data-total-portions]');

    function updateTotal() {
        if (!container || !totalEl) return;
        var total = 0;
        container.querySelectorAll('[data-coefficient]').forEach(function (input) {
            total += parseFloat(input.value) || 0;
        });
        totalEl.textContent = (Math.round(total * 100) / 100).toString().replace('.', ',');
        var unit = document.querySelector('[data-total-unit]');
        if (unit) unit.textContent = total > 1 ? 'parts' : 'part';
    }

    if (container && template) {
        var next = container.querySelectorAll('[data-member-row]').length + 100;

        document.querySelector('[data-add-member]').addEventListener('click', function () {
            var html = template.innerHTML.replace(/__KEY__/g, String(next++));
            container.insertAdjacentHTML('beforeend', html);
            var rows = container.querySelectorAll('[data-member-row]');
            rows[rows.length - 1].querySelector('input[type=text]').focus();
            updateTotal();
        });

        container.addEventListener('click', function (event) {
            var button = event.target.closest('[data-remove-member]');
            if (!button) return;
            if (container.querySelectorAll('[data-member-row]').length <= 1) return;
            button.closest('[data-member-row]').remove();
            updateTotal();
        });

        container.addEventListener('input', function (event) {
            if (event.target.matches('[data-coefficient]')) updateTotal();
        });

        updateTotal();
    }
})();

// Lignes dynamiques génériques (recettes : ingrédients, étapes)
// <div data-rows="nom"> + <template data-row-template="nom"> (clé __KEY__) + <button data-add-row="nom">
(function () {
    'use strict';
    var counter = 1000;

    function renumber(container) {
        container.querySelectorAll('.step-number').forEach(function (el, i) { el.textContent = String(i + 1); });
    }

    document.querySelectorAll('[data-rows]').forEach(renumber);

    document.addEventListener('click', function (event) {
        var add = event.target.closest('[data-add-row]');
        if (add) {
            var name = add.getAttribute('data-add-row');
            var container = document.querySelector('[data-rows="' + name + '"]');
            var template = document.querySelector('[data-row-template="' + name + '"]');
            if (!container || !template) return;
            container.insertAdjacentHTML('beforeend', template.innerHTML.replace(/__KEY__/g, String(counter++)));
            var rows = container.querySelectorAll('[data-row]');
            var first = rows[rows.length - 1].querySelector('input[type=text], textarea');
            if (first) first.focus();
            renumber(container);
            return;
        }

        var remove = event.target.closest('[data-remove-row]');
        if (remove) {
            var holder = remove.closest('[data-rows]');
            if (!holder) return;
            if (holder.querySelectorAll('[data-row]').length <= 1) {
                remove.closest('[data-row]').querySelectorAll('input, textarea').forEach(function (el) {
                    if (el.type === 'checkbox') el.checked = false; else el.value = '';
                });
                return;
            }
            remove.closest('[data-row]').remove();
            renumber(holder);
        }
    });
})();

// ---- v0.8.0 : liste de courses partagée (cases cochées visibles en direct par toute la famille) ----
(function () {
    'use strict';

    var root = document.querySelector('[data-shopping]');
    if (!root) return;

    var stateUrl = root.getAttribute('data-state-url');
    var revision = parseInt(root.getAttribute('data-revision'), 10);
    var me = root.getAttribute('data-me') || '';
    var pending = {};

    function setChecked(li, checked, by) {
        li.classList.toggle('is-checked', checked);
        var input = li.querySelector('input[name=checked]');
        if (input) input.value = checked ? '0' : '1';
        var button = li.querySelector('.sl-box');
        if (button) button.setAttribute('aria-pressed', checked ? 'true' : 'false');
        var label = li.querySelector('[data-by]');
        if (label) label.textContent = checked && by ? '✓ ' + by : '';
    }

    function recount() {
        var total = 0, done = 0;
        root.querySelectorAll('[data-store-block]').forEach(function (block) {
            var items = block.querySelectorAll('[data-item]');
            var checked = block.querySelectorAll('[data-item].is-checked').length;
            var counter = block.querySelector('[data-store-done]');
            if (counter) counter.textContent = checked;
            total += items.length;
            done += checked;
        });
        var doneEl = root.querySelector('[data-done]');
        var countEl = root.querySelector('[data-count]');
        if (doneEl) doneEl.textContent = done;
        if (countEl) countEl.textContent = total;
    }

    // Cocher / décocher : mise à jour immédiate, puis confirmation du serveur
    root.addEventListener('submit', function (event) {
        var form = event.target.closest('[data-check-form]');
        if (!form || !window.fetch) return;
        event.preventDefault();

        var li = form.closest('[data-item]');
        var id = li.getAttribute('data-item');
        var next = form.querySelector('input[name=checked]').value === '1';
        var body = new FormData(form); // avant de basculer la valeur du champ caché
        pending[id] = true;
        setChecked(li, next, me);
        recount();

        fetch(form.action, {
            method: 'POST',
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: body,
            credentials: 'same-origin'
        }).then(function (response) {
            if (!response.ok) throw new Error('HTTP ' + response.status);
            return response.json();
        }).then(function (data) {
            setChecked(li, !!data.checked, data.by);
        }).catch(function () {
            setChecked(li, !next, null);
            window.alert('La case n\'a pas pu être enregistrée (connexion perdue ?). Réessaie.');
        }).then(function () {
            delete pending[id];
            recount();
        });
    });

    // Synchronisation : les cases cochées par les autres apparaissent toutes seules
    function poll() {
        if (document.hidden || !window.fetch) return;
        fetch(stateUrl, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
            .then(function (response) { return response.ok ? response.json() : null; })
            .then(function (state) {
                if (!state) return;
                var checked = state.checked || {};
                root.querySelectorAll('[data-item]').forEach(function (li) {
                    var id = li.getAttribute('data-item');
                    if (pending[id] || !checked[id]) return;
                    var isChecked = checked[id][0] === 1;
                    if (li.classList.contains('is-checked') !== isChecked || (isChecked && checked[id][1])) {
                        setChecked(li, isChecked, checked[id][1]);
                    }
                });
                recount();

                var changed = root.querySelector('[data-changed]');
                if (changed && state.revision !== revision) changed.hidden = false;
                var stale = root.querySelector('[data-stale-note]');
                if (stale) stale.hidden = !state.stale;
            })
            .catch(function () { /* réseau coupé en magasin : on réessaiera */ });
    }

    setInterval(poll, 6000);
    document.addEventListener('visibilitychange', function () { if (!document.hidden) poll(); });

    // Masquer les articles cochés (préférence gardée sur cet appareil)
    var hide = root.querySelector('[data-hide-checked]');
    if (hide) {
        try { hide.checked = window.localStorage.getItem('ft-hide-checked') === '1'; } catch (e) { /* stockage indisponible */ }
        root.classList.toggle('hide-checked', hide.checked);
        hide.addEventListener('change', function () {
            root.classList.toggle('hide-checked', hide.checked);
            try { window.localStorage.setItem('ft-hide-checked', hide.checked ? '1' : '0'); } catch (e) { /* ignoré */ }
        });
    }

    recount();
})();
