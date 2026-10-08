/**
 * 日曆檢視（日版面）：載入後把畫面捲到「當天最早的上班時間」。
 *
 * 時間軸固定畫滿 00:00–24:00（誰有班、誰沒班用灰底表示），但大多數店家的班表
 * 集中在白天，一進來就停在午夜等於每次都要自己往下捲。這裡讀 PHP 算好的
 * data-scroll-to（距離午夜幾分鐘），乘上實際的每小時高度捲過去。
 *
 * 高度來源刻意是 CSS 變數 --uappt-hour-h 的實際計算值，而不是 JS 裡再寫一個
 * 常數——CSS 改了高度，這裡自動跟著對，不會有兩個地方各寫一份的問題。
 */
( function () {
	'use strict';

	function scrollToBusinessHours() {
		var view = document.querySelector( '.uappt-day' );
		if ( ! view ) {
			return;
		}

		var minutes = parseInt( view.getAttribute( 'data-scroll-to' ), 10 );
		if ( isNaN( minutes ) || minutes <= 0 ) {
			return;
		}

		var hourHeight = parseFloat(
			window.getComputedStyle( view ).getPropertyValue( '--uappt-hour-h' )
		);
		if ( isNaN( hourHeight ) || hourHeight <= 0 ) {
			return;
		}

		view.scrollTop = ( minutes / 60 ) * hourHeight;
	}

	/**
	 * 點欄位空白處直接跳到「手動建立預約」，帶著這位人員與這一天（見
	 * includes/views/calendar-day.php 的 data-quick-book-url）。用事件代理
	 * 綁在整個日曆容器上，而不是每個欄位各自綁一個 listener——欄位數量隨
	 * 篩選條件變動，代理不用管欄位是怎麼來的。
	 *
	 * 點在既有的預約/時段佔用方塊（.uappt-day-block）上要放行，不能被這裡攔截：
	 * 那些本身是連結或純顯示用途，跳轉到快速建單不是使用者點下去時想要的結果。
	 */
	function bindQuickBookClick() {
		var view = document.querySelector( '.uappt-day' );
		if ( ! view ) {
			return;
		}

		view.addEventListener( 'click', function ( event ) {
			if ( event.target.closest( '.uappt-day-block' ) ) {
				return;
			}

			var col = event.target.closest( '.uappt-day-col' );
			if ( ! col ) {
				return;
			}

			var url = col.getAttribute( 'data-quick-book-url' );
			if ( url ) {
				window.location.href = url;
			}
		} );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', scrollToBusinessHours );
		document.addEventListener( 'DOMContentLoaded', bindQuickBookClick );
	} else {
		scrollToBusinessHours();
		bindQuickBookClick();
	}
} )();
