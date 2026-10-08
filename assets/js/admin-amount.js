/**
 * 金額欄位的兩個行為：勾選框展開輸入框（手動建單頁），以及送出 0 元（招待）時
 * 再確認一次（兩頁都有）。
 *
 * 手動建單頁與預約編輯頁共用同一支，但兩邊的標記不完全一樣：
 * - 手動建單頁有 #uappt-amount-toggle（預設走自動計算，勾了才自訂金額）
 * - 預約編輯頁沒有勾選框——那一頁的「金額」本來就是一張獨立的表單，按下
 *   「更新金額」就是明確要改，不需要再多一層開關
 *
 * 所以勾選框是**選配**：找不到就只掛 0 元確認。所屬表單用 closest('form') 找，
 * 不寫死表單 id。
 *
 * 沒有相依 jQuery（原生 DOM API 就夠）。整支沒載入時欄位會直接顯示、表單照樣
 * 送得出去——後端不依賴這支做任何驗證。
 */
( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function () {
		var input = document.getElementById( 'uappt-amount-input' );

		if ( ! input ) {
			return;
		}

		var toggle = document.getElementById( 'uappt-amount-toggle' );
		var field  = document.getElementById( 'uappt-amount-field' );

		if ( toggle && field ) {
			var sync = function () {
				field.hidden = ! toggle.checked;
				// required 要跟著開關走：沒勾的時候留著 required，瀏覽器會擋住
				// 一張根本沒要自訂金額的表單，而且擋在一個看不見的欄位上，使用者
				// 完全不知道是什麼東西沒填。
				input.required = toggle.checked;
			};

			toggle.addEventListener( 'change', sync );
			sync();
		}

		var form = input.closest( 'form' );
		if ( ! form ) {
			return;
		}

		form.addEventListener( 'submit', function ( event ) {
			if ( toggle && ! toggle.checked ) {
				return;
			}

			// 0 元在報表上會被讀成「免費服務」，而且多半是手滑（欄位留空被瀏覽器
			// 當成 0）而不是真的要招待，所以攔一次。
			if ( 0 !== parseFloat( input.value || '0' ) ) {
				return;
			}

			var message = ( window.UAPPT_Admin_Amount && window.UAPPT_Admin_Amount.i18n )
				? window.UAPPT_Admin_Amount.i18n.confirm_zero
				: '';

			if ( message && ! window.confirm( message ) ) {
				event.preventDefault();
			}
		} );
	} );
} )();
