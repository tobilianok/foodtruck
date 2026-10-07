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

    // Filtres : la liste se met à jour dès qu'on coche ou choisit : <form data-autofilter>
    document.addEventListener('change', function (event) {
        var form = event.target.closest('form[data-autofilter]');
        if (form && event.target.type !== 'search') form.submit();
    });

    // Un lien vers une section repliée l'ouvre : <a href="#avance">
    function openTarget() {
        var el = location.hash.length > 1 ? document.getElementById(decodeURIComponent(location.hash.slice(1))) : null;
        if (el && el.tagName === 'DETAILS') el.open = true;
    }
    window.addEventListener('hashchange', openTarget);
    openTarget();

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

    // Âge → coefficient : grille [[âge limite, coefficient], …] (identique à la grille serveur)
    var grid = [[1, 0], [3, 0.3], [5, 0.5], [12, 0.7], [15, 0.8], [200, 1]];
    var formWithGrid = document.querySelector('[data-age-grid]');
    if (formWithGrid) {
        try { grid = JSON.parse(formWithGrid.getAttribute('data-age-grid')); } catch (e) { /* grille par défaut */ }
    }

    function coefficientForBirth(value) {
        var birth = new Date(value + 'T00:00:00');
        if (isNaN(birth.getTime())) return null;
        var today = new Date();
        var years = today.getFullYear() - birth.getFullYear();
        if (today.getMonth() < birth.getMonth() || (today.getMonth() === birth.getMonth() && today.getDate() < birth.getDate())) years--;
        years = Math.max(0, years);
        for (var i = 0; i < grid.length; i++) {
            if (years < grid[i][0]) return grid[i][1];
        }
        return grid[grid.length - 1][1];
    }

    // Une personne : date de naissance saisie → coefficient automatique (verrouillé) sauf « Régler à la main »
    function syncMember(row) {
        var birth = row.querySelector('[data-birth]');
        var input = row.querySelector('[data-coefficient]');
        var manual = row.querySelector('[data-manual]');
        var wrap = row.querySelector('[data-manual-wrap]');
        if (!birth || !input) return;

        var auto = birth.value ? coefficientForBirth(birth.value) : null;
        if (wrap) wrap.hidden = auto === null;
        if (auto !== null && !(manual && manual.checked)) {
            input.value = auto;
            input.readOnly = true;
        } else {
            input.readOnly = false;
        }
    }

    document.addEventListener('change', function (event) {
        if (!event.target.matches('[data-birth], [data-manual]')) return;
        var row = event.target.closest('.member-row');
        if (row) syncMember(row);
        updateTotal();
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

// v0.15.2 / v0.16.0 : liste des unités de chaque ligne (unités propres de l'ingrédient choisi), filtre « lignes à vérifier »
(function () {
    'use strict';

    var unitsData = document.getElementById('ingredient-units');
    var ownUnits = {};
    try { ownUnits = unitsData ? JSON.parse(unitsData.textContent) : {}; } catch (e) { ownUnits = {}; }

    function refreshUnits(row, name) {
        var group = row.querySelector('[data-own-units]');
        var select = row.querySelector('[data-unit-select]');
        if (!group || !select) return;
        var current = select.value;
        var catalog = window.foodtruckCatalog || {};
        var units = (catalog[name] && catalog[name].units) || ownUnits[name] || {};
        group.innerHTML = '';
        Object.keys(units).forEach(function (code) {
            var option = document.createElement('option');
            option.value = code;
            option.textContent = units[code];
            group.appendChild(option);
        });
        group.hidden = group.children.length === 0;
        select.value = Array.prototype.some.call(select.options, function (o) { return o.value === current; }) ? current : (current.indexOf('u:') === 0 ? '' : current);
    }

    // « foodtruck:units » : demandé par les fenêtres de correction après le choix ou la création d'un ingrédient
    ['change', 'foodtruck:units'].forEach(function (type) {
        document.addEventListener(type, function (event) {
            var input = event.target.closest && event.target.closest('[data-ingredient-name]');
            if (!input) return;
            var row = input.closest('[data-row]');
            if (row) refreshUnits(row, input.value.trim());
        });
    });

    document.addEventListener('change', function (event) {
        var toggle = event.target.closest('[data-only-problems]');
        if (!toggle) return;
        var rows = document.querySelector('.ingredient-rows');
        if (rows) rows.classList.toggle('only-problems', toggle.checked);
    });
})();

// v0.17.0 : corrections en fenêtre, sans quitter la recette (relecture d'une fiche Paperless ou saisie d'une recette).
// Ingrédient à choisir ou à créer, équivalence d'unité, quantité douteuse : la ligne passe au vert dès que c'est réglé.
(function () {
    'use strict';

    var dialog = document.getElementById('fix-dialog');
    var rowsBox = document.querySelector('.ingredient-rows');
    if (!dialog || !rowsBox || typeof dialog.showModal !== 'function') return;

    function readJson(id, fallback) {
        var node = document.getElementById(id);
        try { return node ? JSON.parse(node.textContent) : fallback; } catch (e) { return fallback; }
    }

    var catalog = readJson('ingredient-catalog', {});
    var routes = readJson('fix-routes', {});
    window.foodtruckCatalog = catalog;
    var token = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
    var body = dialog.querySelector('[data-fix-body]');
    var errorBox = dialog.querySelector('[data-fix-error]');
    var titleBox = dialog.querySelector('#fix-title');

    var WORDS = {
        sachet: 'sachet', paquet: 'paquet', pot: 'pot', boite: 'boîte', brique: 'brique', barquette: 'barquette', bouteille: 'bouteille',
        botte: 'botte', bouquet: 'bouquet', brin: 'brin', branche: 'branche', tige: 'tige', feuille: 'feuille', gousse: 'gousse',
        tete: 'tête', tranche: 'tranche', morceau: 'morceau', cm: 'cm', poignee: 'poignée', noix: 'noix', noisette: 'noisette',
        carre: 'carré', tablette: 'tablette', rouleau: 'rouleau', cube: 'cube', pave: 'pavé', filet: 'filet', boule: 'boule', portion: 'portion'
    };
    var FRACTIONS = [['¼', 0.25], ['⅓', 0.333], ['½', 0.5], ['⅔', 0.667], ['¾', 0.75], ['1', 1], ['1 ½', 1.5], ['2', 2]];

    // ---------------------------------------------------------------- outils
    function make(tag, attrs, children) {
        var node = document.createElement(tag);
        Object.keys(attrs || {}).forEach(function (key) {
            if (key === 'text') node.textContent = attrs[key];
            else if (key === 'class') node.className = attrs[key];
            else node.setAttribute(key, attrs[key]);
        });
        (children || []).forEach(function (child) { if (child) node.appendChild(child); });
        return node;
    }
    function slug(text) {
        return (text || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');
    }
    function find(name) {
        name = (name || '').trim();
        if (catalog[name]) return catalog[name];
        var key = slug(name);
        var hit = null;
        Object.keys(catalog).forEach(function (n) { if (!hit && catalog[n].slug === key) hit = catalog[n]; });
        return hit;
    }
    function field(row, name) { return row.querySelector('[name$="[' + name + ']"]'); }
    function number(text) {
        var value = parseFloat(String(text || '').replace(',', '.'));
        return isNaN(value) ? null : value;
    }
    function show(value) {
        return String(Math.round(value * 1000) / 1000).replace('.', ',');
    }
    function noteWord(row) {
        var note = field(row, 'note');
        var first = slug((note && note.value || '').split(/[\s,(]+/)[0]);
        if (WORDS[first]) return first;
        if (WORDS[first.replace(/x$/, '')]) return first.replace(/x$/, '');
        if (WORDS[first.replace(/s$/, '')]) return first.replace(/s$/, '');
        return null;
    }
    function stripNoteWord(row) {
        var note = field(row, 'note');
        if (note) note.value = note.value.replace(/^\S+\s*(?:de\s+|d['’]\s*)?[,;:]?\s*/, '').trim();
    }
    function unitLabel(code) {
        return routes.units && routes.units[code] ? routes.units[code].label : code;
    }

    function post(url, data) {
        return fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': token, 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify(data)
        }).then(function (response) {
            return response.json().catch(function () { return {}; }).then(function (json) {
                if (response.status === 419) throw new Error('Session expirée : recharge la page (tes corrections déjà faites sont gardées sur les ingrédients).');
                if (!response.ok) throw new Error(json.message || ('Erreur ' + response.status + ', réessaie.'));
                return json;
            });
        });
    }

    // ---------------------------------------------------------------- état des lignes
    function recount() {
        var left = rowsBox.querySelectorAll('.ingredient-row.has-problem').length;
        var text = document.querySelector('[data-check-text]');
        var summary = text && text.closest('.check-summary');
        if (text) {
            text.innerHTML = '';
            if (left === 0) {
                text.appendChild(make('strong', { text: '✓ Tout est vérifié' }));
                text.appendChild(document.createTextNode(' : tu peux valider la recette.'));
            } else {
                text.appendChild(make('strong', { text: left + ' ligne' + (left > 1 ? 's' : '') + ' à vérifier' }));
                text.appendChild(document.createTextNode(' : « Corriger » sur chaque ligne, la recette reste ouverte.'));
            }
            if (summary) summary.classList.toggle('is-done', left === 0);
        }
        var issue = document.querySelector('[data-problems-issue]');
        if (issue) {
            issue.textContent = left === 0 ? '✓ Tous les ingrédients sont vérifiés.' : (left === 1 ? '1 ingrédient à vérifier.' : left + ' ingrédients à vérifier.');
            issue.classList.toggle('is-done', left === 0);
        }
    }

    function warning(row, text, kind) {
        var box = row.querySelector('[data-row-warning]');
        if (!box) {
            box = make('div', { class: 'row-warning', 'data-row-warning': '' });
            var info = row.querySelector('[data-row-info]');
            row.insertBefore(box, info || null);
        }
        box.className = 'row-warning';
        box.innerHTML = '';
        box.appendChild(make('span', { class: 'row-warning-text' }, [make('strong', { text: 'À vérifier' }), document.createTextNode(' · ' + text)]));
        var labels = { unknown: 'Choisir ou créer l\'ingrédient', ask: 'Indiquer l\'équivalence' };
        box.appendChild(make('button', { type: 'button', class: 'btn btn-small fix-btn', 'data-fix': '', 'data-kind': kind, 'data-problem': text,
            'data-label': (field(row, 'label') && field(row, 'label').value) || field(row, 'name').value, text: labels[kind] || 'Corriger' }));
        row.classList.add('has-problem');
        row.classList.remove('is-resolved');
        recount();
        return box.querySelector('[data-fix]');
    }

    function resolved(row, message) {
        row.classList.remove('has-problem');
        row.classList.add('is-resolved');
        var box = row.querySelector('[data-row-warning]');
        if (box) {
            box.className = 'row-warning is-done';
            box.textContent = '✓ ' + message;
        }
        recount();
    }

    function refreshUnits(row) {
        var input = field(row, 'name');
        if (input) input.dispatchEvent(new Event('foodtruck:units', { bubbles: true }));
    }

    function addNameOption(name) {
        var list = document.getElementById('ingredient-names');
        if (list && !Array.prototype.some.call(list.options, function (o) { return o.value === name; })) {
            list.appendChild(make('option', { value: name }));
        }
    }

    function setUnit(row, code, label) {
        var select = field(row, 'unit');
        if (!select) return;
        if (!Array.prototype.some.call(select.options, function (o) { return o.value === code; })) {
            var group = select.querySelector('[data-own-units]') || select;
            group.appendChild(make('option', { value: code, text: label }));
            if (group.hidden) group.hidden = false;
        }
        select.value = code;
    }

    /** Ce qui manque encore pour convertir la ligne dans l'unité de référence de l'ingrédient (null = rien). */
    function missing(row) {
        var info = find(field(row, 'name').value);
        if (!info) return { kind: 'unknown' };
        var quantity = field(row, 'quantity').value.trim();
        var unit = field(row, 'unit').value;
        if (quantity === '' || unit === '') return null;

        var word = unit === 'piece' ? noteWord(row) : null;
        if (unit.indexOf('u:') === 0) word = unit.slice(2);
        if (word) {
            if (info.units['u:' + word]) {
                if (unit === 'piece') { setUnit(row, 'u:' + word, info.units['u:' + word]); stripNoteWord(row); }
                return null;
            }
            if (WORDS[word] || unit.indexOf('u:') === 0) return { kind: 'unit', word: word, info: info };
        }
        var from = routes.units[unit], to = routes.units[info.base];
        if (!from || !to || from.dim === to.dim) return null;
        if (from.dim === 'piece' || to.dim === 'piece') return info.piece ? null : { kind: 'piece', info: info };
        return info.density ? null : { kind: 'density', unit: from.dim === 'volume' ? unit : 'cl', info: info };
    }

    /** Après une correction : s'il manque une équivalence, la question suit tout de suite, sinon la ligne passe au vert. */
    function settle(row, message) {
        var need = missing(row);
        if (!need) {
            resolved(row, message);
            dialog.close();
            return;
        }
        if (need.kind === 'unknown') {
            openFix(warning(row, 'Ingrédient absent du référentiel.', 'unknown'));
            return;
        }
        openAsk(row, need);
    }

    // ---------------------------------------------------------------- fenêtre
    function open(title) {
        titleBox.textContent = title;
        body.innerHTML = '';
        errorBox.hidden = true;
        if (!dialog.open) dialog.showModal();
    }
    function fail(error) {
        errorBox.textContent = error.message || String(error);
        errorBox.hidden = false;
    }
    function busy(button, on) {
        button.disabled = on;
        button.classList.toggle('is-busy', on);
    }
    function section(title, children) {
        return make('section', { class: 'fix-section' }, [make('h3', { text: title })].concat(children));
    }
    function labelled(text, control) {
        return make('label', { class: 'field' }, [make('span', { text: text }), control]);
    }
    function options(templateId, selected) {
        var select = make('select');
        var tpl = document.getElementById(templateId);
        if (tpl) select.innerHTML = tpl.innerHTML;
        if (selected) select.value = selected;
        return select;
    }

    function useIngredient(row, name, message) {
        field(row, 'name').value = name;
        refreshUnits(row);
        settle(row, message || name + ' retenu.');
    }

    function openUnknown(row, button, approx) {
        var read = button.getAttribute('data-label') || field(row, 'name').value;
        open(approx ? 'Confirmer l\'ingrédient' : 'Choisir ou créer l\'ingrédient');
        var fromScan = !!document.querySelector('input[name="import_id"]');
        body.appendChild(make('p', { class: 'fix-read' }, [document.createTextNode(fromScan ? 'Lu sur la fiche : ' : 'Saisi : '), make('strong', { text: '« ' + read + ' »' })]));

        if (approx) {
            var current = field(row, 'name').value;
            var yes = make('button', { type: 'button', class: 'btn', text: 'Oui, c\'est « ' + current + ' »' });
            yes.addEventListener('click', function () { useIngredient(row, current, current + ' confirmé.'); });
            body.appendChild(make('div', { class: 'fix-actions' }, [yes]));
        }

        var candidates = [];
        try { candidates = JSON.parse(button.getAttribute('data-candidates') || '[]') || []; } catch (e) { candidates = []; }
        var search = make('input', { type: 'text', list: 'ingredient-names', placeholder: 'Commence à taper…', autocomplete: 'off' });
        var choose = make('button', { type: 'button', class: 'btn btn-ghost', text: 'Utiliser' });
        choose.addEventListener('click', function () {
            var info = find(search.value);
            if (!info) { fail('« ' + search.value + ' » n\'est pas dans les ingrédients : crée-le juste en dessous.'); return; }
            useIngredient(row, info.name);
        });
        var picks = candidates.map(function (name) {
            var pick = make('button', { type: 'button', class: 'pick', text: name });
            pick.addEventListener('click', function () { useIngredient(row, name); });
            return pick;
        });
        body.appendChild(section(approx ? 'Ou un autre ingrédient' : 'Un ingrédient existant', [
            picks.length ? make('div', { class: 'pick-list' }, picks) : null,
            make('div', { class: 'fix-inline' }, [search, choose])
        ]));

        var suggested = read.replace(/[*_]+/g, '').trim();
        suggested = suggested.charAt(0).toUpperCase() + suggested.slice(1);
        var name = make('input', { type: 'text', value: suggested, maxlength: '80', required: '' });
        var aisle = options('fix-aisles');
        var base = options('fix-bases', field(row, 'unit').value === 'piece' && !noteWord(row) ? 'piece' : 'g');
        var piece = make('input', { type: 'text', inputmode: 'decimal', maxlength: '8', placeholder: 'facultatif' });
        var create = make('button', { type: 'button', class: 'btn', text: 'Créer et utiliser' });
        create.addEventListener('click', function () {
            if (name.value.trim() === '') { fail('Indique le nom de l\'ingrédient.'); return; }
            busy(create, true);
            post(routes.create, { name: name.value, aisle_id: aisle.value, base_unit: base.value, piece_weight_g: number(piece.value) })
                .then(function (data) {
                    catalog[data.name] = data;
                    addNameOption(data.name);
                    useIngredient(row, data.name, data.message);
                })
                .catch(fail)
                .then(function () { busy(create, false); });
        });
        body.appendChild(section('Ou créer un nouvel ingrédient', [
            make('div', { class: 'fix-grid' }, [
                labelled('Nom', name),
                labelled('Rayon', aisle),
                labelled('Se compte', base),
                labelled('Poids d\'une pièce (g)', piece)
            ]),
            make('div', { class: 'fix-actions' }, [create])
        ]));
        setTimeout(function () { (approx ? body.querySelector('.btn') : search).focus(); }, 30);
    }

    function openAsk(row, need) {
        var info = need.info || find(field(row, 'name').value);
        if (!info) { openFix(warning(row, 'Ingrédient absent du référentiel.', 'unknown')); return; }
        var what = need.kind === 'unit' ? '1 ' + (WORDS[need.word] || need.word.replace(/-/g, ' '))
            : need.kind === 'piece' ? '1 pièce' : '1 ' + unitLabel(need.unit);
        var base = need.kind === 'unit' ? unitLabel(info.base) : 'g';
        row.setAttribute('data-need', JSON.stringify({ kind: need.kind, word: need.word || null, unit: need.unit || null }));
        warning(row, 'Combien vaut ' + what + ' de « ' + info.name + ' » ?', 'ask');

        open('Indiquer l\'équivalence');
        body.appendChild(make('p', { text: 'Combien vaut ' + what + ' de « ' + info.name + ' » ? Foodtruck le retient pour toutes les recettes.' }));
        var value = make('input', { type: 'text', inputmode: 'decimal', maxlength: '12', placeholder: '?', class: 'fix-number' });
        var save = make('button', { type: 'button', class: 'btn', text: 'Enregistrer' });
        body.appendChild(make('div', { class: 'fix-inline' }, [make('strong', { text: what + ' =' }), value, make('span', { text: base }), save]));
        save.addEventListener('click', function () {
            if (!(number(value.value) > 0)) { fail('Indique un nombre (ex. 200 ou 0,5).'); return; }
            busy(save, true);
            var request = need.kind === 'unit'
                ? post(routes.unit.replace('__SLUG__', info.slug), { word: need.word, quantity: value.value })
                : post(routes.measure.replace('__SLUG__', info.slug), { kind: need.kind, quantity: value.value, unit: need.unit });
            request.then(function (data) {
                catalog[data.name] = data;
                if (need.kind === 'unit') {
                    setUnit(row, data.unit, data.units[data.unit]);
                    if (noteWord(row) === need.word) stripNoteWord(row);
                }
                settle(row, data.message);
            }).catch(fail).then(function () { busy(save, false); });
        });
        setTimeout(function () { value.focus(); }, 30);
    }

    function openQuantity(row, button) {
        open('Corriger la quantité');
        var raw = button.getAttribute('data-raw');
        if (raw) body.appendChild(make('p', { class: 'fix-read' }, [document.createTextNode('Lu sur la fiche : '), make('strong', { text: '« ' + raw + ' »' })]));
        body.appendChild(make('p', { class: 'muted', text: button.getAttribute('data-problem') || '' }));

        var quantity = make('input', { type: 'text', inputmode: 'decimal', maxlength: '12', value: field(row, 'quantity').value, class: 'fix-number' });
        var unit = field(row, 'unit').cloneNode(true);
        unit.removeAttribute('name');
        unit.value = field(row, 'unit').value;
        var fractions = FRACTIONS.map(function (f) {
            var b = make('button', { type: 'button', class: 'pick', text: f[0] });
            b.addEventListener('click', function () { quantity.value = show(f[1]); });
            return b;
        });
        var apply = make('button', { type: 'button', class: 'btn', text: 'Appliquer' });
        apply.addEventListener('click', function () {
            if (quantity.value.trim() !== '' && !(number(quantity.value) > 0)) { fail('Quantité invalide (ex. 0,5).'); return; }
            field(row, 'quantity').value = quantity.value.trim();
            field(row, 'unit').value = unit.value;
            var label = quantity.value.trim() === '' ? 'selon goût' : quantity.value.trim() + ' ' + (unit.options[unit.selectedIndex] ? unit.options[unit.selectedIndex].text : '');
            settle(row, 'Quantité corrigée : ' + label + '.');
        });
        body.appendChild(make('div', { class: 'pick-list' }, fractions));
        body.appendChild(make('div', { class: 'fix-inline' }, [quantity, unit, apply]));
        setTimeout(function () { quantity.focus(); quantity.select(); }, 30);
    }

    function openTypical(row, button) {
        var info = find(field(row, 'name').value);
        var word = button.getAttribute('data-word');
        if (!info || !word) return;
        var current = info.units['u:' + word];
        open('Corriger la valeur typique');
        body.appendChild(make('p', { text: 'Valeur proposée par Foodtruck : corrige-la, elle servira pour toutes les recettes.' }));
        var value = make('input', { type: 'text', inputmode: 'decimal', maxlength: '12', class: 'fix-number', placeholder: '?' });
        var save = make('button', { type: 'button', class: 'btn', text: 'Enregistrer' });
        var what = '1 ' + (current || WORDS[word] || word);
        body.appendChild(make('div', { class: 'fix-inline' }, [make('strong', { text: what + ' de « ' + info.name + ' » =' }), value, make('span', { text: unitLabel(info.base) }), save]));
        save.addEventListener('click', function () {
            if (!(number(value.value) > 0)) { fail('Indique un nombre (ex. 12).'); return; }
            busy(save, true);
            post(routes.unit.replace('__SLUG__', info.slug), { word: word, quantity: value.value }).then(function (data) {
                catalog[data.name] = data;
                var text = row.querySelector('[data-info-text]');
                if (text) text.textContent = data.message;
                var line = row.querySelector('[data-row-info]');
                if (line) line.classList.add('is-done');
                button.remove();
                dialog.close();
            }).catch(fail).then(function () { busy(save, false); });
        });
        setTimeout(function () { value.focus(); }, 30);
    }

    function openFix(button) {
        var row = button.closest('[data-row]');
        if (!row) return;
        var kind = button.getAttribute('data-kind') || 'other';
        if (kind === 'unknown') return openUnknown(row, button, false);
        if (kind === 'approx') return openUnknown(row, button, true);
        if (kind === 'typical') return openTypical(row, button);
        if (kind === 'ask') {
            var ask = null;
            try { ask = JSON.parse(row.getAttribute('data-need') || button.getAttribute('data-ask') || 'null'); } catch (e) { ask = null; }
            var need = ask ? { kind: ask.kind, word: ask.word, unit: ask.kind === 'density' ? (ask.unit || ask.word || 'cl') : null } : missing(row);
            if (ask && ask.kind === 'piece') need = { kind: 'piece' };
            if (!need) { resolved(row, 'Équivalence déjà connue.'); return; }
            return openAsk(row, need);
        }
        return openQuantity(row, button);
    }

    document.addEventListener('click', function (event) {
        var button = event.target.closest('[data-fix]');
        if (!button) return;
        event.preventDefault();
        openFix(button);
    });

    // Saisie à la main d'un ingrédient inconnu (nouvelle recette comprise) : proposition de le créer, sans quitter la page
    document.addEventListener('change', function (event) {
        var input = event.target.closest('[data-ingredient-name]');
        if (!input) return;
        var row = input.closest('[data-row]');
        var name = input.value.trim();
        if (!row || name === '') return;
        var box = row.querySelector('[data-row-warning]');
        if (!find(name)) {
            warning(row, '« ' + name + ' » n\'est pas dans les ingrédients.', 'unknown');
        } else if (box && row.classList.contains('has-problem') && /n'est pas dans les ingrédients|absent du référentiel/.test(box.textContent)) {
            settle(row, find(name).name + ' retenu.');
        }
    });

    recount();
})();

// ---------------------------------------------------------------------------------------------------------------
// v0.18.0 : avancement de la lecture des fiches Paperless (modèle de vision de srv-nas), mis à jour toutes les 3 s.
// Quand une fiche n'est plus en lecture, la page se recharge : elle apparaît « à valider » ou « à compléter ».
(function () {
    var source = document.getElementById('read-progress-url');
    if (!source) return;
    var url = JSON.parse(source.textContent);
    var known = {};
    document.querySelectorAll('[data-read-progress]').forEach(function (el) { known[el.getAttribute('data-read-progress')] = true; });

    function duration(seconds) {
        if (seconds === null || seconds === undefined) return '';
        var m = Math.floor(seconds / 60), s = seconds % 60;
        return ' · depuis ' + (m > 0 ? m + ' min ' : '') + (s < 10 && m > 0 ? '0' : '') + s + ' s';
    }

    function tick() {
        fetch(url, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
            .then(function (r) { if (!r.ok) throw new Error(r.status); return r.json(); })
            .then(function (data) {
                var still = {};
                (data.reading || []).forEach(function (item) {
                    still[item.id] = true;
                    var el = document.querySelector('[data-read-progress="' + item.id + '"]');
                    if (!el) return;
                    var fill = el.querySelector('.read-progress-fill');
                    if (fill) fill.style.width = (item.progress || 0) + '%';
                    el.classList.toggle('is-waiting', item.progress === null);
                    el.querySelector('[data-read-step]').textContent = item.step;
                    el.querySelector('[data-read-percent]').textContent = item.progress === null ? '' : ' · ' + item.progress + ' %';
                    el.querySelector('[data-read-since]').textContent = item.progress === null ? '' : duration(item.since);
                });
                var finished = Object.keys(known).some(function (id) { return !still[id]; });
                if (finished) { window.location.reload(); return; }
                setTimeout(tick, 3000);
            })
            .catch(function () { setTimeout(tick, 10000); });
    }
    tick();
})();

// ---------------------------------------------------------------------------------------------------------------
// v0.19.0 : validation d'un ticket. Chaque ligne se corrige dans une fenêtre, sans quitter le ticket (comme les
// recettes) : lecture (quantité, prix unitaire, prix payé), ingrédient existant ou nouveau, ou ligne ignorée.
// La somme des lignes est recomparée au total à chaque correction.
(function () {
    'use strict';

    var form = document.querySelector('[data-receipt-review]');
    var dialog = document.getElementById('receipt-fix');
    if (!form || !dialog || typeof dialog.showModal !== 'function') return;

    var routes = {};
    try { routes = JSON.parse(document.getElementById('receipt-fix-routes').textContent); } catch (e) { routes = {}; }
    var token = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
    var body = dialog.querySelector('[data-fix-body]');
    var errorBox = dialog.querySelector('[data-fix-error]');
    var titleBox = dialog.querySelector('#receipt-fix-title');
    var choices = document.getElementById('pack-choices');

    function make(tag, attrs, children) {
        var node = document.createElement(tag);
        Object.keys(attrs || {}).forEach(function (key) {
            if (key === 'text') node.textContent = attrs[key];
            else if (key === 'class') node.className = attrs[key];
            else node.setAttribute(key, attrs[key]);
        });
        (children || []).forEach(function (child) { if (child) node.appendChild(child); });
        return node;
    }
    function field(row, name) { return row.querySelector('[data-field="' + name + '"]'); }
    function number(text) {
        var value = parseFloat(String(text || '').replace(/\s|€/g, '').replace(',', '.'));
        return isNaN(value) ? null : value;
    }
    function euros(value) { return value === null ? '?' : value.toFixed(2).replace('.', ',') + ' €'; }
    function plain(value, digits) { return value.toFixed(digits).replace('.', ','); }
    function slug(text) {
        return (text || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');
    }
    function known(value) {
        var key = slug(value);
        return !!key && Array.prototype.some.call(choices.options, function (o) { return slug(o.value) === key; });
    }
    function options(templateId, selected) {
        var select = make('select');
        var tpl = document.getElementById(templateId);
        if (tpl) select.innerHTML = tpl.innerHTML;
        if (selected) select.value = selected;
        return select;
    }
    function labelled(text, control) { return make('label', { class: 'field' }, [make('span', { text: text }), control]); }
    function section(title, children) { return make('section', { class: 'fix-section' }, [make('h3', { text: title })].concat(children)); }
    function fail(error) { errorBox.textContent = error.message || String(error); errorBox.hidden = false; }
    function busy(button, on) { button.disabled = on; button.classList.toggle('is-busy', on); }

    function post(url, data) {
        return fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': token, 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify(data)
        }).then(function (response) {
            return response.json().catch(function () { return {}; }).then(function (json) {
                if (response.status === 419) throw new Error('Session expirée : recharge la page.');
                if (!response.ok) throw new Error(json.message || ('Erreur ' + response.status + ', réessaie.'));
                return json;
            });
        });
    }

    // ---------------------------------------------------------------- somme des lignes et lignes à vérifier
    function recount() {
        var rows = form.querySelectorAll('[data-line]');
        var sum = 0;
        rows.forEach(function (row) {
            var price = number(field(row, 'price').value) || 0;
            sum += price - (parseInt(row.getAttribute('data-discount') || '0', 10) / 100);
        });
        form.querySelectorAll('[data-remise]').forEach(function (row) { sum += parseInt(row.getAttribute('data-remise'), 10) / 100; });
        sum = Math.round(sum * 100) / 100;

        var total = number((form.querySelector('[data-receipt-total]') || {}).value);
        var sumBox = form.querySelector('[data-receipt-sum]');
        var balanced = total !== null && Math.abs(total - sum) <= 0.02;
        if (sumBox) {
            sumBox.textContent = total === null ? 'Somme des lignes : ' + euros(sum)
                : (balanced ? '✓ Somme des lignes = total (' + euros(sum) + ')' : 'Écart de ' + euros(Math.abs(total - sum)) + ' avec le total (lignes : ' + euros(sum) + ')');
        }

        var left = form.querySelectorAll('[data-line].has-problem').length;
        var text = form.querySelector('[data-check-text]');
        var summary = form.querySelector('[data-check-summary]');
        if (text) {
            text.innerHTML = '';
            if (left === 0) {
                text.appendChild(make('strong', { text: '✓ Tout est vérifié' }));
                text.appendChild(document.createTextNode(balanced || total === null ? ' : tu peux valider le ticket.' : ' : vérifie l\'écart avec le total avant de valider.'));
            } else {
                text.appendChild(make('strong', { text: left + ' ligne' + (left > 1 ? 's' : '') + ' à vérifier' }));
                text.appendChild(document.createTextNode(' : « Corriger » sur chaque ligne, le ticket reste ouvert.'));
            }
        }
        if (summary) summary.classList.toggle('is-done', left === 0 && (balanced || total === null));
    }

    function setStatus(row, cls, label) {
        var box = row.querySelector('[data-line-status]');
        if (box) { box.innerHTML = ''; box.appendChild(make('span', { class: cls, text: label })); }
    }

    function resolved(row, message) {
        row.classList.remove('has-problem');
        row.classList.add('is-resolved');
        var box = row.querySelector('[data-row-warning]');
        if (!box) {
            box = make('div', { class: 'row-warning', 'data-row-warning': '' });
            row.appendChild(box);
        }
        box.className = 'row-warning is-done';
        box.textContent = '✓ ' + message;
        recount();
    }

    function warning(row, text, kind, label) {
        var box = row.querySelector('[data-row-warning]');
        if (!box) {
            box = make('div', { class: 'row-warning', 'data-row-warning': '' });
            row.appendChild(box);
        }
        box.className = 'row-warning';
        box.innerHTML = '';
        box.appendChild(make('span', { class: 'row-warning-text' }, [make('strong', { text: 'À vérifier' }), document.createTextNode(' · ' + text)]));
        box.appendChild(make('button', { type: 'button', class: 'btn btn-small fix-btn', 'data-fix': '', 'data-kind': kind, 'data-problem': text, text: label || 'Corriger' }));
        row.classList.add('has-problem');
        row.classList.remove('is-resolved');
        recount();
    }

    function setIgnored(row, ignored) {
        var choice = field(row, 'choice');
        choice.disabled = ignored;
        row.classList.toggle('is-muted', ignored);
    }

    // ---------------------------------------------------------------- application d'une correction
    function readFields(inputs) {
        var quantity = number(inputs.quantity.value);
        var price = number(inputs.price.value);
        var unitPrice = inputs.unitPrice.value.trim() === '' ? null : number(inputs.unitPrice.value);
        if (price === null || price < 0) return 'Prix payé invalide (ex. 2,83).';
        if (quantity === null || quantity <= 0) return 'Quantité invalide (ex. 1 ou 0,420).';
        if (inputs.unitPrice.value.trim() !== '' && (unitPrice === null || unitPrice <= 0)) return 'Prix unitaire invalide (ex. 1,99).';
        return { quantity: quantity, unit: inputs.unit.value, unitPrice: unitPrice, price: price };
    }

    function writeReading(row, reading) {
        var weighted = reading.unit === 'kg';
        field(row, 'quantity').value = plain(reading.quantity, weighted ? 3 : (reading.quantity % 1 ? 3 : 0));
        field(row, 'unit').value = reading.unit;
        field(row, 'unit_price').value = reading.unitPrice === null ? '' : plain(reading.unitPrice, 2);
        field(row, 'price').value = plain(reading.price, 2);
        field(row, 'checked').value = '1';
        row.querySelector('[data-line-price]').textContent = euros(reading.price);
        var detail = row.querySelector('[data-line-detail]');
        var pu = reading.unitPrice !== null ? reading.unitPrice : reading.price / reading.quantity;
        if (detail) {
            detail.textContent = weighted ? plain(reading.quantity, 3) + ' kg × ' + euros(pu) + '/kg'
                : (reading.quantity !== 1 ? plain(reading.quantity, reading.quantity % 1 ? 3 : 0) + ' × ' + euros(pu) : '');
        }
    }

    /** Lecture vérifiée + ingrédient choisi (ou ligne ignorée) : la ligne passe au vert. */
    function commit(row, inputs, choice, action, message) {
        // Ligne ignorée : sa lecture n'a pas d'importance (aucun prix n'en sera tiré)
        if (action === 'associer') {
            var reading = readFields(inputs);
            if (typeof reading === 'string') { fail(reading); return; }
            writeReading(row, reading);
        }
        field(row, 'action').value = action;
        if (action === 'associer') {
            setIgnored(row, false);
            field(row, 'choice').value = choice;
            setStatus(row, 'badge-cheap', 'à enregistrer');
        } else {
            setIgnored(row, true);
            setStatus(row, 'badge-off', action === 'ignorer_toujours' ? 'toujours ignoré' : 'ignoré');
        }
        dialog.close();
        resolved(row, message);
    }

    // ---------------------------------------------------------------- fenêtre
    function openFix(row, kind, problem) {
        var label = row.getAttribute('data-label') || '';
        var current = field(row, 'choice').value.trim();
        var ignored = field(row, 'action').value !== 'associer';
        titleBox.textContent = kind === 'reading' ? 'Vérifier la lecture' : (kind === 'unknown' ? 'Choisir ou créer l\'ingrédient' : (kind === 'approx' ? 'Confirmer l\'ingrédient' : 'Corriger la ligne'));
        body.innerHTML = '';
        errorBox.hidden = true;

        body.appendChild(make('p', { class: 'fix-read' }, [document.createTextNode('Lu sur le ticket : '), make('strong', { text: '« ' + label + ' »' })]));
        if (problem) body.appendChild(make('p', { class: 'muted', text: problem }));

        // Lecture : quantité, unité, prix unitaire, prix payé, avec le calcul vérifié en direct
        var inputs = {
            quantity: make('input', { type: 'text', inputmode: 'decimal', maxlength: '12', value: field(row, 'quantity').value }),
            unit: make('select'),
            unitPrice: make('input', { type: 'text', inputmode: 'decimal', maxlength: '12', value: field(row, 'unit_price').value, placeholder: 'facultatif' }),
            price: make('input', { type: 'text', inputmode: 'decimal', maxlength: '12', value: field(row, 'price').value })
        };
        inputs.unit.appendChild(make('option', { value: 'piece', text: 'à la pièce' }));
        inputs.unit.appendChild(make('option', { value: 'kg', text: 'pesé (kg)' }));
        inputs.unit.value = field(row, 'unit').value;
        var check = make('p', { class: 'small' });
        var refresh = function () {
            var q = number(inputs.quantity.value), pu = number(inputs.unitPrice.value), p = number(inputs.price.value);
            if (q === null || pu === null || p === null) { check.textContent = ''; return; }
            var computed = Math.round(q * pu * 100) / 100;
            check.textContent = plain(q, inputs.unit.value === 'kg' ? 3 : (q % 1 ? 3 : 0)) + (inputs.unit.value === 'kg' ? ' kg' : '') + ' × ' + euros(pu)
                + ' = ' + euros(computed) + (Math.abs(computed - p) <= 0.02 ? '  ✓' : '  ≠ prix payé (' + euros(p) + ')');
            check.className = 'small ' + (Math.abs(computed - p) <= 0.02 ? 'is-ok' : 'is-ko');
        };
        Object.keys(inputs).forEach(function (k) { inputs[k].addEventListener('input', refresh); inputs[k].addEventListener('change', refresh); });
        refresh();
        body.appendChild(section('Lecture du ticket', [
            make('div', { class: 'fix-grid' }, [
                labelled('Quantité ou poids', inputs.quantity),
                labelled('Vendu', inputs.unit),
                labelled('Prix unitaire ou au kilo (€)', inputs.unitPrice),
                labelled('Prix payé (€)', inputs.price)
            ]),
            check
        ]));

        // Ingrédient : garder, chercher, ou créer
        var keep = [];
        if (current && !ignored) {
            var yes = make('button', { type: 'button', class: 'btn', text: kind === 'approx' ? 'Oui, c\'est « ' + current + ' »' : 'Valider avec « ' + current + ' »' });
            yes.addEventListener('click', function () { commit(row, inputs, current, 'associer', current + (kind === 'approx' ? ' confirmé.' : ' : ligne vérifiée.')); });
            keep.push(make('div', { class: 'fix-actions' }, [yes]));
        }
        var search = make('input', { type: 'text', list: 'pack-choices', placeholder: 'Commence à taper…', autocomplete: 'off' });
        var use = make('button', { type: 'button', class: 'btn btn-ghost', text: 'Utiliser' });
        use.addEventListener('click', function () {
            var value = search.value.trim();
            if (!known(value)) { fail('« ' + value + ' » n\'est pas dans les ingrédients : crée-le juste en dessous.'); return; }
            commit(row, inputs, value, 'associer', value + ' retenu.');
        });
        body.appendChild(section(current && !ignored ? 'Ingrédient : « ' + current + ' »' : 'Ingrédient', keep.concat([
            make('p', { class: 'small muted', text: current && !ignored ? 'Ou un autre ingrédient existant :' : 'Un ingrédient existant :' }),
            make('div', { class: 'fix-inline' }, [search, use])
        ])));

        var suggested = label.replace(/\s+[-–]\s+.*$/, '').replace(/\s+\d+([.,]\d+)?\s*(kg|g|l|cl|ml)\b.*$/i, '').trim();
        suggested = suggested.charAt(0).toUpperCase() + suggested.slice(1).toLowerCase();
        var name = make('input', { type: 'text', value: suggested, maxlength: '80' });
        var aisle = options('fix-aisles', row.getAttribute('data-aisle'));
        var base = options('fix-bases', row.getAttribute('data-base') || 'piece');
        var piece = make('input', { type: 'text', inputmode: 'decimal', maxlength: '8', placeholder: 'facultatif' });
        var create = make('button', { type: 'button', class: 'btn', text: 'Créer et utiliser' });
        create.addEventListener('click', function () {
            if (name.value.trim() === '') { fail('Indique le nom de l\'ingrédient.'); return; }
            var reading = readFields(inputs);
            if (typeof reading === 'string') { fail(reading); return; }
            busy(create, true);
            post(routes.create, { name: name.value, aisle_id: aisle.value, base_unit: base.value, piece_weight_g: number(piece.value) })
                .then(function (data) {
                    if (!known(data.name)) choices.appendChild(make('option', { value: data.name }));
                    commit(row, inputs, data.name, 'associer', data.message);
                })
                .catch(fail)
                .then(function () { busy(create, false); });
        });
        body.appendChild(section('Ou créer un nouvel ingrédient', [
            make('div', { class: 'fix-grid' }, [labelled('Nom', name), labelled('Rayon', aisle), labelled('Se compte', base), labelled('Poids d\'une pièce (g)', piece)]),
            make('p', { class: 'small muted', text: 'Le conditionnement est déduit du ticket (« 500 g », « x30 »…) à la validation.' }),
            make('div', { class: 'fix-actions' }, [create])
        ]));

        // Ou ignorer (non alimentaire, consigne…)
        var once = make('button', { type: 'button', class: 'btn btn-ghost btn-small', text: 'Ignorer cette fois' });
        once.addEventListener('click', function () { commit(row, inputs, '', 'ignorer', 'Ligne ignorée pour ce ticket.'); });
        var always = make('button', { type: 'button', class: 'btn btn-ghost btn-small', text: 'Toujours ignorer (non alimentaire)' });
        always.addEventListener('click', function () { commit(row, inputs, '', 'ignorer_toujours', 'Ligne ignorée, et sur les prochains tickets.'); });
        var buttons = [once, always];
        if (ignored) {
            var back = make('button', { type: 'button', class: 'btn btn-ghost btn-small', text: 'Ne plus ignorer' });
            back.addEventListener('click', function () {
                field(row, 'action').value = 'associer';
                setIgnored(row, false);
                setStatus(row, 'badge-warn', 'à associer');
                dialog.close();
                warning(row, 'Ingrédient à choisir ou à créer.', 'unknown', 'Choisir ou créer l\'ingrédient');
            });
            buttons = [back];
        }
        body.appendChild(section('Ou', [make('div', { class: 'fix-inline' }, buttons)]));

        if (!dialog.open) dialog.showModal();
        setTimeout(function () { (kind === 'reading' ? inputs.price : (current && !ignored ? body.querySelector('.btn') : search)).focus(); }, 30);
    }

    /** Proposition confirmée en un clic, sans ouvrir la fenêtre. */
    function acceptRow(row) {
        var current = field(row, 'choice').value.trim();
        if (!current) return false;
        field(row, 'action').value = 'associer';
        setIgnored(row, false);
        setStatus(row, 'badge-cheap', 'à enregistrer');
        resolved(row, current + ' confirmé.');
        return true;
    }

    function refreshConfirmAll() {
        var all = form.querySelector('[data-accept-all]');
        if (!all) return;
        var left = form.querySelectorAll('[data-line].has-problem [data-accept]').length;
        all.style.display = left < 2 ? 'none' : '';
        all.textContent = 'Confirmer les ' + left + ' propositions';
    }

    form.addEventListener('click', function (event) {
        var one = event.target.closest('[data-accept]');
        if (one) {
            event.preventDefault();
            acceptRow(one.closest('[data-line]'));
            refreshConfirmAll();
            return;
        }
        if (event.target.closest('[data-accept-all]')) {
            event.preventDefault();
            form.querySelectorAll('[data-line].has-problem').forEach(function (row) {
                if (row.querySelector('[data-row-warning] [data-accept]')) acceptRow(row);
            });
            refreshConfirmAll();
            return;
        }
        var button = event.target.closest('[data-fix]');
        if (!button) return;
        event.preventDefault();
        var row = button.closest('[data-line]');
        if (row) openFix(row, button.getAttribute('data-kind') || 'edit', button.getAttribute('data-problem') || '');
    });
    dialog.addEventListener('close', refreshConfirmAll);

    // Nom tapé directement dans la ligne : retenu s'il est dans la liste, sinon proposition de le créer
    form.addEventListener('change', function (event) {
        if (event.target.matches('[data-receipt-total]')) { recount(); return; }
        var input = event.target.closest('[data-field="choice"]');
        if (!input) return;
        var row = input.closest('[data-line]');
        var value = input.value.trim();
        var reading = row.classList.contains('has-problem') && !!row.querySelector('[data-row-warning] [data-fix][data-kind="reading"]');
        if (value === '' || reading) return;
        if (known(value)) {
            setStatus(row, 'badge-cheap', 'à enregistrer');
            resolved(row, value + ' retenu.');
        } else {
            warning(row, '« ' + value + ' » n\'est pas dans les ingrédients.', 'unknown', 'Choisir ou créer l\'ingrédient');
        }
    });

    recount();
})();
