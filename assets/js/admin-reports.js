/**
 * 報表「人員」頁的「全部展開／收合」。
 *
 * 人員 × 項目的明細預設是收合的（人一多時全部展開整頁會爆炸），但「我想一次
 * 看完所有人在做什麼」也是真實需求，所以留一顆按鈕。
 *
 * 按鈕的文字由 data 屬性提供，不寫在 JS 裡——翻譯留在 PHP 那邊，這支不需要
 * 另外 localize。
 *
 * 沒有相依 jQuery。這支沒載入時明細照樣可以一個一個點開，只是少了批次操作。
 */
( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function () {
		var button = document.getElementById( 'uappt-toggle-staff-services' );
		if ( ! button ) {
			return;
		}

		var panels = document.querySelectorAll( '.uappt-staff-services' );
		if ( ! panels.length ) {
			button.hidden = true;
			return;
		}

		function sync() {
			// 「還有任何一個沒展開」就顯示「全部展開」——以使用者接下來想做的
			// 動作命名按鈕，不是以目前狀態命名。
			var anyClosed = false;
			Array.prototype.forEach.call( panels, function ( panel ) {
				if ( ! panel.open ) {
					anyClosed = true;
				}
			} );

			button.textContent = anyClosed ? button.dataset.expand : button.dataset.collapse;
			return anyClosed;
		}

		button.addEventListener( 'click', function () {
			var shouldOpen = sync();
			Array.prototype.forEach.call( panels, function ( panel ) {
				panel.open = shouldOpen;
			} );
			sync();
		} );

		// 使用者自己點開／關掉個別區塊時，按鈕文字要跟著對。
		Array.prototype.forEach.call( panels, function ( panel ) {
			panel.addEventListener( 'toggle', sync );
		} );

		sync();
	} );
} )();
