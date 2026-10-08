/**
 * 手動建立預約頁：選定會員後自動帶入姓名/電話，管理員仍可手動覆寫。
 *
 * 會員搜尋本身是 WooCommerce 內建的 wc-enhanced-select（select2 +
 * woocommerce_json_search_customers 這支 AJAX），不是我們自己做的；
 * 這支檔案只負責「選定會員後」呼叫 uappt_get_customer 取得聯絡資訊並帶入表單。
 */
( function ( $ ) {
	'use strict';

	if ( typeof UAPPT_Admin_Customer === 'undefined' ) {
		return;
	}

	$( function () {
		var $customer = $( '#uappt-mb-customer' );
		var $name = $( '#uappt-mb-name' );
		var $phone = $( '#uappt-mb-phone' );

		if ( ! $customer.length ) {
			return;
		}

		$customer.on( 'change', function () {
			var customerId = parseInt( $customer.val(), 10 ) || 0;
			if ( ! customerId ) {
				return;
			}

			$.ajax( {
				url: UAPPT_Admin_Customer.ajax_url,
				method: 'GET',
				dataType: 'json',
				data: {
					action: 'uappt_get_customer',
					nonce: UAPPT_Admin_Customer.nonce,
					customer_id: customerId,
				},
			} ).done( function ( response ) {
				if ( response && response.success && response.data ) {
					if ( response.data.name ) {
						$name.val( response.data.name );
					}
					if ( response.data.phone ) {
						$phone.val( response.data.phone );
					}
				}
			} );
		} );
	} );
} )( jQuery );
