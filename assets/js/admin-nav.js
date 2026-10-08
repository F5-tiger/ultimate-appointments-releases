/**
 * 手機版的兩層導覽：把「目前所在」的那一個捲進視野。
 *
 * ≤782px 時 admin.css 把區塊導覽與子頁籤都改成單行橫向捲動。純 CSS 做到這裡
 * 就停了：捲動位置永遠從最左邊開始，所以停在靠右的區塊（例如第七個「設定」）
 * 時，進頁面看到的是前三個頁籤，自己在哪反而看不到——導覽的第一個工作就是
 * 回答「我在哪」，那等於整排白做。
 *
 * 刻意不用 scrollIntoView()：它會連同**垂直**方向一起捲（頁面會自己往下跳
 * 一段），而這裡只想動水平位置。直接算 scrollLeft 沒有這個副作用。
 *
 * @package Ultimate_Appointments
 */

( function () {
	'use strict';

	/**
	 * 把 active 置中在可視範圍內（兩端自動夾住，不會捲過頭）。
	 *
	 * @param {Element} strip  可橫向捲動的容器。
	 * @param {Element} active 目前所在的那一個項目。
	 */
	function centerActive( strip, active ) {
		if ( ! strip || ! active ) {
			return;
		}

		// 沒有捲動空間就什麼都不用做（桌面寬度、或項目本來就放得下）。
		if ( strip.scrollWidth <= strip.clientWidth ) {
			return;
		}

		// offsetLeft 是相對於 offsetParent，不見得是 strip 本身；用兩者的
		// 位置差算，才不會受中間隔了幾層定位元素影響。
		var offset = active.getBoundingClientRect().left - strip.getBoundingClientRect().left;
		var target = strip.scrollLeft + offset - ( strip.clientWidth - active.offsetWidth ) / 2;

		strip.scrollLeft = Math.max( 0, Math.min( target, strip.scrollWidth - strip.clientWidth ) );
	}

	function init() {
		var sectionNav = document.querySelector( '.uappt-section-nav' );
		if ( sectionNav ) {
			centerActive( sectionNav, sectionNav.querySelector( '.nav-tab-active' ) );
		}

		var subTabs = document.querySelector( '.uappt-settings-tabs' );
		if ( subTabs ) {
			// 子頁籤的「目前」標在 <a> 上，要捲的是它外面那個 <li>。
			var current = subTabs.querySelector( 'a.current' );
			centerActive( subTabs, current ? current.parentNode : null );
		}
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
