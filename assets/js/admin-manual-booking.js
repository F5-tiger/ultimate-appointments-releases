/**
 * 手動建單：SelectWoo 搜尋與聯絡資料讀取均採預約管理權限。
 * 切會員立即清除上一位的自動資料；載入或失敗時不得把舊資料送出。
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
		var config = UAPPT_Admin_Customer;
		var strings = config.i18n || {};
		var autofill = { name: '', phone: '' };
		var seq = 0;
		var pending = false;
		var failed = false;
		var $status = $( '<p/>', { 'class': 'description uappt-customer-status', role: 'status', 'aria-live': 'polite', tabindex: '-1' } ).insertAfter( $customer );

		// 不使用 WooCommerce 的訂單搜尋端點，也不改全站的權限或 AJAX 設定。
		if ( typeof $.fn.selectWoo === 'function' ) {
			$customer.selectWoo( {
				allowClear: true,
				placeholder: $customer.data( 'placeholder' ) || strings.search_placeholder,
				minimumInputLength: 1,
				ajax: {
					url: config.ajax_url,
					dataType: 'json',
					delay: 350,
					data: function ( params ) {
						return { action: 'uappt_search_customers', nonce: config.nonce, term: params.term };
					},
					processResults: function ( data ) {
						return data && Array.isArray( data.results ) ? data : { results: [] };
					}
				}
			} );
		}

		function fill( $field, key, value ) {
			var current = $.trim( $field.val() );
			value = value ? String( value ) : '';
			if ( '' === current || current === autofill[ key ] || value ) {
				$field.val( value );
				autofill[ key ] = value;
			}
		}
		function currentRequest( mySeq, customerId ) {
			return mySeq === seq && ( parseInt( $customer.val(), 10 ) || 0 ) === customerId;
		}
		function showFailure() {
			pending = false;
			failed = true;
			$status.empty().append( document.createTextNode( strings.lookup_failed || '會員資料讀取失敗，請重試或確認手動填寫的資料。' ) );
			$( '<button/>', { type: 'button', 'class': 'button', text: strings.retry || '重試' } )
				.on( 'click', loadCustomer ).appendTo( $status );
			$( '<button/>', { type: 'button', 'class': 'button', text: strings.use_manual || '改用手動填寫' } )
				.on( 'click', function () {
					failed = false;
					$status.text( strings.manual_hint || '請確認姓名與電話後再送出。' );
					$name.trigger( 'focus' );
				} ).appendTo( $status );
		}
		function loadCustomer() {
			var customerId = parseInt( $customer.val(), 10 ) || 0;
			var mySeq = ++seq;
			// 自動資料立即清除，管理者手動改過的欄位保留。
			fill( $name, 'name', '' );
			fill( $phone, 'phone', '' );
			pending = !! customerId;
			failed = false;
			$status.empty();
			if ( ! customerId ) {
				return;
			}
			$status.text( strings.loading || '讀取會員資料中…' );
			$.ajax( {
				url: config.ajax_url, method: 'GET', dataType: 'json',
				data: { action: 'uappt_get_customer', nonce: config.nonce, customer_id: customerId }
			} ).done( function ( response ) {
				if ( ! currentRequest( mySeq, customerId ) ) {
					return;
				}
				if ( ! response || ! response.success || ! response.data ) {
					showFailure();
					return;
				}
				pending = false;
				$status.empty();
				fill( $name, 'name', response.data.name );
				fill( $phone, 'phone', response.data.phone );
			} ).fail( function () {
				if ( currentRequest( mySeq, customerId ) ) {
					showFailure();
				}
			} );
		}
		$customer.on( 'change', loadCustomer );
		$customer.closest( 'form' ).on( 'submit', function ( event ) {
			if ( pending || failed ) {
				event.preventDefault();
				$status.trigger( 'focus' );
			}
		} );
	} );
} )( jQuery );
