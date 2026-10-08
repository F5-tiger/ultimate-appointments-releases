/**
 * 商品編輯頁的「預約設定」分頁：服務方案的可重複列（新增／刪除／排序／進階展開）。
 *
 * v2.1.0 起不再有變化款相關的欄位切換，也不再有「需要預約時段」勾選框
 * ——商品類型本身就是開關，這個分頁只在「預約商品」出現。
 *
 * v2.1.2：加入沿用值即時顯示、進階展開層、拖曳排序、送出前的名稱驗證。
 */
( function ( $ ) {
	'use strict';

	var i18n = window.UAPPT_AdminProduct || {};

	function $rows() {
		return $( '.uappt-plans-rows' );
	}

	/**
	 * 目前編輯中的商品是不是「預約商品」。
	 *
	 * ⚠️ **這支 JS 在每一個商品編輯頁都會載入**（商品類型可以在不重新整理的
	 * 情況下切成預約商品，所以不能只在預約商品時才載），而「預約設定」分頁的
	 * 欄位也是每一種類型都印在 DOM 裡、只是被 WooCommerce 用 CSS 藏起來。
	 *
	 * 也就是說 `#_uappt_staff_ids` 在一個簡單商品的編輯頁上**存在而且是空的**
	 * ——送出前的驗證如果不先問一句「這是不是預約商品」，就會把所有商品的
	 * 「更新」按鈕擋掉：畫面跳到「預約設定」分頁、出現一句「請至少選擇一位
	 * 可服務的人員」，而管理者只是想改一件 T 恤的價格。（實測回報過的症狀：
	 * 「不論哪個商品，點更新都會跳到預約商品的商品資料頁面」，而且存不了檔。）
	 *
	 * @return {boolean}
	 */
	function isBookingProduct() {
		var $type = $( '#product-type' );
		if ( ! $type.length ) {
			// 下拉不在（別的外掛藏了它、或 WooCommerce 改版）時保守處理：
			// 寧可不驗證，也不要擋住存檔。
			return false;
		}
		return $type.val() === ( i18n.product_type || 'uappt_booking' );
	}

	function syncEmptyNotice() {
		$( '.uappt-plans-empty' ).toggle( $rows().find( '.uappt-plan-row' ).length === 0 );
	}

	/**
	 * 讓新加入的 select 變成 select2。
	 *
	 * WooCommerce 只在頁面載入時初始化一次 .wc-enhanced-select，動態插入的列
	 * 不會自動套用，要自己丟這個事件出去（wc-enhanced-select.js 有監聽它）。
	 */
	function initEnhancedSelect( $context ) {
		$context.find( '.wc-enhanced-select' ).each( function () {
			// 範本列複製過來時可能帶著上一輪 select2 的殘留 class，先清掉再初始化。
			$( this ).removeClass( 'enhanced' ).next( '.select2-container' ).remove();
		} );
		$( document.body ).trigger( 'wc-enhanced-select-init' );
	}

	function addPlanRow() {
		var template = $( '#uappt-plan-row-template' ).html();
		if ( ! template ) {
			return;
		}

		// 索引只要在這一次送出的表單裡不重複就好；方案真正的識別是 key，
		// 由 PHP 端在儲存時產生（見 UAPPT_Product::sanitize_plans_from_post()）。
		var index = 'new' + Date.now() + Math.floor( Math.random() * 1000 );

		// 方案改成卡片（<div>）之後就不需要舊版那個「先塞進暫時的 table 再把 <tr>
		// 取出來」的技巧了——那是因為 HTML 剖析器會丟掉不在 table 裡的裸 <tr>，
		// <div> 沒有這個限制，直接解析就好。
		var $row = $( template.replace( /__INDEX__/g, index ) ).first();

		$rows().append( $row );
		initEnhancedSelect( $row );
		syncEmptyNotice();
		$row.find( '.uappt-plan-name' ).trigger( 'focus' );
	}

	function removePlanRow( $button ) {
		var $row = $button.closest( '.uappt-plan-row' );
		var hasKey = $row.find( 'input[name$="[key]"]' ).val();

		// 只有「已經存過、可能已被拿來建立預約」的方案才需要確認；剛按新增
		// 還沒填的空白列直接刪掉就好，每次都跳確認很煩人。
		if ( hasKey && i18n.confirm_remove_plan && ! window.confirm( i18n.confirm_remove_plan ) ) {
			return;
		}

		$row.remove();
		syncEmptyNotice();
	}

	/**
	 * 切換單一列的「進階」展開層（前／後緩衝、暫停銷售此方案）。
	 */
	function toggleAdvanced( $button ) {
		var $panel = $button.closest( '.uappt-plan-row' ).find( '.uappt-plan-advanced' );
		var expanded = $panel.is( ':visible' );
		$panel.toggle( ! expanded );
		$button.attr( 'aria-expanded', expanded ? 'false' : 'true' );
	}

	/**
	 * 停用開關切換時，同步整列的「已停用」視覺標記——不用等存檔重新整理頁面
	 * 才看得出這一列會被停售。
	 */
	function syncInactiveState( $checkbox ) {
		var $row = $checkbox.closest( '.uappt-plan-row' );
		var inactive = $checkbox.is( ':checked' );
		$row.toggleClass( 'is-inactive', inactive );
		$row.find( '.uappt-plan-inactive-badge' ).toggle( inactive );
		refreshAdvancedDot( $row );
	}

	/**
	 * 「進階」按鈕右上角的紅點：只要前／後緩衝有值、或方案已停用，就代表這個展開層
	 * 裡藏著會實際生效的設定，不能讓管理者以為收起來的欄位是空的。
	 */
	function refreshAdvancedDot( $row ) {
		var hasValues =
			$.trim( $row.find( '[data-field="buffer_before"]' ).val() ) !== '' ||
			$.trim( $row.find( '[data-field="buffer_after"]' ).val() ) !== '' ||
			$row.find( '.uappt-plan-inactive' ).is( ':checked' );
		$row.find( '.uappt-toggle-advanced' ).toggleClass( 'has-values', hasValues );
	}

	/**
	 * 商品層級的「服務預設值」一改，所有方案列（含之後才新增的）的 placeholder
	 * 要立刻反映新的沿用值，不然「沿用 60」在管理者改成 90 之後還是舊的，等於說謊。
	 */
	function syncInheritPlaceholders( field, value ) {
		var text = ( i18n.inherit_prefix || '' ) + ' ' + value;
		$( '.uappt-inherit-input[data-field="' + field + '"]' )
			.attr( 'data-inherit', value )
			.attr( 'placeholder', text );

		// 範本列（新增方案用）也要跟著換，否則新增的列又會退回舊的沿用值。
		var $template = $( '#uappt-plan-row-template' );
		if ( $template.length ) {
			var html = $template.html();
			var re = new RegExp( '(data-field="' + field + '"[^>]*data-inherit=")[^"]*(")', 'g' );
			html = html.replace( re, '$1' + value + '$2' );
			var re2 = new RegExp( '(data-field="' + field + '"[^>]*placeholder=")[^"]*(")', 'g' );
			html = html.replace( re2, '$1' + text.replace( /"/g, '&quot;' ) + '$2' );
			$template.html( html );
		}
	}

	/**
	 * 送出前檢查：有列填了內容卻沒有名稱，擋下送出、切到分頁、聚焦第一個問題列。
	 *
	 * 不能靠 HTML required——名稱欄位活在一個可能沒被選取的分頁裡，未選取的分頁
	 * 由 WooCommerce 的 JS 蓋上 style="display:none"，瀏覽器對隱藏欄位做原生驗證會
	 * 直接無聲擋下整個表單送出（Chrome/Firefox 對 `An invalid form control is not
	 * focusable` 的處理方式不一致，有的完全沒有任何提示），管理者只會覺得「按更新
	 * 沒反應」。所以驗證與聚焦都得自己動手做。
	 */
	function hasContent( $row ) {
		var filled = false;
		$row.find( 'input[type="number"], input.wc_input_price' ).each( function () {
			if ( $.trim( $( this ).val() ) !== '' ) {
				filled = true;
			}
		} );
		if ( $row.find( '.uappt-plan-staff option:selected' ).length ) {
			filled = true;
		}
		if ( $row.find( '.uappt-plan-inactive' ).is( ':checked' ) ) {
			filled = true;
		}
		return filled;
	}

	/**
	 * 「可服務的人員」是必填欄位（見 class-uappt-product.php 的 get_booking_settings()
	 * 說明）：留空不是「不限定」，是「沒人能做」，整項服務在前台就完全買不到。
	 * 跟方案名稱一樣不能靠 HTML required——欄位在未選取的分頁裡是隱藏的。
	 *
	 * @return boolean 是否已擋下送出（擋下時呼叫端不需要再往下驗證）。
	 */
	function validateStaffRequired() {
		if ( ! isBookingProduct() ) {
			return false;
		}

		var $staffSelect = $( '#_uappt_staff_ids' );
		if ( ! $staffSelect.length || ( $staffSelect.val() && $staffSelect.val().length ) ) {
			return false;
		}

		$( '.wc-tabs a[href="#uappt_booking_product_data"]' ).trigger( 'click' );

		var $wrap = $staffSelect.closest( '.form-field' );
		$wrap.find( '.uappt-staff-required-error' ).remove();
		$( '<p class="uappt-staff-required-error" style="color:#d63638;margin:4px 0 0;"></p>' )
			.text( i18n.staff_required || '' )
			.appendTo( $wrap );

		$wrap.get( 0 ).scrollIntoView( { block: 'center' } );

		// select2 把原生 <select> 藏起來另外畫一個假的選取框，對隱藏元素呼叫
		// focus() 使用者看不出反應，要點在那個假選取框上才看得出聚焦到哪裡。
		var $select2 = $staffSelect.next( '.select2-container' ).find( '.select2-selection' );
		( $select2.length ? $select2 : $staffSelect ).trigger( 'focus' );

		return true;
	}

	function validateBeforeSubmit( e ) {
		// 只有預約商品才跑底下的驗證，理由見 isBookingProduct()。
		if ( ! isBookingProduct() ) {
			return;
		}

		if ( validateStaffRequired() ) {
			e.preventDefault();
			return;
		}

		var $offender = null;

		$rows()
			.find( '.uappt-plan-row' )
			.each( function () {
				var $row = $( this );
				var $name = $row.find( '.uappt-plan-name' );
				if ( $.trim( $name.val() ) !== '' ) {
					return;
				}
				if ( hasContent( $row ) ) {
					$offender = $row;
					return false;
				}
			} );

		if ( ! $offender ) {
			return;
		}

		e.preventDefault();

		// 切到「預約設定」分頁——如果管理者是在別的分頁按下更新的。
		$( '.wc-tabs a[href="#uappt_booking_product_data"]' ).trigger( 'click' );

		var $panel = $offender.find( '.uappt-plan-advanced' );
		if ( ! $panel.is( ':visible' ) ) {
			$panel.show();
			$offender.find( '.uappt-toggle-advanced' ).attr( 'aria-expanded', 'true' );
		}

		var $name = $offender.find( '.uappt-plan-name' );
		$name.addClass( 'uappt-field-error' );
		if ( ! $offender.find( '.uappt-plan-name-error' ).length ) {
			$( '<span class="uappt-plan-name-error" style="color:#d63638;display:block;font-size:12px;margin-top:2px;"></span>' )
				.text( i18n.name_required || '' )
				.insertAfter( $name );
		}

		$offender.get( 0 ).scrollIntoView( { block: 'center' } );
		$name.trigger( 'focus' );
	}

	/**
	 * 狀態列裡「前往設定可服務的人員」「前往解除暫停」這類連結：把畫面捲到對應欄位
	 * 並閃一下邊框，而不是只換分頁讓管理者自己在一長串欄位裡找。
	 */
	function focusField( key ) {
		var $target = $( '#' + key );
		if ( ! $target.length ) {
			return;
		}
		var $scrollTo = $target.closest( '.form-field, .options_group' );
		( $scrollTo.length ? $scrollTo : $target ).get( 0 ).scrollIntoView( { block: 'center' } );

		// select2 把原生 <select> 藏起來、另外畫一個假的選取框，對隱藏元素呼叫
		// focus() 使用者完全看不出反應；要聚焦就得點在那個假選取框上。
		var $select2 = $target.next( '.select2-container' ).find( '.select2-selection' );
		if ( $select2.length ) {
			$select2.trigger( 'focus' );
			return;
		}
		$target.trigger( 'focus' );
	}

	$( function () {
		$( document ).on( 'click', '.uappt-add-plan', function ( e ) {
			e.preventDefault();
			addPlanRow();
		} );

		$( document ).on( 'click', '.uappt-remove-plan', function ( e ) {
			e.preventDefault();
			removePlanRow( $( this ) );
		} );

		$( document ).on( 'click', '.uappt-toggle-advanced', function ( e ) {
			e.preventDefault();
			toggleAdvanced( $( this ) );
		} );

		$( document ).on( 'change', '.uappt-plan-inactive', function () {
			syncInactiveState( $( this ) );
		} );

		$( document ).on( 'input', '[data-field="buffer_before"], [data-field="buffer_after"]', function () {
			refreshAdvancedDot( $( this ).closest( '.uappt-plan-row' ) );
		} );

		$( document ).on( 'input', '#_uappt_duration_minutes', function () {
			var v = parseInt( $( this ).val(), 10 );
			syncInheritPlaceholders( 'duration', v > 0 ? v : 60 );
		} );
		$( document ).on( 'input', '#_uappt_buffer_before', function () {
			var v = parseInt( $( this ).val(), 10 );
			syncInheritPlaceholders( 'buffer_before', isNaN( v ) ? 0 : Math.max( 0, v ) );
		} );
		$( document ).on( 'input', '#_uappt_buffer_after', function () {
			var v = parseInt( $( this ).val(), 10 );
			syncInheritPlaceholders( 'buffer_after', isNaN( v ) ? 0 : Math.max( 0, v ) );
		} );

		$( document ).on( 'input', '.uappt-plan-name', function () {
			$( this ).removeClass( 'uappt-field-error' );
			$( this ).next( '.uappt-plan-name-error' ).remove();
		} );

		$( document ).on( 'change', '#_uappt_staff_ids', function () {
			$( this ).closest( '.form-field' ).find( '.uappt-staff-required-error' ).remove();
		} );

		$( document ).on( 'click', '.uappt-focus-field', function ( e ) {
			e.preventDefault();
			focusField( $( this ).data( 'target' ) );
		} );

		$( '#post' ).on( 'submit', validateBeforeSubmit );

		// 拖曳排序：順序由送出時的 DOM 順序決定，PHP 端本來就是照 $_POST 出現
		// 順序建陣列（見 sanitize_plans_from_post()），不需要額外的排序欄位。
		//
		// 卡片化之後這裡簡單很多：舊版要用自訂 helper 把每個 <td> 當下的寬度寫成
		// inline style 鎖住，因為 <tr> 被拖起來就脫離 table 的版面流程、子層會塌
		// 成一團（table row sortable 的經典坑）。<div> 卡片本來就是獨立的區塊，
		// 拖起來寬高都不會變，那段 hack 可以整個拿掉。
		if ( $.fn.sortable ) {
			$rows().sortable( {
				items: '.uappt-plan-row',
				handle: '.uappt-plan-handle',
				axis: 'y',
				forcePlaceholderSize: true,
				placeholder: 'uappt-plan-row-placeholder',
			} );
		}

		syncEmptyNotice();
		$rows()
			.find( '.uappt-plan-row' )
			.each( function () {
				refreshAdvancedDot( $( this ) );
			} );
	} );
} )( jQuery );
