<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Mollie\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\HTTP\Client\CurlFactory;
use Magento\Store\Model\ScopeInterface;
use Psr\Log\LoggerInterface;

/**
 * Mollie's Payments API v2: the API key as a bearer token (test_… or
 * live_…), JSON both ways, amounts as {"currency": "EUR", "value": "10.00"}.
 */
class Api
{
    private const URL = 'https://api.mollie.com/v2/';

    public function __construct(
        private readonly CurlFactory $curlFactory,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly EncryptorInterface $encryptor,
        private readonly LoggerInterface $logger,
        private readonly int $timeout = 15
    ) {
    }

    /**
     * @throws LocalizedException
     */
    public function createPayment(array $payment, ?int $storeId): array
    {
        $answer = $this->send('POST', 'payments', $payment, $storeId);
        if (empty($answer['id']) || empty($answer['_links']['checkout']['href'])) {
            throw new LocalizedException(__('Mollie didn\'t create the payment. Please try again or choose another payment method.'));
        }

        return $answer;
    }

    /**
     * @throws LocalizedException
     */
    public function getPayment(string $id, ?int $storeId): array
    {
        if (!preg_match('/^tr_[A-Za-z0-9]+$/', $id)) {
            throw new LocalizedException(__('Not a Mollie payment.'));
        }

        return $this->send('GET', 'payments/' . $id, null, $storeId);
    }

    /**
     * @throws LocalizedException
     */
    public function refund(string $id, array $amount, ?int $storeId): array
    {
        return $this->send('POST', 'payments/' . $id . '/refunds', ['amount' => $amount], $storeId);
    }

    /**
     * Cancels an open payment, so it can't be paid any more (only some methods
     * allow it; the others expire).
     *
     * @throws LocalizedException
     */
    public function cancel(string $id, ?int $storeId): void
    {
        $this->send('DELETE', 'payments/' . $id, null, $storeId);
    }

    public function isConfigured(?int $storeId): bool
    {
        return $this->apiKey($storeId) !== '';
    }

    /**
     * Mollie's amount value: two decimals, a string.
     */
    public static function value(float $amount): string
    {
        return number_format($amount, 2, '.', '');
    }

    /**
     * @throws LocalizedException
     */
    private function send(string $method, string $path, ?array $body, ?int $storeId): array
    {
        $key = $this->apiKey($storeId);
        if ($key === '') {
            throw new LocalizedException(__('Mollie payments are not set up: the API key is missing.'));
        }
        try {
            $curl = $this->curlFactory->create();
            $curl->setTimeout($this->timeout);
            $curl->addHeader('Authorization', 'Bearer ' . $key);
            $curl->addHeader('Accept', 'application/json');
            if ($method === 'POST') {
                $curl->addHeader('Content-Type', 'application/json');
                $curl->post(self::URL . $path, (string) json_encode($body, JSON_UNESCAPED_SLASHES));
            } elseif ($method === 'DELETE') {
                $curl->setOption(CURLOPT_CUSTOMREQUEST, 'DELETE');
                $curl->get(self::URL . $path);
            } else {
                $curl->get(self::URL . $path);
            }
            $status = $curl->getStatus();
            $answer = json_decode((string) $curl->getBody(), true);
        } catch (\Throwable $e) {
            $this->logger->warning('Zero Mollie: ' . $path . ': ' . $e->getMessage());
            throw new LocalizedException(__('Mollie is not answering. Please try again in a minute.'), $e);
        }
        if ($status < 200 || $status >= 300 || !is_array($answer)) {
            $detail = is_array($answer) ? (string) ($answer['detail'] ?? '') : '';
            $this->logger->warning(sprintf('Zero Mollie: %s: HTTP %d %s', $path, $status, $detail));
            throw new LocalizedException(__('Mollie refused the request: %1', $detail !== '' ? $detail : 'HTTP ' . $status));
        }

        return $answer;
    }

    private function apiKey(?int $storeId): string
    {
        $value = trim((string) $this->scopeConfig->getValue('payment/zero_mollie/api_key', ScopeInterface::SCOPE_STORE, $storeId));

        // Read through the Encrypted backend model its config.xml default names, the
        // value comes decrypted; one stored some other way may still be encrypted.
        return preg_match('/^\d+:\d+:/', $value) ? trim((string) $this->encryptor->decrypt($value)) : $value;
    }
}
