<?php

class Affiliate_WP_Exchange extends Affiliate_WP_Base {

	/**
	 * The transaction object
	 *
	 * @access  private
	 * @since   1.3
	*/
	private $transaction;

	public function init() {

		$this->context = 'it-exchange';

		add_action( 'it_exchange_add_transaction_success', array( $this, 'add_pending_referral' ), 10 );
		add_action( 'it_exchange_update_transaction_status', array( $this, 'mark_referral_complete' ), 10, 3 );
		add_action( 'it_exchange_update_transaction_status', array( $this, 'revoke_referral_on_refund' ), 10, 3 );
		add_action( 'it_exchange_update_transaction_status', array( $this, 'revoke_referral_on_void' ), 10, 3 );
		add_action( 'wp_trash_post', array( $this, 'revoke_referral_on_delete' ), 10 );

		// coupon code tracking actions and filters
		add_action( 'it_exchange_basics_coupon_coupon_edit_screen_end_fields', array( $this, 'coupon_edit' ) );
		add_action( 'it_exchange_basic_coupons_saved_coupon', array( $this, 'store_coupon_affiliate' ), 10, 2 );

		add_filter( 'affwp_referral_reference_column', array( $this, 'reference_link' ), 10, 2 );
	}

	public function add_pending_referral( $transaction_id = 0 ) {

		$has_coupon  = false;
		$this->transaction = apply_filters( 'affwp_get_it_exchange_transaction', get_post_meta( $transaction_id, '_it_exchange_cart_object', true ) );

		if ( $this->transaction->coupons && is_array( $this->transaction->coupons ) ) {

			if( ! empty( $this->transaction->coupons['cart'] ) ) {

				foreach( $this->transaction->coupons['cart'] as $coupon ) {

					$affiliate_id = get_post_meta( $coupon['id'], 'affwp_coupon_affiliate', true );

					if( ! $affiliate_id ) {
						continue;
					}

					$this->affiliate_id = $affiliate_id;
					$has_coupon = true;
					break;

				}

			}

		}

		if( $this->was_referred() || $has_coupon ) {

			if( $this->get_affiliate_email() === $this->transaction->shipping_address['email'] ) {
				return; // Customers cannot refer themselves
			}

			$total = 0;
			foreach( $this->transaction->products as $product ) {

				$total += $product['product_subtotal'];

			}

			if( affiliate_wp()->settings->get( 'exclude_tax' ) ) {

				$options  = it_exchange_get_option( 'addon_taxes_simple' );
				$tax_rate = empty( $options['default-tax-rate'] ) ? 1 : (float) $options['default-tax-rate'];
				
				$tax      = $total * ( $tax_rate / 100 );
				$amount   = $this->transaction->total - $tax;
			
			} else {
			
				$amount = $this->transaction->total;
			
			}

			$referral_total = $this->calculate_referral_amount( $amount, $transaction_id );

			$this->insert_pending_referral( $referral_total, $transaction_id, $this->transaction->description );

		}

	}

	public function mark_referral_complete( $transaction, $old_status, $old_status_cleared ) {

		$new_status         = it_exchange_get_transaction_status( $transaction->ID );
		$new_status_cleared = it_exchange_transaction_is_cleared_for_delivery( $transaction->ID );

		if ( ( $new_status != $old_status ) && ! $old_status_cleared && $new_status_cleared ) {

			$this->complete_referral( $transaction->ID );

		}

	}

	public function revoke_referral_on_refund( $transaction, $old_status, $old_status_cleared ) {

		if( ! affiliate_wp()->settings->get( 'revoke_on_refund' ) ) {
			return;
		}

		if( 'refunded' == $transaction->get_status() && 'paid' == $old_status ) {

			$this->reject_referral( $transaction->ID );

		}

	}

	public function revoke_referral_on_void( $transaction, $old_status, $old_status_cleared ) {

		if( ! affiliate_wp()->settings->get( 'revoke_on_refund' ) ) {
			return;
		}

		if( 'voided' == $transaction->get_status() ) {

			$this->reject_referral( $transaction->ID );

		}

	}

	public function revoke_referral_on_delete( $transaction_id = 0 ) {

		if( ! affiliate_wp()->settings->get( 'revoke_on_refund' ) ) {
			return;
		}

		$post = get_post( $transaction_id );

		if( ! $post ) {
			return;
		}

		if( 'it_exchange_tran' != $post->post_type ) {
			return;
		}

		$this->reject_referral( $transaction_id );

	}

	public function reference_link( $reference = 0, $referral ) {

		if( empty( $referral->context ) || 'it-exchange' != $referral->context ) {

			return $reference;

		}

		$url = get_edit_post_link( $reference );

		return '<a href="' . esc_url( $url ) . '">' . $reference . '</a>';
	}

	/**
	 * Shows the affiliate drop down on the coupon edit / add screens
	 *
	 * @access  public
	 * @since   1.3
	*/
	public function coupon_edit( $form ) {

		add_filter( 'affwp_is_admin_page', '__return_true' );
		affwp_admin_scripts();

		$coupon_id    = ! empty( $_REQUEST['post'] ) ? absint( $_REQUEST['post'] ) : 0;
		$affiliate_id = get_post_meta( $coupon_id, 'affwp_coupon_affiliate', true );
		$user_id      = affwp_get_affiliate_user_id( $affiliate_id );
		$user         = get_userdata( $user_id );
		$user_name    = $user ? $user->user_login : '';
?>
		<div class="field affwp-coupon">
			<th scope="row" valign="top">
				<label for="user_name"><?php _e( 'Affiliate coupon?', 'affiliate-wp' ); ?></label>
			</th>
			<td>
				<span class="affwp-ajax-search-wrap">
					<input type="hidden" name="user_id" id="user_id" value="<?php echo esc_attr( $user_id ); ?>" />
					<input type="text" name="user_name" id="user_name" value="<?php echo esc_attr( $user_name ); ?>" class="affwp-user-search" autocomplete="off" />
					<img class="affwp-ajax waiting" src="<?php echo admin_url('images/wpspin_light.gif'); ?>" style="display: none;"/>
				</span>
				<div id="affwp_user_search_results"></div>
				<p class="description"><?php _e( 'If you would like to connect this coupon to an affiliate, enter the name of the affiliate it belongs to.', 'edd' ); ?></p>
			</td>
		</div>
<?php
	}

	/**
	 * Stores the affiliate ID in the coupons meta if it is an affiliate's coupon
	 *
	 * @access  public
	 * @since   1.3
	*/
	public function store_coupon_affiliate( $coupon_id = 0, $data = array() ) {

		if( empty( $_POST['user_id'] ) && empty( $_POST['user_name'] ) ) {
			return;
		}

		if( empty( $_POST['user_id'] ) ) {
			$user = get_user_by( 'login', $_POST['user_name'] );
			if( $user ) {
				$user_id = $user->ID;
			}
		} else {
			$user_id = absint( $_POST['user_id'] );
		}

		$affiliate_id = affwp_get_affiliate_id( $user_id );

		update_post_meta( $coupon_id, 'affwp_coupon_affiliate', $affiliate_id );

	}

}
new Affiliate_WP_Exchange;