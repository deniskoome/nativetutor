<?php

namespace App\PaymentChannels\Drivers\Mpesa;

use App\Models\Order;
use App\Models\PaymentChannel;
use App\PaymentChannels\BasePaymentChannel;
use App\PaymentChannels\IChannel;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class Channel extends BasePaymentChannel implements IChannel
{
    protected string $currency = 'KES';
    protected $test_mode;

    protected $consumer_key;
    protected $consumer_secret;
    protected $short_code;
    protected $passkey;

    protected array $credentialItems = [
        'consumer_key',
        'consumer_secret',
        'short_code',
        'passkey',
    ];

    public function __construct(PaymentChannel $paymentChannel)
    {
        $this->setCredentialItems($paymentChannel);
    }

    public function paymentRequest(Order $order)
    {
        $order->loadMissing('user');
        $user = $order->user;

        $phone = $user ? $this->formatPhoneNumber($user->mobile) : null;

        if (empty($phone)) {
            return $this->failedResponse(trans('update.mpesa_missing_phone'));
        }

        if (empty($this->consumer_key) || empty($this->consumer_secret) || empty($this->short_code) || empty($this->passkey)) {
            return $this->failedResponse(trans('update.mpesa_missing_credentials'));
        }

        $amount = (int) round($this->makeAmountByCurrency($order->total_amount, $this->currency));

        if ($amount <= 0) {
            return $this->failedResponse(trans('update.mpesa_invalid_amount'));
        }

        $token = $this->getAccessToken();

        if (empty($token)) {
            return $this->failedResponse(trans('update.mpesa_token_error'));
        }

        $timestamp = Carbon::now('Africa/Nairobi')->format('YmdHis');
        $password = base64_encode($this->short_code . $this->passkey . $timestamp);

        try {
            $response = Http::withToken($token)->post($this->baseUrl() . '/mpesa/stkpush/v1/processrequest', [
                'BusinessShortCode' => $this->short_code,
                'Password' => $password,
                'Timestamp' => $timestamp,
                'TransactionType' => 'CustomerPayBillOnline',
                'Amount' => $amount,
                'PartyA' => $phone,
                'PartyB' => $this->short_code,
                'PhoneNumber' => $phone,
                'CallBackURL' => $this->makeCallbackUrl(),
                'AccountReference' => (string) $order->id,
                'TransactionDesc' => trans('update.mpesa_transaction_description', ['order' => $order->id]),
            ]);
        } catch (\Throwable $exception) {
            return $this->failedResponse($exception->getMessage());
        }

        $body = $response->json();

        if ($response->successful() && isset($body['ResponseCode']) && (string) $body['ResponseCode'] === '0') {
            $order->reference_id = $body['CheckoutRequestID'] ?? null;
            $this->updatePaymentData($order, [
                'MerchantRequestID' => $body['MerchantRequestID'] ?? null,
                'CheckoutRequestID' => $order->reference_id,
                'ResponseCode' => $body['ResponseCode'] ?? null,
                'ResponseDescription' => $body['ResponseDescription'] ?? null,
                'CustomerMessage' => $body['CustomerMessage'] ?? null,
            ]);

            return view('web.default.cart.channels.mpesa', [
                'order' => $order,
                'referenceId' => $order->reference_id,
                'pollUrl' => route('payment_verify', ['gateway' => 'Mpesa']),
                'phone' => $user->mobile,
                'testMode' => (bool) $this->test_mode,
            ]);
        }

        $message = $body['errorMessage'] ?? $body['ResponseDescription'] ?? trans('cart.gateway_error');

        return $this->failedResponse($message);
    }

    public function verify(Request $request)
    {
        $payload = json_decode($request->getContent(), true);

        if (json_last_error() === JSON_ERROR_NONE && isset($payload['Body']['stkCallback'])) {
            return $this->handleCallback($payload['Body']['stkCallback']);
        }

        $callback = $request->input('Body.stkCallback');
        if (is_array($callback)) {
            return $this->handleCallback($callback);
        }

        $referenceId = $request->input('reference_id') ?? $request->input('CheckoutRequestID');

        if (!empty($referenceId)) {
            return $this->queryCheckout($referenceId);
        }

        return null;
    }

    protected function handleCallback(array $callback)
    {
        $checkoutRequestId = $callback['CheckoutRequestID'] ?? null;

        if (empty($checkoutRequestId)) {
            return null;
        }

        $order = Order::where('reference_id', $checkoutRequestId)->first();

        if (empty($order)) {
            return null;
        }

        $resultCode = (int) ($callback['ResultCode'] ?? -1);

        $data = [
            'MerchantRequestID' => $callback['MerchantRequestID'] ?? null,
            'CheckoutRequestID' => $checkoutRequestId,
            'ResultCode' => $resultCode,
            'ResultDesc' => $callback['ResultDesc'] ?? null,
        ];

        if (!empty($callback['CallbackMetadata']['Item'])) {
            $data['CallbackMetadata'] = $callback['CallbackMetadata']['Item'];
        }

        if ($resultCode === 0) {
            $order->status = Order::$paying;
        } else {
            $order->status = Order::$fail;
        }

        $this->updatePaymentData($order, $data);

        return $order->fresh();
    }

    protected function queryCheckout(string $referenceId)
    {
        $order = Order::where('reference_id', $referenceId)->first();

        if (empty($order)) {
            return null;
        }

        if ($order->status === Order::$paid || $order->status === Order::$paying) {
            return $order;
        }

        $token = $this->getAccessToken();

        if (empty($token)) {
            return $order;
        }

        $timestamp = Carbon::now('Africa/Nairobi')->format('YmdHis');
        $password = base64_encode($this->short_code . $this->passkey . $timestamp);

        try {
            $response = Http::withToken($token)->post($this->baseUrl() . '/mpesa/stkpushquery/v1/query', [
                'BusinessShortCode' => $this->short_code,
                'Password' => $password,
                'Timestamp' => $timestamp,
                'CheckoutRequestID' => $referenceId,
            ]);
        } catch (\Throwable $exception) {
            return $order;
        }

        $body = $response->json();

        if (!$response->successful()) {
            return $order;
        }

        $data = ['query' => $body];

        if (isset($body['ResultCode'])) {
            $resultCode = (int) $body['ResultCode'];

            if ($resultCode === 0) {
                $order->status = Order::$paying;
            } elseif ($resultCode > 0 && $resultCode !== 1) {
                $order->status = Order::$fail;
            }
        }

        $this->updatePaymentData($order, $data);

        return $order->fresh();
    }

    protected function getAccessToken(): ?string
    {
        try {
            $response = Http::withBasicAuth($this->consumer_key, $this->consumer_secret)
                ->get($this->baseUrl() . '/oauth/v1/generate', [
                    'grant_type' => 'client_credentials',
                ]);
        } catch (\Throwable $exception) {
            return null;
        }

        if (!$response->successful()) {
            return null;
        }

        return $response->json('access_token');
    }

    protected function baseUrl(): string
    {
        return $this->test_mode ? 'https://sandbox.safaricom.co.ke' : 'https://api.safaricom.co.ke';
    }

    protected function makeCallbackUrl(): string
    {
        return route('payment_verify_post', ['gateway' => 'Mpesa']);
    }

    protected function formatPhoneNumber(?string $phone): ?string
    {
        if (empty($phone)) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $phone);

        if (empty($digits)) {
            return null;
        }

        if (Str::startsWith($digits, '0')) {
            $digits = '254' . substr($digits, 1);
        } elseif (Str::startsWith($digits, '7')) {
            $digits = '254' . $digits;
        } elseif (!Str::startsWith($digits, '254')) {
            $digits = '254' . ltrim($digits, '+');
        }

        return strlen($digits) >= 10 ? $digits : null;
    }

    protected function failedResponse(string $message)
    {
        $toastData = [
            'title' => trans('cart.fail_purchase'),
            'msg' => $message,
            'status' => 'error'
        ];

        return redirect()->back()->with(['toast' => $toastData])->withInput();
    }

    protected function updatePaymentData(Order $order, array $data): void
    {
        $existing = [];

        if (!empty($order->payment_data)) {
            $existing = json_decode($order->payment_data, true) ?: [];
        }

        $payload = array_merge($existing, array_filter($data, fn($value) => $value !== null));

        $order->payment_data = json_encode($payload);
        $order->save();
    }
}
