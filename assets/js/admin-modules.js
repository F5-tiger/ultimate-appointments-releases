/**
 * 「功能模組」頁籤：群組總開關、模組開關、與最上面那行「目前方案」的連動。
 *
 * 三件事：
 * - 撥群組總開關 → 那一組的模組全開或全關
 * - 撥模組開關   → 重算總開關（全開＝開、全關＝關、只開一部分＝半開）
 * - 任何改動     → 重算「目前方案」那行字，並標出「有未儲存的變更」
 *
 * 全部都**不送出**：整頁只有一種互動模式（撥開關 → 按儲存），而且「專業版
 * 再加購耗材」可以在同一次存檔裡完成。
 *
 * ⚠️ currentPlan() 跟 UAPPT_Modules::current_tier() 是同一條規則（頁面第一次畫出來
 * 由 PHP 算，之後撥開關由這裡算）。
 * 撥開關時要即時更新，不可能每次回伺服器問，所以兩邊各一份實作；吃的是同一
 * 份 presets／labels 資料（由 PHP 帶進來），改規則時兩邊都要動。
 */
( function () {
	'use strict';

	var data = window.UAPPT_Admin_Modules || {};
	var form = document.getElementById( 'uappt-modules-form' );

	if ( ! form || ! data.presets || ! data.tiers ) {
		return;
	}

	var boxes  = form.querySelectorAll( 'input[type="checkbox"][name^="modules["]' );
	var groups = form.querySelectorAll( 'input[type="checkbox"][data-uappt-group]' );
	var dirty  = form.querySelector( '[data-uappt-dirty]' );
	var badge  = form.querySelector( '[data-uappt-tier-badge]' );
	var extra  = form.querySelector( '[data-uappt-tier-extra]' );

	// 記下進來時的狀態。判斷「有沒有未儲存的變更」比對的是**目前與初始的
	// 差異**，不是「動過沒有」——撥出去再撥回來就是沒有變更。
	var initial = {};
	Array.prototype.forEach.call( boxes, function ( box ) {
		initial[ box.name ] = box.checked;
	} );

	function key( box ) {
		return box.name.replace( /^modules\[|\]$/g, '' );   // modules[staff_portal] → staff_portal
	}

	function inTier( tier ) {
		return Array.prototype.filter.call( boxes, function ( b ) {
			return b.getAttribute( 'data-uappt-tier' ) === tier;
		} );
	}

	function enabledKeys() {
		return Array.prototype.filter.call( boxes, function ( b ) {
			return b.checked;
		} ).map( key );
	}

	/**
	 * 目前的基準層級與加購項目。跟 UAPPT_Modules::current_tier() 是同一條規則。
	 */
	function currentPlan() {
		var on   = enabledKeys();
		var keys = Object.keys( data.presets );
		var base = keys[ 0 ];

		// 由高往低找：第一個「模組全都開著」的層級就是基準。
		for ( var i = keys.length - 1; i >= 0; i-- ) {
			var all = data.presets[ keys[ i ] ].every( function ( k ) {
				return on.indexOf( k ) !== -1;
			} );
			if ( all ) {
				base = keys[ i ];
				break;
			}
		}

		return {
			tier:   data.tiers[ base ],
			extras: on.filter( function ( k ) {
				return data.presets[ base ].indexOf( k ) === -1;
			} ).map( function ( k ) {
				return data.labels[ k ] || k;
			} ),
		};
	}

	function refresh() {
		Array.prototype.forEach.call( groups, function ( master ) {
			var mine = inTier( master.getAttribute( 'data-uappt-group' ) );
			var on   = mine.filter( function ( b ) {
				return b.checked;
			} ).length;

			master.checked       = ( on === mine.length && mine.length > 0 );
			// 只開了一部分：半開。這是 DOM 屬性，沒有對應的 HTML attribute，
			// 所以初始狀態也要由這裡設一次（PHP 印不出來）。
			master.indeterminate = ( on > 0 && on < mine.length );
		} );

		var plan = currentPlan();

		if ( badge ) {
			badge.textContent = plan.tier;
		}

		if ( extra ) {
			extra.textContent = plan.extras.length
				? data.extraFormat.replace( '%s', plan.extras.join( data.separator ) )
				: '';
		}

		if ( dirty ) {
			dirty.hidden = ! Array.prototype.some.call( boxes, function ( box ) {
				return box.checked !== initial[ box.name ];
			} );
		}
	}

	form.addEventListener( 'change', function ( e ) {
		var master = e.target.closest ? e.target.closest( '[data-uappt-group]' ) : null;

		if ( master ) {
			var wanted = master.checked;
			inTier( master.getAttribute( 'data-uappt-group' ) ).forEach( function ( box ) {
				box.checked = wanted;
			} );
		}

		refresh();
	} );

	refresh();
} )();
