<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Mollie\Model;

use Magento\Framework\Api\AttributeValueFactory;
use Magento\Framework\Api\ExtensionAttributesFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Model\Context;
use Magento\Framework\Model\ResourceModel\AbstractResource;
use Magento\Framework\Registry;
use Magento\Framework\UrlInterface;
use Magento\Payment\Helper\Data as PaymentData;
use Magento\Payment\Model\InfoInterface;
use Magento\Payment\Model\Method\AbstractMethod;
use Magento\Payment\Model\Method\Logger;
use Magento\Quote\Api\Data\CartInterface;
use Magento\Sales\Model\Order;
use Magento\Store\Model\ScopeInterface;
use Zero\Checkout\Controller\Checkout\Placeorder;

/**
 * Mollie's page (zero_mollie): the customer picks the method there (iDEAL,
 * Bancontact, cards, SEPA, Klarna...). The order waits in pending payment and
 * follows Mollie's answer on return and webhook (OrderUpdater). Credit memos
 * refund through Mollie.
 */
class Payment extends AbstractMethod
{
    public const CODE = 'zero_mollie';
    public const PAYMENT_ID = 'mollie_payment_id';

    /** Locales Mollie's page speaks; another one is left to Mollie. */
    private const LOCALES = [
        'en_US', 'en_GB', 'nl_NL', 'nl_BE', 'fr_FR', 'fr_BE', 'de_DE', 'de_AT', 'de_CH', 'es_ES', 'ca_ES',
        'pt_PT', 'it_IT', 'nb_NO', 'sv_SE', 'fi_FI', 'da_DK', 'is_IS', 'hu_HU', 'pl_PL', 'lv_LV', 'lt_LT',
    ];

    /**
     * @var string
     */
    protected $_code = self::CODE;

    /**
     * @var bool
     */
    protected $_isInitializeNeeded = true;

    /**
     * @var bool
     */
    protected $_canUseInternal = false;

    /**
     * @var bool
     */
    protected $_canRefund = true;

    /**
     * @var bool
     */
    protected $_canRefundInvoicePartial = true;

    /**
     * @SuppressWarnings(PHPMD.ExcessiveParameterList)
     */
    public function __construct(
        Context $context,
        Registry $registry,
        ExtensionAttributesFactory $extensionFactory,
        AttributeValueFactory $customAttributeFactory,
        PaymentData $paymentData,
        ScopeConfigInterface $scopeConfig,
        Logger $logger,
        private readonly Api $api,
        private readonly UrlInterface $urlBuilder,
        ?AbstractResource $resource = null,
        ?AbstractDb $resourceCollection = null,
        array $data = []
    ) {
        parent::__construct(
            $context,
            $registry,
            $extensionFactory,
            $customAttributeFactory,
            $paymentData,
            $scopeConfig,
            $logger,
            $resource,
            $resourceCollection,
            $data
        );
    }

    /**
     * @param CartInterface|null $quote
     * @return bool
     */
    public function isAvailable(?CartInterface $quote = null)
    {
        return parent::isAvailable($quote) && $this->api->isConfigured($quote ? (int) $quote->getStoreId() : null);
    }

    /**
     * @param string $paymentAction
     * @param object $stateObject
     * @return $this
     * @throws LocalizedException
     */
    public function initialize($paymentAction, $stateObject)
    {
        $payment = $this->getInfoInstance();
        /** @var Order $order */
        $order = $payment->getOrder();
        $reference = (string) $order->getIncrementId();
        $storeId = (int) $order->getStoreId();
        $returnUrl = $this->urlBuilder->getUrl('zero_mollie/payment/return', [
            '_secure' => true,
            '_query' => ['order' => $reference],
        ]);
        $params = [
            'amount' => self::amount($order, (float) $order->getGrandTotal()),
            'description' => (string) __('Order #%1', $reference),
            'redirectUrl' => $returnUrl,
            'cancelUrl' => $returnUrl,
            'metadata' => ['order_id' => $reference],
        ];
        $webhookUrl = $this->urlBuilder->getUrl('zero_mollie/payment/webhook', [
            '_secure' => true,
            '_query' => ['order' => $reference],
        ]);
        // Mollie refuses a webhook it can't reach (a development site); the return still updates the order.
        if (self::isPublic($webhookUrl)) {
            $params['webhookUrl'] = $webhookUrl;
        }
        $locale = (string) $this->_scopeConfig->getValue('general/locale/code', ScopeInterface::SCOPE_STORE, $storeId);
        if (in_array($locale, self::LOCALES, true)) {
            $params['locale'] = $locale;
        }
        $answer = $this->api->createPayment($params, $storeId);

        $payment->setAdditionalInformation(self::PAYMENT_ID, (string) $answer['id']);
        $payment->setAdditionalInformation(Placeorder::REDIRECT_URL, (string) $answer['_links']['checkout']['href']);
        $order->setCanSendNewEmailFlag(false);
        $stateObject->setState(Order::STATE_PENDING_PAYMENT);
        $stateObject->setStatus(Order::STATE_PENDING_PAYMENT);
        $stateObject->setIsNotified(false);

        return $this;
    }

    /**
     * @param InfoInterface $payment
     * @param float $amount In the base currency
     * @return $this
     * @throws LocalizedException
     */
    public function refund(InfoInterface $payment, $amount)
    {
        $id = (string) $payment->getAdditionalInformation(self::PAYMENT_ID);
        if ($id === '') {
            throw new LocalizedException(__('This order has no Mollie payment to refund.'));
        }
        /** @var Order $order */
        $order = $payment->getOrder();
        $refund = $this->api->refund(
            $id,
            self::amount($order, (float) $amount * (float) ($order->getBaseToOrderRate() ?: 1)),
            (int) $order->getStoreId()
        );
        $payment->setTransactionId((string) ($refund['id'] ?? $id . '-refund'))->setIsTransactionClosed(true);

        return $this;
    }

    /**
     * An amount in the order's currency, as Mollie takes it.
     *
     * @return array{currency: string, value: string}
     */
    public static function amount(Order $order, float $amount): array
    {
        return ['currency' => (string) $order->getOrderCurrencyCode(), 'value' => Api::value($amount)];
    }

    /**
     * Whether a URL is on the internet (not localhost, a private address or a
     * development domain such as .test).
     */
    public static function isPublic(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if ($host === '' || !str_contains($host, '.') || preg_match('/\.(test|local|localhost|example|invalid)$/', $host)) {
            return false;
        }
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return (bool) filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
        }

        return true;
    }
}
