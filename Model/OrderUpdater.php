<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Mollie\Model;

use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Model\Order;
use Zero\Checkout\Model\RedirectPayment\OrderPayment;
use Zero\Checkout\Model\RedirectPayment\ReturnResult;

/**
 * Brings an order in line with its Mollie payment, asked from Mollie itself
 * (the webhook only says which payment changed):
 * - paid, for the order's amount and currency: captured;
 * - authorized (Klarna and other pay-later methods): reserved, taken when
 *   captured in the Mollie dashboard;
 * - canceled, expired, failed: cancelled;
 * - open: nobody paid yet (NOT_STARTED);
 * - pending: nothing yet.
 */
class OrderUpdater
{
    public function __construct(
        private readonly Api $api,
        private readonly OrderPayment $orderPayment
    ) {
    }

    /**
     * @return string A ReturnResult state
     * @throws LocalizedException When Mollie doesn't answer
     */
    public function update(Order $order): string
    {
        $id = (string) $order->getPayment()?->getAdditionalInformation(Payment::PAYMENT_ID);
        if ($order->getPayment()?->getMethod() !== Payment::CODE || $id === '') {
            return ReturnResult::PENDING;
        }
        $settled = $this->orderPayment->settled($order);
        if ($settled !== null) {
            return $settled;
        }
        $payment = $this->api->getPayment($id, (int) $order->getStoreId());

        return match ((string) ($payment['status'] ?? '')) {
            'paid' => $this->paid($order, $payment, false),
            'authorized' => $this->paid($order, $payment, true),
            'canceled', 'expired', 'failed' => $this->failed($order, (string) $payment['status']),
            'open' => ReturnResult::NOT_STARTED,
            default => ReturnResult::PENDING,
        };
    }

    /**
     * Nobody paid: the payment is cancelled where the method allows it, and
     * the order too.
     */
    public function abandon(Order $order): void
    {
        try {
            $this->api->cancel((string) $order->getPayment()->getAdditionalInformation(Payment::PAYMENT_ID), (int) $order->getStoreId());
        } catch (LocalizedException) {
            // Not cancelable: it expires on its own.
        }
        $this->failed($order, (string) __('the customer came back without paying'));
    }

    private function paid(Order $order, array $payment, bool $authorizedOnly): string
    {
        $expected = Payment::amount($order, (float) $order->getGrandTotal());
        if (($payment['amount'] ?? null) != $expected) {
            $this->orderPayment->flag(
                $order,
                (string) __('Mollie reports a payment of a different amount; check it in the Mollie dashboard.'),
                sprintf(
                    'Mollie: %s %s, expected %s %s',
                    $payment['amount']['value'] ?? '?',
                    $payment['amount']['currency'] ?? '?',
                    $expected['value'],
                    $expected['currency']
                )
            );

            return ReturnResult::PENDING;
        }
        if ($authorizedOnly) {
            $this->orderPayment->authorize($order, (string) $payment['id']);
        } else {
            $this->orderPayment->capture($order, (string) $payment['id']);
        }

        return ReturnResult::PAID;
    }

    private function failed(Order $order, string $reason): string
    {
        $this->orderPayment->cancel($order, (string) __('Mollie payment did not go through: %1', $reason));

        return ReturnResult::FAILED;
    }
}
