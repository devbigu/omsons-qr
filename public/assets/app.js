(function () {
    function getSelectedProduct() {
        var select = document.getElementById('productSelect');
        if (!select || !select.selectedOptions.length) {
            return null;
        }
        return select.selectedOptions[0].dataset;
    }

    function renderQr(el, text) {
        if (!el || !window.QRCode) {
            return;
        }

        el.innerHTML = '';
        el.classList.add('rendered');
        new window.QRCode(el, {
            text: text || 'https://example.com/coa',
            width: 92,
            height: 92,
            colorDark: '#000000',
            colorLight: '#ffffff',
            correctLevel: window.QRCode.CorrectLevel.M
        });
    }

    function buildLot() {
        var prefix = document.querySelector('[name="lot_prefix"]');
        var code = document.querySelector('[name="lot_code"]');
        var suffix = document.querySelector('[name="lot_suffix"]');
        var manual = document.querySelector('[name="lot_no"]');
        var autoLot = [
            prefix ? prefix.value.trim().toUpperCase() : 'S',
            code ? code.value.trim().toUpperCase() : '5516',
            suffix ? suffix.value.trim().toUpperCase() : 'E'
        ].join('');

        if (manual && manual.value.trim() !== '') {
            return manual.value.trim().toUpperCase();
        }

        return autoLot;
    }

    function applyTemplate(template, product, lot, serial) {
        var safeTemplate = template || 'https://example.com/coa/{lot}/{serial}';
        var map = {
            '{product}': product ? encodeURIComponent(product.name || '') : '',
            '{catalog}': product ? encodeURIComponent(product.catalog || '') : '',
            '{lot}': encodeURIComponent(lot),
            '{serial}': encodeURIComponent(serial),
            '{membrane}': product ? encodeURIComponent(product.membrane || '') : ''
        };

        Object.keys(map).forEach(function (key) {
            safeTemplate = safeTemplate.split(key).join(map[key]);
        });

        return safeTemplate;
    }

    function displayPore(value) {
        return (value || '').replace(/\bum\b/gi, '\u00B5m');
    }

    function updatePreview() {
        var form = document.getElementById('generatorForm');
        var product = getSelectedProduct();
        if (!form || !product) {
            return;
        }

        var suffixInput = document.getElementById('lotSuffix');
        if (suffixInput && document.activeElement !== suffixInput && suffixInput.dataset.touched !== '1') {
            suffixInput.value = product.suffix || 'E';
        }

        var lot = buildLot();
        var serialInput = form.querySelector('[name="start_serial"]');
        var serial = serialInput && serialInput.value.trim() !== '' ? serialInput.value.trim() : '101';
        var previewLotText = document.getElementById('previewLotText');

        if (previewLotText) {
            previewLotText.textContent = lot;
        }

        var values = {
            product: product.name || '',
            catalog: product.catalog || '',
            pore: displayPore(product.pore || ''),
            membrane: product.membrane || '',
            lot: lot,
            serial: serial
        };

        Object.keys(values).forEach(function (key) {
            document.querySelectorAll('[data-preview="' + key + '"]').forEach(function (el) {
                el.textContent = values[key];
            });
        });

        var previewQr = document.querySelector('.preview-label .qr-code');
        renderQr(previewQr, applyTemplate(product.template, product, lot, serial));
    }

    function renderAllQrCodes() {
        document.querySelectorAll('.qr-code[data-qr]').forEach(function (el) {
            renderQr(el, el.getAttribute('data-qr'));
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        renderAllQrCodes();
        updatePreview();

        var form = document.getElementById('generatorForm');
        if (form) {
            form.addEventListener('input', updatePreview);
            form.addEventListener('change', updatePreview);
        }

        var suffixInput = document.getElementById('lotSuffix');
        if (suffixInput) {
            suffixInput.addEventListener('input', function () {
                suffixInput.dataset.touched = '1';
            });
        }
    });
})();
