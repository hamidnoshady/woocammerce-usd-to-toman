<?php
/**
 * Integration scenarios for the USD-pegged pricing model.
 *
 * Included by run.php, which has booted WordPress, loaded lib.php and defined
 * the shared helpers. Run alone with USDTF_IT_ONLY=pegged.
 *
 * @package USDTF
 */

// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI test script.

// ---------------------------------------------------------------------------
// The harness: a Rial/Toman store and a product that carries only a Woo price.
// ---------------------------------------------------------------------------
usdtf_it_delete_products();
usdtf_it_reset_plugin_state();

usdtf_it_set_currency( 'IRT' );
$harness = usdtf_it_make_priced_product( 'Harness', 5000000 );

usdtf_it_assert_same( '5000000', usdtf_it_price( $harness->get_id() ), 'the fixture must hold the Woo price' );
usdtf_it_assert_same( '', usdtf_it_meta( $harness->get_id(), '_usdtf_usd_regular' ), 'the fixture must carry no plugin meta' );
usdtf_it_pass( 'the new-model harness runs' );

// Leave nothing behind for the legacy scenarios that follow in a full run.
usdtf_it_set_currency( null );
usdtf_it_delete_products();
