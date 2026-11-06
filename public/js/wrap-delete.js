(() => {
    document.addEventListener('click', (event) => {
        const openButton = event.target.closest('[data-wrap-delete-open]');

        if (openButton) {
            const dialog = document.getElementById(openButton.getAttribute('aria-controls'));

            if (dialog && !dialog.open) {
                dialog.showModal();
            }

            return;
        }

        const cancelButton = event.target.closest('[data-wrap-delete-cancel]');

        if (cancelButton) {
            cancelButton.closest('dialog').close();
        }
    });

    document.querySelectorAll('.wrap-tile__delete-dialog').forEach((dialog) => {
        dialog.addEventListener('click', (event) => {
            if (event.target !== dialog) {
                return;
            }

            const bounds = dialog.getBoundingClientRect();
            const outside = event.clientX < bounds.left || event.clientX > bounds.right
                || event.clientY < bounds.top || event.clientY > bounds.bottom;

            if (outside) {
                dialog.close();
            }
        });

        dialog.addEventListener('close', () => {
            document.querySelector(`[aria-controls="${dialog.id}"]`)?.focus();
        });
    });
})();
