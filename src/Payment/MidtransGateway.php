<?php

declare(strict_types=1);

namespace App\Payment;

/**
 * Midtrans Payment Gateway Implementation.
 *
 * Mendukung:
 * - QRIS (QR Code Indonesian Standard)
 * - E-Wallet (GoPay, OVO, Dana, ShopeePay)
 * - Virtual Account (BCA, Mandiri, BRI, BNI)
 * - Credit/Debit Card
 *
 * Dokumentasi: https://docs.midtrans.com/
 */
class MidtransGateway implements PaymentGateway
{
    private string $serverKey;
    /** @var string Client key for frontend Midtrans Snap integration */
    private string $clientKey;
    private string $environment;
    private string $baseUrl;

    public function __construct()
    {
        $this->serverKey = getenv('MIDTRANS_SERVER_KEY') ?: '';
        $this->clientKey = getenv('MIDTRANS_CLIENT_KEY') ?: '';
        $this->environment = getenv('MIDTRANS_ENVIRONMENT') ?: 'sandbox';

        $this->baseUrl = $this->environment === 'production'
            ? 'https://api.midtrans.com'
            : 'https://api.sandbox.midtrans.com';
    }

    /**
     * Get client key for frontend JavaScript integration.
     */
    public function getClientKey(): string
    {
        return $this->clientKey;
    }

    public function getName(): string
    {
        return 'Midtrans';
    }

    /**
     * Buat transaksi di Midtrans.
     *
     * @param array $orderData Data transaksi
     * @return array{success: bool, payment_url?: string, qr_code?: string, va_number?: string, order_id?: string, error?: string}
     */
    public function createTransaction(array $orderData): array
    {
        if ($this->serverKey === '') {
            return ['success' => false, 'error' => 'Midtrans server key not configured'];
        }

        $orderId = $orderData['order_id'] ?? '';
        $amount = $orderData['amount'] ?? 0;
        $customerName = $orderData['customer_name'] ?? 'Customer';
        $customerEmail = $orderData['customer_email'] ?? '';
        $items = $orderData['items'] ?? [];
        $paymentMethod = $orderData['payment_method'] ?? 'qris';
        $callbackUrl = $orderData['callback_url'] ?? '';

        // Build transaction payload
        $payload = [
            'transaction_details' => [
                'order_id' => $orderId,
                'gross_amount' => (int) round($amount),
            ],
            'customer_details' => [
                'first_name' => $customerName,
                'email' => $customerEmail ?: "customer-{$orderId}@kasir.local",
            ],
            'callbacks' => [
                'finish' => $callbackUrl ?: (getenv('APP_URL') . '/payment_status.php?order_id=' . $orderId),
            ],
        ];

        // Add items — always use single item with gross_amount to avoid
        // validation mismatch between item_details sum and gross_amount.
        $payload['item_details'] = [[
            'id' => 'POS-' . $orderId,
            'price' => (int) round($amount),
            'quantity' => 1,
            'name' => 'Transaksi #' . $orderId,
        ]];

        // Set payment method specific config
        $payload = match ($paymentMethod) {
            'qris' => $this->addQrisConfig($payload),
            'gopay', 'ovo', 'dana', 'shopeepay' => $this->addEwalletConfig($payload, $paymentMethod),
            'bca_va', 'mandiri_va', 'bri_va', 'bni_va' => $this->addVaConfig($payload, $paymentMethod),
            'credit_card' => $this->addCreditCardConfig($payload),
            default => $this->addQrisConfig($payload), // Default to QRIS
        };

        return $this->apiRequest('POST', '/v2/charge', $payload);
    }

    /**
     * QRIS payment configuration.
     */
    private function addQrisConfig(array $payload): array
    {
        $payload['payment_type'] = 'qris';
        $payload['qris'] = [
            'acquirer' => 'gopay',
        ];
        return $payload;
    }

    /**
     * E-Wallet payment configuration.
     */
    private function addEwalletConfig(array $payload, string $method): array
    {
        $ewalletType = match ($method) {
            'gopay' => 'gopay',
            'ovo' => 'ovo',
            'dana' => 'dana',
            'shopeepay' => 'shopeepay',
            default => 'gopay',
        };

        $payload['payment_type'] = $ewalletType;
        $payload[$ewalletType] = [
            'callback_url' => $payload['callbacks']['finish'] ?? '',
        ];

        return $payload;
    }

    /**
     * Virtual Account payment configuration.
     */
    private function addVaConfig(array $payload, string $method): array
    {
        $bank = match ($method) {
            'bca_va' => 'bca',
            'mandiri_va' => 'mandiri',
            'bri_va' => 'bri',
            'bni_va' => 'bni',
            default => 'bca',
        };

        $payload['payment_type'] = 'bank_transfer';
        $payload['bank_transfer'] = [
            'bank' => $bank,
        ];

        return $payload;
    }

    /**
     * Credit card configuration.
     */
    private function addCreditCardConfig(array $payload): array
    {
        $payload['payment_type'] = 'credit_card';
        $payload['credit_card'] = [
            'secure' => true,
        ];

        return $payload;
    }

    /**
     * Verify transaction status.
     */
    public function verifyTransaction(string $orderId): array
    {
        if ($this->serverKey === '') {
            return ['success' => false, 'status' => 'error', 'error' => 'Server key not configured'];
        }

        $response = $this->apiRequest('GET', "/v2/{$orderId}/status");

        if (!$response['success']) {
            return [
                'success' => false,
                'status' => 'error',
                'error' => $response['error'] ?? 'Unknown error',
            ];
        }

        $data = $response['data'] ?? [];

        return [
            'success' => true,
            'status' => $this->mapStatus($data['transaction_status'] ?? 'pending'),
            'payment_type' => $data['payment_type'] ?? '',
            'gross_amount' => (float) ($data['gross_amount'] ?? 0),
            'paid_at' => $data['settlement_time'] ?? $data['transaction_time'] ?? '',
        ];
    }

    /**
     * Process refund via Midtrans.
     */
    public function refund(string $orderId, float $amount, string $reason = ''): array
    {
        if ($this->serverKey === '') {
            return ['success' => false, 'error' => 'Server key not configured'];
        }

        $payload = [
            'refund_key' => $orderId . '-refund-' . time(),
            'amount' => (int) round($amount),
            'reason' => $reason ?: 'Refund requested',
        ];

        $response = $this->apiRequest('POST', "/v2/{$orderId}/refund", $payload);

        if (!$response['success']) {
            return [
                'success' => false,
                'error' => $response['error'] ?? 'Refund failed',
            ];
        }

        return [
            'success' => true,
            'refund_id' => $response['data']['refund_id'] ?? '',
        ];
    }

    /**
     * Handle webhook notification dari Midtrans.
     *
     * Verifikasi signature, lalu return normalized status.
     */
    public function handleWebhook(array $payload): array
    {
        // Verify signature
        $orderId = $payload['order_id'] ?? '';
        $statusCode = $payload['status_code'] ?? '';
        $grossAmount = $payload['gross_amount'] ?? '';
        $serverKey = $this->serverKey;
        $signatureKey = $payload['signature_key'] ?? '';

        $expectedSignature = hash('sha512', $orderId . $statusCode . $grossAmount . $serverKey);

        if ($signatureKey !== $expectedSignature) {
            error_log('Midtrans webhook: Invalid signature for order ' . $orderId);
            return [
                'order_id' => $orderId,
                'status' => 'invalid_signature',
            ];
        }

        return [
            'order_id' => $orderId,
            'status' => $this->mapStatus($payload['transaction_status'] ?? 'pending'),
            'payment_type' => $payload['payment_type'] ?? '',
            'gross_amount' => (float) ($payload['gross_amount'] ?? 0),
            'fraud_status' => $payload['fraud_status'] ?? '',
        ];
    }

    /**
     * Map Midtrans status to our normalized status.
     */
    private function mapStatus(string $midtransStatus): string
    {
        return match ($midtransStatus) {
            'capture', 'settlement' => 'paid',
            'pending' => 'pending',
            'deny', 'expire', 'cancel' => 'failed',
            'refund' => 'refunded',
            default => 'pending',
        };
    }

    /**
     * Extract QR code URL from Midtrans response actions array.
     * Midtrans QRIS returns QR URL in data.actions, not in qr_code_url.
     */
    private function extractQrUrl(array $body): ?string
    {
        $actions = $body['actions'] ?? [];
        foreach ($actions as $action) {
            if (($action['name'] ?? '') === 'generate-qr-code') {
                return $action['url'] ?? null;
            }
        }
        // Fallback: try generate-qr-code-v2
        foreach ($actions as $action) {
            if (($action['name'] ?? '') === 'generate-qr-code-v2') {
                return $action['url'] ?? null;
            }
        }
        return null;
    }

    /**
     * Make API request to Midtrans.
     */
    private function apiRequest(string $method, string $endpoint, array $data = []): array
    {
        try {
            $client = new \GuzzleHttp\Client([
                'base_uri' => $this->baseUrl,
                'headers' => [
                    'Authorization' => 'Basic ' . base64_encode($this->serverKey . ':'),
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ],
                'timeout' => 10,
            ]);

            $options = [];
            if ($method === 'POST' && !empty($data)) {
                $options['json'] = $data;
            }

            $response = $client->request($method, $endpoint, $options);
            $body = json_decode((string) $response->getBody(), true);

            // Midtrans returns error messages in body even with HTTP 200
            if (isset($body['status_code']) && (int) $body['status_code'] >= 400) {
                return [
                    'success' => false,
                    'error' => $body['status_message'] ?? 'Payment gateway error (' . ($body['status_code'] ?? '') . ')',
                ];
            }

            return [
                'success' => true,
                'data' => $body,
                'payment_url' => $body['redirect_url'] ?? $body['qr_code_url'] ?? $this->extractQrUrl($body),
                'qr_code' => $body['qr_code_url'] ?? $this->extractQrUrl($body),
                'va_number' => $body['va_numbers'][0]['va_number'] ?? null,
                'order_id' => $body['order_id'] ?? null,
            ];
        } catch (\GuzzleHttp\Exception\ClientException $e) {
            $respBody = (string) $e->getResponse()->getBody();
            $body = json_decode($respBody, true);
            $errMsg = $body['error_messages'][0] ?? $body['status_message'] ?? 'Payment gateway error (HTTP ' . $e->getResponse()->getStatusCode() . ')';
            error_log('Midtrans ClientException: ' . $errMsg . ' | body=' . substr($respBody, 0, 500));
            return [
                'success' => false,
                'error' => $errMsg,
            ];
        } catch (\Throwable $e) {
            error_log('Midtrans API error: ' . $e->getMessage() . ' | class=' . get_class($e));
            return [
                'success' => false,
                'error' => 'Payment gateway connection failed: ' . $e->getMessage(),
            ];
        }
    }
}
