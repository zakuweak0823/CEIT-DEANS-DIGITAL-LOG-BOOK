document.addEventListener('DOMContentLoaded', function () {
    const smsForm = document.getElementById('smsSendForm');
    const composeFields = document.getElementById('smsComposeFields');
    const composeFooter = document.getElementById('smsComposeFooter');
    const reviewPanel = document.getElementById('smsSendReview');
    const reviewFooter = document.getElementById('smsSendReviewFooter');
    const reviewRecipient = document.getElementById('smsReviewRecipient');
    const reviewMessage = document.getElementById('smsReviewMessage');
    const reviewButton = document.getElementById('smsReviewConfirm');
    let smsSubmitter = null;

    const showSmsReview = function () {
        const recipientInput = document.getElementById('recipient');
        const messageInput = document.getElementById('sms_message');
        if (!smsForm || !composeFields || !composeFooter || !reviewPanel || !reviewFooter || !recipientInput || !messageInput) return;
        reviewRecipient.textContent = recipientInput.value.trim();
        reviewMessage.textContent = messageInput.value.trim();
        composeFields.classList.add('d-none');
        composeFooter.classList.add('d-none');
        reviewPanel.classList.remove('d-none');
        reviewFooter.classList.remove('d-none');
    };

    const hideSmsReview = function () {
        if (!composeFields || !composeFooter || !reviewPanel || !reviewFooter) return;
        composeFields.classList.remove('d-none');
        composeFooter.classList.remove('d-none');
        reviewPanel.classList.add('d-none');
        reviewFooter.classList.add('d-none');
        smsSubmitter = null;
        if (reviewButton) {
            reviewButton.disabled = false;
            reviewButton.innerHTML = '<i class="bi bi-send me-1" aria-hidden="true"></i>Confirm and send';
        }
    };

    if (smsForm) {
        smsForm.addEventListener('submit', function (event) {
            if (smsForm.dataset.confirmed === 'true') {
                delete smsForm.dataset.confirmed;
                return;
            }
            event.preventDefault();
            smsSubmitter = event.submitter;
            showSmsReview();
        });
    }

    document.getElementById('smsReviewBack')?.addEventListener('click', hideSmsReview);
    reviewButton?.addEventListener('click', function () {
        if (!smsForm || !smsForm.reportValidity()) return;
        reviewButton.disabled = true;
        reviewButton.innerHTML = '<span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>Sending…';
        smsForm.dataset.confirmed = 'true';
        let sendField = smsForm.querySelector('input[name="send"][type="hidden"]');
        if (!sendField) {
            sendField = document.createElement('input');
            sendField.type = 'hidden';
            sendField.name = 'send';
            sendField.value = '1';
            smsForm.appendChild(sendField);
        }
        if (smsSubmitter && smsSubmitter.name === 'send') {
            smsForm.requestSubmit(smsSubmitter);
        } else {
            smsForm.requestSubmit();
        }
    });

    const actionConfirmElement = document.getElementById('smsActionConfirmModal');
    const actionConfirmModal = actionConfirmElement ? new bootstrap.Modal(actionConfirmElement) : null;
    const actionConfirmHeading = document.getElementById('smsActionConfirmHeading');
    const actionConfirmMessage = document.getElementById('smsActionConfirmMessage');
    const actionConfirmSubmit = document.getElementById('smsActionConfirmSubmit');
    let pendingSmsForm = null;
    let pendingSmsSubmitter = null;

    document.querySelectorAll('form.sms-confirm-form').forEach(function (form) {
        form.addEventListener('submit', function (event) {
            if (form.dataset.confirmed === 'true') {
                delete form.dataset.confirmed;
                return;
            }
            event.preventDefault();
            pendingSmsForm = form;
            pendingSmsSubmitter = event.submitter;
            actionConfirmHeading.textContent = form.dataset.confirmTitle || 'Confirm action';
            actionConfirmMessage.textContent = form.dataset.confirmMessage || 'Continue with this SMS action?';
            actionConfirmSubmit.textContent = form.dataset.confirmButton || 'Continue';
            actionConfirmSubmit.disabled = false;
            actionConfirmModal.show();
        });
    });

    actionConfirmSubmit?.addEventListener('click', function () {
        if (!pendingSmsForm) return;
        const form = pendingSmsForm;
        const submitter = pendingSmsSubmitter;
        pendingSmsForm = null;
        pendingSmsSubmitter = null;
        actionConfirmSubmit.disabled = true;
        actionConfirmSubmit.innerHTML = '<span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>Working…';
        actionConfirmElement.addEventListener('hidden.bs.modal', function () {
            form.dataset.confirmed = 'true';
            if (submitter) {
                form.requestSubmit(submitter);
            } else {
                form.requestSubmit();
            }
        }, { once: true });
        actionConfirmModal.hide();
    });

    const outcomeModal = document.getElementById('smsOutcomeModal');
    if (outcomeModal) {
        new bootstrap.Modal(outcomeModal).show();
    }
});
