<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 *
 * Zero_Mollie: payment on Mollie's page (iDEAL, Bancontact, cards, SEPA,
 * Klarna and the other methods switched on in the Mollie account), for Zero
 * Checkout. See README.md.
 */
use Magento\Framework\Component\ComponentRegistrar;

ComponentRegistrar::register(ComponentRegistrar::MODULE, 'Zero_Mollie', __DIR__);
