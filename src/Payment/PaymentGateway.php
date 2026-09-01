<?php

declare(strict_types=1);

namespace App\Payment;

/**
 * Interface untuk payment gateway integration.
 *
 * Mendukung berbagai gateway (Midtrans, Xendit, dll) dengan
 * method yang seragam untuk create transaction, verify, dan refund.
 */
interface PaymentGateway
{
    /**
     * Buat transaksi di payment gateway.
     *
     * @param array $orderData Data transaksi:
     *   - order_id: string (unique order ID)
     *   - amount: float (total amount in IDR)
     *   - customer_name: string
     *   - customer_email: string (optional)
     *   - items: array of ['name', 'price', 'quantity']
     *   - payment_method: string (qris, ewallet, va, etc.)
     *   - callback_url: string (webhook URL)
     *
     * @return array{success: bool, payment_url?: string, qr_code?: string, va_number?: string, order_id?: string, error?: string}
     */
    public function createTransaction(array $orderData): array;

    /**
     * Verifikasi status transaksi.
     *
     * @param string $orderId Order ID dari aplikasi
     * @return array{success: bool, status: string, payment_type?: string, gross_amount?: float, paid_at?: string}
     */
    public function verifyTransaction(string $orderId): array;

    /**
     * Process refund.
     *
     * @param string $orderId Order ID
     * @param float $amount Amount to refund
     * @param string $reason Refund reason
     * @return array{success: bool, refund_id?: string, error?: string}
     */
    public function refund(string $orderId, float $amount, string $reason = ''): array;

    /**
     * Handle webhook notification dari gateway.
     *
     * @param array $payload Raw webhook payload
     * @return array{order_id: string, status: string, payment_type?: string, gross_amount?: float}
     */
    public function handleWebhook(array $payload): array;

    /**
     * Get gateway display name.
     */
    public function getName(): string;
}
