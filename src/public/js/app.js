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
