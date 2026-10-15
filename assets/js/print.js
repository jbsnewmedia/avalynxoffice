document.addEventListener('DOMContentLoaded', function () {
    setTimeout(function () { window.print(); }, 100);
    const btn = document.querySelector('[data-action="print"]');
    if (btn) {
        btn.addEventListener('click', function (e) {
            e.preventDefault();
            window.print();
        });
    }
});
