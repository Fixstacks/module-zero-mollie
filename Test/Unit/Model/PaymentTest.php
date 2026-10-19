<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Mollie\Test\Unit\Model;

use PHPUnit\Framework\TestCase;
use Zero\Mollie\Model\Api;
use Zero\Mollie\Model\Payment;

class PaymentTest extends TestCase
{
    public function testAmountValueHasTwoDecimals(): void
    {
        $this->assertSame('10.00', Api::value(10));
        $this->assertSame('1234.57', Api::value(1234.567));
    }

    /**
     * Mollie refuses a webhook it can't reach: none is sent from a development site.
     */
    public function testWebhookOnlyForPublicSites(): void
    {
        $this->assertTrue(Payment::isPublic('https://zero.fixstacks.com/zero_mollie/payment/webhook/'));
        $this->assertFalse(Payment::isPublic('http://magento2.test:8080/zero_mollie/payment/webhook/'));
        $this->assertFalse(Payment::isPublic('http://localhost/zero_mollie/payment/webhook/'));
        $this->assertFalse(Payment::isPublic('http://192.168.1.10/zero_mollie/payment/webhook/'));
    }
}
