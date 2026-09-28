<?php
/**
 * Plugin Name: BingoCommerce Digital Tickets
 * Description: Hall-local paid-order digital entitlements, online QR references and refund/cancellation voiding.
 * Version: 0.1.0
 * Author: Bingo Innovation
 * Network: true
 * Text Domain: bingocommerce-digital-tickets
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) { exit; }

require_once __DIR__ . '/src/Migrations.php';
require_once __DIR__ . '/src/EntitlementSnapshot.php';
require_once __DIR__ . '/src/EntitlementVoiding.php';
require_once __DIR__ . '/src/DigitalTicket.php';
require_once __DIR__ . '/src/CustomerTicket.php';
require_once __DIR__ . '/src/Plugin.php';

BingoCommerce\DigitalTickets\Plugin::boot( __FILE__ );
