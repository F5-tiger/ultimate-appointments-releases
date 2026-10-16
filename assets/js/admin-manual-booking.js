/**
 * 手動建立預約頁：選定會員後自動帶入姓名/電話，管理員仍可手動覆寫。
 * 換會員時，上一位帶入的資料一定會被換掉（空值也是），見 fill()。
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

		// 上一次「自動帶入」的值（v3.4.1）。用來分辨欄位裡的是會員資料還是管理者手打的：
		// 以前只在新會員有值時才覆寫，從會員 A 換到沒留電話的會員 B，A 的電話就留在
		// 欄位裡、跟著 B 的預約存進去。
		var autofill = { name: '', phone: '' };
		var seq = 0;

		/**
		 * 把會員資料填進欄位。
		 *
		 * 還是上一位會員自動帶入的值（或空白）→ 一律換成這一位的，空值也照填（清掉）。
		 * 管理者自己改過的 → 這一位有資料才覆寫，沒有就保留手打的內容。
		 */
		function fill( $field, key, value ) {
			var current = $.trim( $field.val() );
			var untouched = '' === current || current === autofill[ key ];
			value = value ? String( value ) : '';

			if ( untouched || value ) {
				$field.val( value );
				autofill[ key ] = value;
			}
		}

		$customer.on( 'change', function () {
			var customerId = parseInt( $customer.val(), 10 ) || 0;
			var mySeq = ++seq;

			// 改回「不綁定會員」：上一位會員帶入的資料要拿掉，手打的留著。
			if ( ! customerId ) {
				fill( $name, 'name', '' );
				fill( $phone, 'phone', '' );
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
				// 快速換會員時，上一位的回應晚到不能蓋掉現在這一位的資料。
				if ( mySeq !== seq || ( parseInt( $customer.val(), 10 ) || 0 ) !== customerId ) {
					return;
				}
				var data = ( response && response.success && response.data ) ? response.data : {};
				fill( $name, 'name', data.name );
				fill( $phone, 'phone', data.phone );
			} );
		} );
	} );
} )( jQuery );
