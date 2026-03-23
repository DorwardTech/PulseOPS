/**
 * PulseOPS V3 - Application JavaScript
 */

document.addEventListener('DOMContentLoaded', function () {
    // Confirm delete actions
    document.querySelectorAll('[data-confirm]').forEach(function (el) {
        el.addEventListener('click', function (e) {
            if (!confirm(el.dataset.confirm || 'Are you sure?')) {
                e.preventDefault();
            }
        });
    });

    // Auto-submit filter forms on change
    document.querySelectorAll('.auto-submit select').forEach(function (select) {
        select.addEventListener('change', function () {
            this.closest('form').submit();
        });
    });

    // Labour cost calculator
    const labourMinutes = document.getElementById('labour_minutes');
    const labourRate = document.getElementById('labour_rate');
    const labourCost = document.getElementById('labour_cost_display');
    if (labourMinutes && labourRate && labourCost) {
        function updateLabourCost() {
            const minutes = parseInt(labourMinutes.value) || 0;
            const rate = parseFloat(labourRate.value) || 0;
            const cost = (minutes / 60) * rate;
            labourCost.textContent = '$' + cost.toFixed(2);
        }
        labourMinutes.addEventListener('input', updateLabourCost);
        labourRate.addEventListener('input', updateLabourCost);
    }

    // Revenue calculator (real-time)
    const cashInput = document.getElementById('cash_amount');
    const cardInput = document.getElementById('card_amount');
    const prepaidInput = document.getElementById('prepaid_amount');
    const grossDisplay = document.getElementById('gross_revenue_display');
    if (cashInput && cardInput && grossDisplay) {
        function updateGross() {
            const cash = parseFloat(cashInput.value) || 0;
            const card = parseFloat(cardInput.value) || 0;
            // Gross = Cash + Card (Prepaid excluded)
            const gross = cash + card;
            grossDisplay.textContent = '$' + gross.toFixed(2);
        }
        cashInput.addEventListener('input', updateGross);
        cardInput.addEventListener('input', updateGross);
        if (prepaidInput) prepaidInput.addEventListener('input', updateGross);
    }

    // Initialize tooltips
    const tooltipTriggerList = document.querySelectorAll('[data-bs-toggle="tooltip"]');
    tooltipTriggerList.forEach(function (el) {
        new bootstrap.Tooltip(el);
    });

    // File upload preview
    document.querySelectorAll('input[type="file"][data-preview]').forEach(function (input) {
        input.addEventListener('change', function () {
            const preview = document.getElementById(input.dataset.preview);
            if (preview && this.files && this.files[0]) {
                const reader = new FileReader();
                reader.onload = function (e) {
                    preview.src = e.target.result;
                    preview.style.display = 'block';
                };
                reader.readAsDataURL(this.files[0]);
            }
        });
    });

    // AJAX delete with confirmation
    document.querySelectorAll('[data-delete]').forEach(function (btn) {
        btn.addEventListener('click', function (e) {
            e.preventDefault();
            if (!confirm(btn.dataset.delete || 'Are you sure you want to delete this?')) return;

            fetch(btn.href, {
                method: 'DELETE',
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                },
            }).then(function (response) {
                if (response.ok) {
                    window.location.reload();
                } else {
                    alert('Delete failed. Please try again.');
                }
            });
        });
    });
});
