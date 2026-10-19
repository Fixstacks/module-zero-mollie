<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Mollie\Controller\Payment;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Exception\LocalizedException;
use Zero\Checkout\Model\RedirectPayment\ReturnResult;
use Zero\Mollie\Model\OrderUpdater;
use Zero\Mollie\Model\Payment;

/**
 * Where Mollie's page sends the customer back (?order=<increment id>).
 * Asks Mollie how the payment went (OrderUpdater); nobody paid: the
 * payment (where it can be) and the order are cancelled. Then ReturnResult: the
 * success page, or the checkout page with the cart restored.
 */
class ReturnAction implements HttpGetActionInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly ReturnResult $returnResult,
        private readonly OrderUpdater $orderUpdater
    ) {
    }

    /**
     * @return Redirect
     */
    public function execute()
    {
        $order = $this->returnResult->lastOrder(Payment::CODE, (string) $this->request->getParam('order'));
        if (!$order) {
            return $this->returnResult->elsewhere();
        }
        try {
            $state = $this->orderUpdater->update($order);
            if ($state === ReturnResult::NOT_STARTED) {
                $this->orderUpdater->abandon($order);
            }
        } catch (LocalizedException) {
            $state = ReturnResult::UNCONFIRMED;
        }

        return $this->returnResult->redirect($state, 'Mollie');
    }
}
