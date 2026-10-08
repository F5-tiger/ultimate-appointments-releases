/**
 * 設定頁的頁籤切換。
 *
 * 頁籤列本身是一排真的 <a> 連結（href 帶 ?tab=），目前在哪一頁由 PHP 決定。
 * 這支 JS 只是把「整頁重新載入」換成「就地切換」——JS 掛掉時頁籤仍然能用，
 * 只是每次切換會重新整理一次。
 *
 * ⚠️ 四個頁籤的欄位一律留在 DOM 裡，切換只改 class。不能改成「只載入目前這一
 * 頁的欄位」：UAPPT_Admin::handle_save_settings() 是把每個 option 都無條件寫入、
 * 欄位沒送上來就寫預設值，少送一個欄位＝按下儲存就把那個設定悄悄重設掉。
 */
( function () {
	'use strict';

	var nav = document.querySelector( '.uappt-settings-tabs' );

	if ( ! nav ) {
		return;
	}

	var links  = nav.querySelectorAll( '[data-uappt-tab-link]' );
	var panes  = document.querySelectorAll( '[data-uappt-tab]' );
	// 每個表單裡的隱藏欄位，讓儲存後的轉址回得到原本那個頁籤。
	var fields = document.querySelectorAll( '.uappt-tab-field' );

	/**
	 * 一個面板可能同時屬於多個頁籤（例如「儲存設定」按鈕）。
	 *
	 * @param {Element} pane 面板。
	 * @return {Array} 頁籤識別值清單。
	 */
	function ownersOf( pane ) {
		return ( pane.getAttribute( 'data-uappt-tab' ) || '' ).split( /\s+/ );
	}

	/**
	 * 切換到某個頁籤。
	 *
	 * @param {string} tab 頁籤識別值。
	 */
	function show( tab ) {
		var i;

		for ( i = 0; i < panes.length; i++ ) {
			if ( ownersOf( panes[ i ] ).indexOf( tab ) !== -1 ) {
				panes[ i ].classList.add( 'is-active' );
			} else {
				panes[ i ].classList.remove( 'is-active' );
			}
		}

		for ( i = 0; i < links.length; i++ ) {
			if ( links[ i ].getAttribute( 'data-uappt-tab-link' ) === tab ) {
				links[ i ].classList.add( 'nav-tab-active' );
				// aria-current 要跟著改，不然螢幕閱讀器會一直報「目前在第一個
				// 頁籤」——切換沒有重新載入頁面，PHP 印出來的那個屬性不會更新。
				links[ i ].setAttribute( 'aria-current', 'page' );
			} else {
				links[ i ].classList.remove( 'nav-tab-active' );
				links[ i ].removeAttribute( 'aria-current' );
			}
		}

		for ( i = 0; i < fields.length; i++ ) {
			fields[ i ].value = tab;
		}
	}

	nav.addEventListener( 'click', function ( e ) {
		var link = e.target.closest ? e.target.closest( '[data-uappt-tab-link]' ) : null;

		if ( ! link ) {
			return;
		}

		e.preventDefault();
		show( link.getAttribute( 'data-uappt-tab-link' ) );

		// 用 replaceState 而不是 pushState：pushState 會讓「上一頁」變成「回到
		// 上一個頁籤」，但那需要再掛一個 popstate handler 才不會只有網址變、
		// 畫面沒變。頁籤不是瀏覽歷史，網址只要保持「重新整理會回到同一頁」即可。
		if ( window.history && window.history.replaceState ) {
			window.history.replaceState( null, '', link.href );
		}
	} );

	/**
	 * 送出時若有欄位沒通過瀏覽器驗證、而且它在被藏起來的頁籤上，先切過去。
	 *
	 * 不處理的話瀏覽器會因為「無效欄位無法對焦」而直接放棄送出、畫面完全沒有
	 * 任何反應——看起來就像儲存鈕壞了。設定頁有好幾個 <input type="number">
	 * 帶 min/step，很容易踩到（「時間格顆粒」是 step=5，填 17 就是無效值）。
	 *
	 * ⚠️ 一定要聽 invalid，不能聽 submit：驗證沒過時瀏覽器是在「送出」之前就
	 * 中止，submit 事件根本不會發生（實測過：invalid 有觸發、submit 沒有）。
	 * 掛在 submit 上的版本是永遠不會執行到的死碼。
	 *
	 * invalid 不會冒泡，所以用捕獲階段掛在 document 上。
	 */
	var switching = false;

	document.addEventListener(
		'invalid',
		function ( e ) {
			// 每個無效欄位都會各發一次 invalid，但瀏覽器只會針對「第一個」顯示
			// 提示。事件依文件順序發出，所以只處理這一輪的第一個就對了；多處理
			// 反而會切到最後一個欄位的頁籤，跟瀏覽器指的不是同一個。
			if ( switching ) {
				return;
			}

			var field = e.target;
			var pane  = field && field.closest ? field.closest( '[data-uappt-tab]' ) : null;

			if ( ! pane || pane.classList.contains( 'is-active' ) ) {
				return;
			}

			switching = true;
			// 在 invalid 事件裡同步切換，欄位才會在瀏覽器「對焦並顯示提示泡泡」
			// 之前就已經是看得見的。這裡不需要 preventDefault——送出早就被中止了。
			show( ownersOf( pane )[ 0 ] );

			window.setTimeout( function () {
				switching = false;
			}, 0 );
		},
		true
	);
} )();

/**
 * 時段分類的分界時間鏡射。
 *
 * 三段的邊界是共用的：第一段的結束就是第二段的開始。上面那一格是輸入框、
 * 下一列同一個時間是唯讀的文字，改了輸入框而文字沒跟著動的話，畫面會同時
 * 顯示兩個互相矛盾的值——存檔前那段時間使用者看到的是錯的。
 *
 * ⚠️ 只做鏡射，**不做格式正規化**。設定頁一律是伺服器端正規化（見
 * UAPPT_Product::sanitize_segment_config()，「930」會變成「09:30」），跟旁邊
 * 「前一天的發送時間」同一套做法；在這裡再做一次只會多一份會走鐘的邏輯。
 *
 * 獨立一個 IIFE 而不是接在上面那段後面：那段開頭有 `if ( ! nav ) return`，
 * 併在一起的話，將來若有哪一頁沒有子頁籤，這裡會跟著靜默失效。
 */
( function () {
	'use strict';

	var mirrors = document.querySelectorAll( '.uappt-boundary-mirror' );

	if ( ! mirrors.length ) {
		return;
	}

	function sync() {
		Array.prototype.forEach.call( mirrors, function ( mirror ) {
			var source = document.getElementById( 'uappt-boundary-' + mirror.getAttribute( 'data-mirror' ) );
			if ( source ) {
				mirror.textContent = source.value;
			}
		} );
	}

	Array.prototype.forEach.call(
		document.querySelectorAll( '.uappt-boundary-input' ),
		function ( input ) {
			input.addEventListener( 'input', sync );
		}
	);

	sync();
} )();

/**
 * 通知卡片的即時預覽（v2.73.0）。
 *
 * ⚠️ **預覽只能比實際保守，不能比實際好看。**
 * 客人收到的是 LINE 的 Flex 卡片，這裡是用 HTML 模擬它。資料列由 PHP 端的
 * UAPPT_Card::preview_samples() 提供（跟 build() 走同一套顯示條件），標題、
 * 問候語、按鈕文字與表頭色則直接讀表單當下的值——那才是「即時」的意思，
 * 若連那些都等 PHP 算好，使用者得存檔才看得到變化。
 *
 * 沒有這支 JS 時設定頁仍然完整可用，只是少了預覽。
 */
( function () {
	'use strict';

	var CFG = window.UAPPT_CardPreview || {};

	function val( id, fallback ) {
		var el = document.getElementById( id );
		if ( ! el || '' === el.value.trim() ) {
			return fallback || '';
		}
		return el.value;
	}

	function esc( text ) {
		var d = document.createElement( 'div' );
		d.textContent = String( text == null ? '' : text );
		return d.innerHTML;
	}

	/** 問候語的變數代換，規則跟 PHP 的 render_greeting() 一致。 */
	function greeting() {
		return val( 'uappt-card-greeting', '{customer_name} 您好' )
			.replace( /\{customer_name\}/g, '王小美' )
			.replace( /\{shop_name\}/g, CFG.shopName || '' )
			.trim();
	}

	function titleFor( kind ) {
		if ( 'hour' === kind ) {
			return val( 'uappt-card-title-hour', '即將開始' );
		}
		if ( 'staff_change' === kind ) {
			return val( 'uappt-card-title-staff', '服務人員異動' );
		}
		// 待付款的預覽沿用「前一天提醒」的標題——它示範的是按鈕差異，不是標題。
		return val( 'uappt-card-title-day', '預約提醒' );
	}

	function render() {
		var box = document.getElementById( 'uappt-card-preview' );
		var sel = document.getElementById( 'uappt-card-preview-kind' );
		if ( ! box || ! sel || ! CFG.samples ) {
			return;
		}

		var kind   = sel.value;
		var sample = CFG.samples[ kind ] || CFG.samples.day;
		var color  = val( 'uappt-card-header-color', '#06C755' );
		var greet  = greeting();

		var btnText = sample.awaiting
			? val( 'uappt-card-pay-button', '前往付款' )
			: val( 'uappt-card-button', '加入行事曆' );

		var rows = ( sample.rows || [] ).map( function ( r ) {
			return '<div class="uappt-card-preview-row">' +
				'<span class="uappt-card-preview-label">' + esc( r.label ) + '</span>' +
				'<span class="uappt-card-preview-value">' + esc( r.value ) + '</span>' +
				'</div>';
		} ).join( '' );

		box.innerHTML =
			'<div class="uappt-card-preview-bubble">' +
				'<div class="uappt-card-preview-header" style="background:' + esc( color ) + ';">' +
					'<span class="uappt-card-preview-shop">' + esc( CFG.shopName ) + '</span>' +
					'<span class="uappt-card-preview-title">' + esc( titleFor( kind ) ) + '</span>' +
				'</div>' +
				'<div class="uappt-card-preview-body">' +
					( greet ? '<p class="uappt-card-preview-greeting">' + esc( greet ) + '</p><hr />' : '' ) +
					rows +
				'</div>' +
				'<div class="uappt-card-preview-footer">' +
					'<span class="uappt-card-preview-btn" style="background:' + esc( color ) + ';">' + esc( btnText ) + '</span>' +
				'</div>' +
			'</div>';
	}

	function init() {
		if ( ! document.getElementById( 'uappt-card-preview' ) ) {
			return;
		}

		var watched = [
			'uappt-card-header-color', 'uappt-card-greeting',
			'uappt-card-title-day', 'uappt-card-title-hour', 'uappt-card-title-staff',
			'uappt-card-button', 'uappt-card-pay-button',
			'uappt-card-preview-kind'
		];

		watched.forEach( function ( id ) {
			var el = document.getElementById( id );
			if ( ! el ) {
				return;
			}
			// input 管打字、change 管色票與下拉——色票在某些瀏覽器只發 change。
			el.addEventListener( 'input', render );
			el.addEventListener( 'change', render );
		} );

		render();
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
