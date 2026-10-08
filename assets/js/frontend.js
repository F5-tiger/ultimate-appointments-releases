/**
 * 商品頁預約時段選擇器。
 *
 * 流程：（有多個方案時先選方案）-> 選服務人員 -> 選日期 -> AJAX 查詢可預約時段
 * -> （若有多個分類先選上午/下午等頁籤）-> 選時段 -> 才能加入購物車。分類頁籤與
 * 剩餘名額都是後端算好、直接附在 AJAX 回應裡的（見 class-uappt-ajax.php），
 * 前端只負責呈現，不重算邏輯。
 *
 * v2.1.0 起方案是本外掛自己的資料（不再是 WooCommerce 變化款），所以不必再監聽
 * found_variation / reset_data 那一整套事件，換方案就是一個單純的 select change。
 *
 * 實際的名額鎖定/超賣防護完全在後端（class-uappt-cart.php + class-uappt-booking.php）
 * 的交易鎖定完成，這裡的前端邏輯只是體驗層，不能被信任為唯一防線。
 */
( function ( $ ) {
	'use strict';

	if ( typeof UAPPT_Frontend === 'undefined' ) {
		return;
	}

	function initWidget( $widget ) {
		var productId = $widget.data( 'product-id' );
		var hasPlans = String( $widget.data( 'has-plans' ) ) === '1';
		var $form = $widget.closest( 'form.cart' );
		var $qty = $form.find( '.quantity' );
		var $planInput = $widget.find( '.uappt-plan-input' );
		var $planSelect = $widget.find( '.uappt-plan-select' );
		var $staffSelect = $widget.find( '.uappt-staff-select' );
		var $dateInput = $widget.find( '.uappt-date-input' );
		var $slotsList = $widget.find( '.uappt-slots-list' );
		var $hiddenDate = $widget.find( '.uappt-input-date' );
		var $hiddenTime = $widget.find( '.uappt-input-time' );
		var $summary = $widget.find( '.uappt-selected-summary' );
		var $participantsInput = $widget.find( '.uappt-participants-input' );
		var $participantsHint = $widget.find( '.uappt-field-participants .uappt-field-hint' );
		// 商品層級的人數上限（後台「每張訂單人數上限」），跟每個時段自己的
		// max_group_size（單一候選人員的剩餘名額）取較小值才是真正能填的上限。
		// 沒有商品層級上限就是 Infinity，等於完全由時段自己的名額決定。
		var productGroupMax = $participantsInput.attr( 'max' ) ? parseInt( $participantsInput.attr( 'max' ), 10 ) : Infinity;

		var lastResponse = null;
		var activeSegment = null;
		var selectedStart = null;

		$qty.hide();

		function getSubmit() {
			return $form.find( '.single_add_to_cart_button' );
		}

		function getPlanKey() {
			return $planInput.length ? String( $planInput.val() || '' ) : '';
		}

		function getStaffId() {
			// 店家關閉人員選擇器時前台不輸出這個 select，一律當作「不指定」。
			return $staffSelect.length ? parseInt( $staffSelect.val(), 10 ) || 0 : 0;
		}

		// 換方案時同步商品頁上顯示的價格。找不到價格容器（佈景主題自製模板）就
		// 安靜略過——這只是體驗，實際金額由後端算。
		function syncPriceDisplay() {
			if ( ! $planSelect.length ) {
				return;
			}
			var priceHtml = $planSelect.find( 'option:selected' ).data( 'price-html' );
			if ( ! priceHtml ) {
				return;
			}
			$( '.summary .price, .product .price' ).first().html( priceHtml );
		}

		function formatStaffLabel( option ) {
			var label = option.name;
			if ( option.is_last ) {
				label += UAPPT_Frontend.i18n.staff_last_suffix;
			}
			if ( option.price_adjustment ) {
				label += ' (+' + option.price_adjustment + ')';
			}
			return label;
		}

		function renderStaffOptions( list ) {
			var currentValue = $staffSelect.val();
			$staffSelect.empty();
			$staffSelect.append( $( '<option/>', { value: '0', text: UAPPT_Frontend.i18n.staff_any } ) );

			var toSelect = '0';
			list.forEach( function ( option ) {
				$staffSelect.append(
					$( '<option/>', { value: option.id, text: formatStaffLabel( option ) } )
				);
				if ( option.is_last ) {
					toSelect = String( option.id );
				}
			} );

			// 換方案後，如果客人先前選的那位人員在新方案裡還在候選名單中就保留選擇，
			// 否則才退回「上次為您服務」或「不指定」。
			if ( list.some( function ( o ) { return String( o.id ) === currentValue; } ) ) {
				toSelect = currentValue;
			}
			$staffSelect.val( toSelect );
		}

		function refreshStaffOptions( callback ) {
			if ( ! $staffSelect.length ) {
				if ( callback ) {
					callback();
				}
				return;
			}

			$.ajax( {
				url: UAPPT_Frontend.ajax_url,
				method: 'GET',
				dataType: 'json',
				data: {
					action: 'uappt_get_staff_options',
					nonce: UAPPT_Frontend.nonce,
					product_id: productId,
					plan_key: getPlanKey(),
				},
			} )
				.done( function ( response ) {
					if ( response && response.success ) {
						renderStaffOptions( response.data.staff || [] );
					}
				} )
				.always( function () {
					if ( callback ) {
						callback();
					}
				} );
		}

		function hasSelection() {
			return !! ( $hiddenDate.val() && $hiddenTime.val() );
		}

		function syncSubmitState() {
			var $submit = getSubmit();
			if ( hasSelection() ) {
				$submit.prop( 'disabled', false ).removeClass( 'uappt-disabled' );
			} else {
				$submit.prop( 'disabled', true ).addClass( 'uappt-disabled' );
			}
		}

		function resetSelection() {
			$hiddenDate.val( '' );
			$hiddenTime.val( '' );
			selectedStart = null;
			$summary.attr( 'hidden', true ).text( '' );
			// 換日期/方案/人員等於選定的時段失效了：人數欄位重新鎖回停用狀態，
			// 逼客人重新選一個時段才會知道新的上限——不能只是把 max 屬性退回
			// 商品層級上限就算了事，那個數字一樣是猜的（見欄位下方的說明文字、
			// CLAUDE.md「團體預約」一節）。
			if ( $participantsInput.length ) {
				$participantsInput
					.prop( 'disabled', true )
					.val( 1 )
					.attr( 'max', isFinite( productGroupMax ) ? productGroupMax : '' );
				$participantsHint.text( UAPPT_Frontend.i18n.participants_hint_before );
			}
			syncSubmitState();
		}

		function renderMessage( text ) {
			$slotsList.empty().append( $( '<p/>', { class: 'uappt-slots-placeholder', text: text } ) );
		}

		function formatRemainingText( remaining ) {
			return UAPPT_Frontend.i18n.remaining_template.replace( '%d', remaining );
		}

		function renderSlotButtons( slots, showRemaining ) {
			var $list = $( '<div/>', { class: 'uappt-slot-buttons' } );

			slots.forEach( function ( slot ) {
				var $btn = $( '<button/>', {
					type: 'button',
					class: 'uappt-slot-btn' + ( slot.start === selectedStart ? ' is-selected' : '' ),
					'data-start': slot.start,
					'data-end': slot.end,
					'data-max-group-size': slot.max_group_size || 1,
				} );

				$btn.append( $( '<span/>', { class: 'uappt-slot-time', text: slot.start + ' – ' + slot.end } ) );

				if ( showRemaining && slot.remaining ) {
					$btn.append(
						$( '<span/>', { class: 'uappt-slot-remaining', text: formatRemainingText( slot.remaining ) } )
					);
				}

				$list.append( $btn );
			} );

			return $list;
		}

		function renderSegmentTabs( segments ) {
			var $tabs = $( '<div/>', { class: 'uappt-segment-tabs' } );

			segments.forEach( function ( segment ) {
				var isActive = segment.key === activeSegment;
				$tabs.append(
					$( '<button/>', {
						type: 'button',
						class: 'uappt-segment-tab' + ( isActive ? ' is-active' : '' ),
						'data-segment': segment.key,
						text: segment.name + '（' + segment.count + '）',
					} )
				);
			} );

			return $tabs;
		}

		function renderNextAvailable( nextAvailable ) {
			var $p = $( '<p/>', { class: 'uappt-next-available' } );
			$p.append( document.createTextNode( UAPPT_Frontend.i18n.full_prefix ) );
			$p.append(
				$( '<button/>', {
					type: 'button',
					class: 'uappt-next-available-link',
					text: nextAvailable,
				} ).on( 'click', function () {
					$dateInput.val( nextAvailable );
					loadSlots();
				} )
			);
			return $p;
		}

		function renderResponse() {
			$slotsList.empty();

			if ( ! lastResponse ) {
				return;
			}

			var slots = lastResponse.slots || [];
			var segments = lastResponse.segments || [];

			if ( ! slots.length ) {
				renderMessage( UAPPT_Frontend.i18n.no_slots );
				if ( lastResponse.next_available ) {
					$slotsList.append( renderNextAvailable( lastResponse.next_available ) );
				}
				return;
			}

			if ( segments.length > 1 ) {
				var stillValid = segments.some( function ( s ) {
					return s.key === activeSegment;
				} );
				if ( ! activeSegment || ! stillValid ) {
					activeSegment = segments[ 0 ].key;
				}
				$slotsList.append( renderSegmentTabs( segments ) );
			} else {
				activeSegment = segments.length ? segments[ 0 ].key : null;
			}

			var filtered = activeSegment
				? slots.filter( function ( s ) {
						return s.segment === activeSegment;
				  } )
				: slots;

			$slotsList.append( renderSlotButtons( filtered, !! lastResponse.show_remaining ) );
		}

		function initialMessage() {
			if ( hasPlans && ! getPlanKey() ) {
				return UAPPT_Frontend.i18n.select_plan;
			}
			return UAPPT_Frontend.i18n.select_date;
		}

		function loadSlots() {
			resetSelection();
			lastResponse = null;
			activeSegment = null;

			var date = $dateInput.val();
			var planKey = getPlanKey();

			if ( hasPlans && ! planKey ) {
				renderMessage( UAPPT_Frontend.i18n.select_plan );
				return;
			}

			if ( ! date ) {
				renderMessage( UAPPT_Frontend.i18n.select_date );
				return;
			}

			renderMessage( UAPPT_Frontend.i18n.loading );

			$.ajax( {
				url: UAPPT_Frontend.ajax_url,
				method: 'GET',
				dataType: 'json',
				data: {
					action: 'uappt_get_slots',
					nonce: UAPPT_Frontend.nonce,
					product_id: productId,
					plan_key: planKey,
					staff_id: getStaffId(),
					date: date,
				},
			} )
				.done( function ( response ) {
					if ( response && response.success ) {
						lastResponse = response.data;
						renderResponse();
					} else {
						lastResponse = null;
						renderMessage(
							( response && response.data && response.data.message ) || UAPPT_Frontend.i18n.error
						);
					}
				} )
				.fail( function () {
					lastResponse = null;
					renderMessage( UAPPT_Frontend.i18n.error );
				} );
		}

		$dateInput.on( 'change', loadSlots );
		$staffSelect.on( 'change', loadSlots );

		$slotsList.on( 'click', '.uappt-segment-tab', function () {
			activeSegment = $( this ).data( 'segment' );
			renderResponse();
		} );

		$slotsList.on( 'click', '.uappt-slot-btn', function () {
			var $btn = $( this );

			selectedStart = $btn.data( 'start' );
			$slotsList.find( '.uappt-slot-btn' ).removeClass( 'is-selected' );
			$btn.addClass( 'is-selected' );

			$hiddenDate.val( $dateInput.val() );
			$hiddenTime.val( $btn.data( 'start' ) );

			$summary
				.removeAttr( 'hidden' )
				.text( $dateInput.val() + '  ' + $btn.data( 'start' ) + ' – ' + $btn.data( 'end' ) );

			// 選定時段後才知道這個時段實際能容納幾人（單一候選人員的剩餘名額），
			// 跟商品層級的人數上限取較小值——真正的把關仍在後端 create_hold()，
			// 這裡只是不讓客人在畫面上填一個注定會被拒絕的數字。欄位到這一刻
			// 才解鎖：選時段之前完全不知道真正的上限，寧可不給也不要給錯的。
			if ( $participantsInput.length ) {
				var slotMax = parseInt( $btn.data( 'max-group-size' ), 10 ) || 1;
				var effectiveMax = Math.min( slotMax, productGroupMax );
				$participantsInput
					.prop( 'disabled', false )
					.attr( 'max', effectiveMax );
				if ( parseInt( $participantsInput.val(), 10 ) > effectiveMax ) {
					$participantsInput.val( effectiveMax );
				}
				$participantsHint.text( UAPPT_Frontend.i18n.participants_hint_after.replace( '%d', effectiveMax ) );
			}

			syncSubmitState();
		} );

		// 換方案時可服務人員與時長/緩衝都可能不同，要先重新整理人員清單再查時段。
		$planSelect.on( 'change', function () {
			syncPriceDisplay();

			if ( ! getPlanKey() ) {
				resetSelection();
				lastResponse = null;
				renderMessage( UAPPT_Frontend.i18n.select_plan );
				return;
			}

			refreshStaffOptions( loadSlots );
		} );

		renderMessage( initialMessage() );
		syncSubmitState();
	}

	$( function () {
		$( '.uappt-booking-widget' ).each( function () {
			initWidget( $( this ) );
		} );
	} );
} )( jQuery );
