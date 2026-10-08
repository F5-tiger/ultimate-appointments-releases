/**
 * 後台時段選擇器共用邏輯，用在兩個頁面：
 * 1. 「手動建立預約」頁：有 #uappt-mb-product 下拉選單，服務項目由管理員選；
 *    同時有 #uappt-mb-staff 人員下拉選單，換服務項目時要重新查詢候選人員。
 * 2. 「編輯預約」頁的改期區塊：沒有下拉選單，服務項目固定為這筆預約本來的
 *    商品/變化款，改讀 #uappt-mb-fixed-target 這個容器上的 data 屬性；這個頁面
 *    沒有人員選單（改期會自動延續原本的指定/不指定規則，見 class-uappt-booking.php
 *    的 reschedule()），下面的人員相關程式碼在偵測不到 #uappt-mb-staff 時會整段跳過。
 *
 * 服務項目的值格式是 "商品ID" 或 "商品ID:變化款ID"（可變商品的方案）。
 */
( function ( $ ) {
	'use strict';

	if ( typeof UAPPT_Admin_Booking === 'undefined' ) {
		return;
	}

	$( function () {
		var $product = $( '#uappt-mb-product' );
		var $fixedTarget = $( '#uappt-mb-fixed-target' );
		var $date = $( '#uappt-mb-date' );
		var $slot = $( '#uappt-mb-slot' );
		var $staff = $( '#uappt-mb-staff' );
		var $dateInput = $( '#uappt-mb-date-input' );
		var $timeInput = $( '#uappt-mb-time-hm' );

		if ( ! $date.length || ! $slot.length ) {
			return;
		}

		// 從日檢視「點空白處」帶過來的人員 ID（見 calendar-day.php 的
		// data-quick-book-url、UAPPT_Admin::enqueue_assets()）。這裡的商品下拉
		// 選單當下還是空的（日檢視不知道要建立哪個商品的預約），要等客服自己
		// 選了服務項目、loadStaffOptions() 把候選人員名單查回來，才套用得上——
		// 只有真的套用成功才清掉，套用失敗（這個商品的候選名單裡沒有這位人員）
		// 就留著，讓客服換下一個商品時還會再試一次。
		var prefillStaffId = ( UAPPT_Admin_Booking.prefill_staff_id ) ? parseInt( UAPPT_Admin_Booking.prefill_staff_id, 10 ) : 0;

		// 下拉選單的值是 "商品ID" 或 "商品ID:方案鍵"（見 UAPPT_Admin::get_bookable_options()）。
		function parseTarget() {
			if ( $product.length ) {
				var raw = String( $product.val() || '' );
				if ( ! raw ) {
					return { productId: 0, planKey: '' };
				}
				var parts = raw.split( ':' );
				return {
					productId: parseInt( parts[ 0 ], 10 ) || 0,
					planKey: parts.length > 1 ? parts[ 1 ] : '',
				};
			}

			if ( $fixedTarget.length ) {
				return {
					productId: parseInt( $fixedTarget.data( 'product-id' ), 10 ) || 0,
					planKey: String( $fixedTarget.data( 'plan-key' ) || '' ),
				};
			}

			return { productId: 0, planKey: '' };
		}

		function resetSlot( message ) {
			$slot.empty().append( $( '<option/>', { value: '', text: message } ) );
			$dateInput.val( '' );
			$timeInput.val( '' );
		}

		function getStaffId() {
			return $staff.length ? ( parseInt( $staff.val(), 10 ) || 0 ) : 0;
		}

		function formatStaffOptionLabel( option ) {
			var label = option.name;
			if ( option.price_adjustment ) {
				label += ' (+' + option.price_adjustment + ')';
			}
			return label;
		}

		/**
		 * 換服務項目時重新查詢候選人員清單。手動建立預約沒有「上次為您服務」這個
		 * 概念（那是給客人回頭客用的），單純列出候選人員供客服直接指定即可。
		 *
		 * @param {Function} callback 查完後要執行的動作（通常是接著查時段）。
		 */
		function loadStaffOptions( callback ) {
			if ( ! $staff.length ) {
				if ( callback ) {
					callback();
				}
				return;
			}

			var target = parseTarget();
			var currentValue = $staff.val();

			if ( ! target.productId ) {
				$staff.empty().append( $( '<option/>', { value: '0', text: UAPPT_Admin_Booking.i18n.staff_any } ) );
				if ( callback ) {
					callback();
				}
				return;
			}

			$.ajax( {
				url: UAPPT_Admin_Booking.ajax_url,
				method: 'GET',
				dataType: 'json',
				data: {
					action: 'uappt_get_staff_options',
					nonce: UAPPT_Admin_Booking.nonce,
					product_id: target.productId,
					plan_key: target.planKey,
				},
			} )
				.done( function ( response ) {
					$staff.empty().append( $( '<option/>', { value: '0', text: UAPPT_Admin_Booking.i18n.staff_any } ) );

					var list = ( response && response.success && response.data.staff ) ? response.data.staff : [];
					list.forEach( function ( option ) {
						$staff.append(
							$( '<option/>', { value: option.id, text: formatStaffOptionLabel( option ) } )
						);
					} );

					if ( prefillStaffId && list.some( function ( o ) { return o.id === prefillStaffId; } ) ) {
						$staff.val( String( prefillStaffId ) );
						prefillStaffId = 0; // 套用成功，之後換服務項目不用再蓋過客服自己的選擇。
					} else if ( list.some( function ( o ) { return String( o.id ) === currentValue; } ) ) {
						// 換方案後，如果先前選的人員在新方案裡還在候選名單中就保留選擇。
						$staff.val( currentValue );
					}
				} )
				.always( function () {
					if ( callback ) {
						callback();
					}
				} );
		}

		function loadSlots() {
			var target = parseTarget();
			var date = $date.val();

			if ( ! target.productId || ! date ) {
				resetSlot( UAPPT_Admin_Booking.i18n.select_date );
				return;
			}

			resetSlot( UAPPT_Admin_Booking.i18n.loading );

			$.ajax( {
				url: UAPPT_Admin_Booking.ajax_url,
				method: 'GET',
				dataType: 'json',
				data: {
					action: 'uappt_get_slots',
					nonce: UAPPT_Admin_Booking.nonce,
					product_id: target.productId,
					plan_key: target.planKey,
					staff_id: getStaffId(),
					date: date,
				},
			} )
				.done( function ( response ) {
					if ( ! response || ! response.success || ! response.data.slots || ! response.data.slots.length ) {
						resetSlot( UAPPT_Admin_Booking.i18n.no_slots );
						return;
					}

					$slot.empty();
					response.data.slots.forEach( function ( s ) {
						$slot.append(
							$( '<option/>', {
								value: s.start,
								text: s.start + ' – ' + s.end,
							} )
						);
					} );

					$dateInput.val( date );
					$timeInput.val( $slot.val() );
				} )
				.fail( function () {
					resetSlot( UAPPT_Admin_Booking.i18n.error );
				} );
		}

		if ( $product.length ) {
			$product.on( 'change', function () {
				loadStaffOptions( loadSlots );
			} );
		}
		$date.on( 'change', loadSlots );
		$staff.on( 'change', loadSlots );
		$slot.on( 'change', function () {
			$timeInput.val( $slot.val() );
		} );

		loadStaffOptions();
	} );
} )( jQuery );
