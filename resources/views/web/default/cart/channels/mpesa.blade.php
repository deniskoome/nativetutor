<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ trans('update.mpesa_checkout_title') }}</title>
    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            background-color: #f9fafb;
            color: #1f2933;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
        }

        .mpesa-card {
            background: #ffffff;
            border-radius: 16px;
            max-width: 460px;
            width: 100%;
            padding: 32px 28px;
            box-shadow: 0 25px 50px -12px rgba(15, 23, 42, 0.25);
            text-align: center;
        }

        .mpesa-card h1 {
            font-size: 1.5rem;
            margin-bottom: 12px;
        }

        .mpesa-card p {
            margin: 8px 0;
            line-height: 1.5;
        }

        .mpesa-card .status {
            font-weight: 600;
            margin-top: 16px;
        }

        .mpesa-card .status.success {
            color: #047857;
        }

        .mpesa-card .status.error {
            color: #b91c1c;
        }

        .mpesa-card .meta {
            font-size: 0.9rem;
            color: #52606d;
            margin-top: 12px;
        }

        .mpesa-card .actions {
            margin-top: 28px;
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .mpesa-card a.button {
            display: inline-block;
            padding: 12px 16px;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 600;
            transition: background 0.2s ease, color 0.2s ease;
        }

        .mpesa-card a.button.primary {
            background-color: #047857;
            color: #ffffff;
        }

        .mpesa-card a.button.secondary {
            background-color: #e4e7eb;
            color: #1f2933;
        }

        .mpesa-card a.button.primary:hover {
            background-color: #0f766e;
        }

        .mpesa-card a.button.secondary:hover {
            background-color: #cbd2d9;
        }
    </style>
</head>
<body>
<div class="mpesa-card">
    <h1>{{ trans('update.mpesa_checkout_heading') }}</h1>
    <p>{{ trans('update.mpesa_checkout_hint', ['phone' => $phone]) }}</p>

    @if($testMode)
        <p class="meta">{{ trans('update.mpesa_test_mode_notice') }}</p>
    @endif

    <p id="mpesaStatus" class="status">{{ trans('update.mpesa_waiting_message') }}</p>
    <p id="mpesaError" class="status error" style="display: none"></p>

    <div class="actions">
        <a id="mpesaContinue" class="button primary" href="{{ url('/payments/status') }}" style="display: none">{{ trans('update.mpesa_continue') }}</a>
        <a class="button secondary" href="{{ url('/cart') }}">{{ trans('update.mpesa_back_to_cart') }}</a>
    </div>
</div>

<script>
    (function () {
        const pollUrl = @json($pollUrl);
        const referenceId = @json($referenceId);
        const statusEl = document.getElementById('mpesaStatus');
        const errorEl = document.getElementById('mpesaError');
        const continueBtn = document.getElementById('mpesaContinue');
        const successMessage = @json(trans('update.mpesa_success_message'));
        const failMessage = @json(trans('update.mpesa_fail_message'));
        const waitingMessage = @json(trans('update.mpesa_waiting_message'));
        const timeoutMessage = @json(trans('update.mpesa_poll_timeout'));
        let attempts = 0;
        const maxAttempts = 45;

        if (!referenceId) {
            statusEl.textContent = failMessage;
            statusEl.classList.add('error');
            return;
        }

        function pollPayment() {
            fetch(`${pollUrl}?reference_id=${encodeURIComponent(referenceId)}`, {
                headers: {
                    'Accept': 'application/json'
                }
            }).then(function (response) {
                if (!response.ok) {
                    throw new Error('Network error');
                }
                return response.json();
            }).then(function (data) {
                if (data.paid) {
                    statusEl.textContent = successMessage;
                    statusEl.classList.add('success');
                    continueBtn.style.display = 'inline-block';
                    setTimeout(function () {
                        window.location.href = data.redirect || '{{ url('/payments/status') }}';
                    }, 1500);
                    return;
                }

                if (data.status === 'fail') {
                    statusEl.textContent = failMessage;
                    statusEl.classList.add('error');
                    if (data.message) {
                        errorEl.textContent = data.message;
                        errorEl.style.display = 'block';
                    }
                    return;
                }

                attempts += 1;
                if (attempts >= maxAttempts) {
                    statusEl.textContent = timeoutMessage;
                    statusEl.classList.add('error');
                    return;
                }

                setTimeout(pollPayment, 4000);
            }).catch(function () {
                attempts += 1;
                if (attempts >= maxAttempts) {
                    statusEl.textContent = timeoutMessage;
                    statusEl.classList.add('error');
                    return;
                }
                statusEl.textContent = waitingMessage;
                setTimeout(pollPayment, 5000);
            });
        }

        setTimeout(pollPayment, 3000);
    })();
</script>
</body>
</html>
