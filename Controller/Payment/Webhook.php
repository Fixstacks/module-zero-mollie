<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Mollie\Controller\Payment;

use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Raw;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Model\OrderFactory;
use Zero\Mollie\Model\Payment;
use Zero\Checkout\Model\RedirectPayment\Webhooks;

/**
 * Mollie's webhook (?order=<increment id>, POST id=tr_…): unsigned by design,
 * it only says that a payment changed. The posted id must be the order's own
 * payment; its state is then asked from Mollie (OrderUpdater, now or from the
 * queue: Zero Checkout > Payment Webhooks), so a forged call can't change
 * anything.
 *
 * 200 when handled or not ours; 503 when Mollie can't be asked right now, so
 * it calls again.
 */
class Webhook implements HttpPostActionInterface, CsrfAwareActionInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly RawFactory $rawFactory,
        private readonly OrderFactory $orderFactory,
        private readonly Webhooks $webhooks
    ) {
    }

    /**
     * @return Raw
     */
    public function execute()
    {
        $result = $this->rawFactory->create()->setContents('');
        $reference = (string) $this->request->getParam('order', '');
        $order = $reference === '' ? null : $this->orderFactory->create()->loadByIncrementId($reference);
        if (!$order || !$order->getId() || $order->getPayment()?->getMethod() !== Payment::CODE
            || $order->getPayment()->getAdditionalInformation(Payment::PAYMENT_ID) !== (string) $this->request->getParam('id')
        ) {
            return $result->setHttpResponseCode(200);
        }
        try {
            $this->webhooks->handle($order, ['id' => (string) $this->request->getParam('id')]);
        } catch (LocalizedException) {
            return $result->setHttpResponseCode(503);
        }

        return $result->setHttpResponseCode(200);
    }

    /**
     * @inheritDoc
     */
    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    /**
     * Nothing here trusts the request: the state is asked from Mollie.
     *
     * @inheritDoc
     */
    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }
}
