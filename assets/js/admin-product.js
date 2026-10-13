/**
 * 商品編輯頁的「預約設定」分頁：服務方案的可重複列（新增／刪除／排序／進階展開）。
 *
 * v2.1.0 起不再有變化款相關的欄位切換，也不再有「需要預約時段」勾選框
 * ——商品類型本身就是開關，這個分頁只在「預約商品」出現。
 *
 * v2.1.2：加入沿用值即時顯示、進階展開層、拖曳排序、送出前的名稱驗證。
 * v3.3.0：卡片可以收合成一行（名稱＋摘要），工具列有方案數與全部展開／收合。
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
		syncToolbar();
	}

	/* --- 收合（v3.3.0）--------------------------------------------------------
	 *
	 * 一張卡片約 220px，十個方案就超過兩千；收合之後一個方案只剩一行：名稱＋摘要標籤
	 * （時長、售價、人員、暫停銷售）。摘要不只放名稱，是因為收合的目的是「一眼看完所有
	 * 方案」——只有名稱的話，要確認價格又得一個個打開。
	 *
	 * ⚠️ 收合狀態是 JS 載入後才套上的，PHP 輸出的是展開的卡片：這支 JS 沒載入的話，卡片
	 * 至少還是能直接編輯，不會全部縮著打不開。
	 */
	function fmt( template, value ) {
		return String( template || '' ).replace( /%[sd]/, value );
	}

	function formatPrice( raw ) {
		var n = parseFloat( String( raw ).replace( /,/g, '' ) );
		if ( isNaN( n ) ) {
			return String( raw );
		}
		return ( i18n.currency || '' ) + n.toLocaleString( 'zh-TW', { maximumFractionDigits: 2 } );
	}

	function addChip( $chips, text, extraClass, title ) {
		var $chip = $( '<span class="uappt-plan-chip"></span>' ).text( text ).appendTo( $chips );
		if ( extraClass ) {
			$chip.addClass( extraClass );
		}
		if ( title ) {
			$chip.attr( 'title', title );
		}
	}

	/**
	 * 從欄位算出收合那一行的名稱與摘要。欄位是唯一的來源，收起來看到的永遠是最新的值。
	 * 留空（沿用商品層級的設定）的那幾項畫成淡色，跟自己填的分得出來。
	 */
	function refreshSummary( $row ) {
		var name = $.trim( $row.find( '.uappt-plan-name' ).val() );
		$row.find( '.uappt-plan-title' ).text( name || i18n.untitled ).toggleClass( 'is-empty', ! name );

		var $chips = $row.find( '.uappt-plan-chips' ).empty();

		var $duration = $row.find( '[data-field="duration"]' );
		var duration = $.trim( $duration.val() );
		if ( duration ) {
			addChip( $chips, fmt( i18n.chip_minutes, duration ) );
		} else {
			addChip( $chips, fmt( i18n.chip_minutes, $duration.attr( 'data-inherit' ) ), 'is-inherited', i18n.chip_inherited );
		}

		var price = $.trim( $row.find( '.wc_input_price' ).val() );
		if ( price ) {
			addChip( $chips, formatPrice( price ) );
		} else {
			addChip( $chips, i18n.chip_price_inherit, 'is-inherited' );
		}

		var staffCount = $row.find( '.uappt-plan-staff option:selected' ).length;
		if ( staffCount ) {
			addChip( $chips, fmt( i18n.chip_staff_count, staffCount ) );
		} else {
			addChip( $chips, i18n.chip_staff_inherit, 'is-inherited' );
		}

		addTimeRuleChip( $chips, $row );

		if ( $row.find( '.uappt-plan-inactive' ).is( ':checked' ) ) {
			addChip( $chips, i18n.chip_inactive, 'is-inactive' );
		}

		if ( $row.hasClass( 'is-modified' ) ) {
			addChip( $chips, i18n.chip_modified, 'is-modified' );
		}
	}

	/**
	 * 一組可預約時段規則的一行摘要：「場次 10:00、14:00」或「13:00–17:00 開始」。
	 * 沒有限制、或還沒有任何有效列時回空字串。
	 *
	 * 只看有勾星期的列：沒勾星期的列存檔時會被丟掉，摘要不該把它算進去。
	 */
	function timeRuleSummary( $editor ) {
		var tool = window.UAPPT_TimeRuleEditor;
		if ( ! tool || ! $editor.length ) {
			return '';
		}

		var mode = tool.mode( $editor );
		var $rows = $editor.find( '.uappt-time-rule-row' ).filter( function () {
			return $( this ).find( '.uappt-weekday input:checked' ).length > 0;
		} );

		if ( 'fixed' === mode ) {
			var seen = {};
			var times = [];
			$rows.each( function () {
				tool.parseTimes( $( this ).find( '.uappt-time-rule-times' ).val() ).times.forEach( function ( t ) {
					if ( ! seen[ t ] ) {
						seen[ t ] = true;
						times.push( t );
					}
				} );
			} );
			times.sort();
			if ( ! times.length ) {
				return '';
			}
			// 場次多的時候標籤會撐得很長，收合列的重點是一眼看完，列前四個就好。
			return fmt( i18n.chip_sessions, times.slice( 0, 4 ).join( '、' ) + ( times.length > 4 ? '…' : '' ) );
		}

		if ( 'range' === mode ) {
			var ranges = [];
			$rows.each( function () {
				var from = tool.hm( $( this ).find( '.uappt-time-rule-from' ).val() );
				var to = tool.hm( $( this ).find( '.uappt-time-rule-to' ).val() );
				if ( from && to && from <= to && -1 === ranges.indexOf( from + '–' + to ) ) {
					ranges.push( from + '–' + to );
				}
			} );
			if ( ! ranges.length ) {
				return '';
			}
			return fmt( i18n.chip_range, ranges[ 0 ] ) + ( ranges.length > 1 ? i18n.chip_more : '' );
		}

		return '';
	}

	/**
	 * 方案卡片上的可預約時段標籤。
	 *
	 * 沿用商品時：商品有限制才顯示（淡色，跟其他沿用值同一個樣式）；商品也不限制就
	 * 什麼都不放——「不限制」是預設狀態，每張卡片都掛一個只是雜訊。方案明確選了
	 * 「不限制」也是同一個道理，只在商品有限制、這個方案偏偏不限時才講出來。
	 */
	function addTimeRuleChip( $chips, $row ) {
		var tool = window.UAPPT_TimeRuleEditor;
		var $editor = $row.find( '.uappt-time-rule' );
		if ( ! tool || ! $editor.length ) {
			return;
		}

		var productSummary = timeRuleSummary( $( '.uappt-time-rule-group .uappt-time-rule' ) );
		var mode = tool.mode( $editor );

		if ( 'inherit' === mode ) {
			if ( productSummary ) {
				addChip( $chips, productSummary, 'is-inherited', i18n.chip_time_inherit );
			}
			return;
		}

		if ( 'none' === mode ) {
			if ( productSummary ) {
				addChip( $chips, i18n.chip_no_limit );
			}
			return;
		}

		var own = timeRuleSummary( $editor );
		if ( own ) {
			addChip( $chips, own );
		}
	}

	function setCollapsed( $row, collapsed ) {
		if ( collapsed ) {
			refreshSummary( $row );
		}
		$row.toggleClass( 'is-collapsed', collapsed );

		var label = collapsed ? i18n.expand : i18n.collapse;
		$row.find( '.uappt-plan-collapse' )
			.attr( { 'aria-expanded': collapsed ? 'false' : 'true', title: label, 'aria-label': label } )
			.find( '.dashicons' )
			.toggleClass( 'dashicons-arrow-down-alt2', collapsed )
			.toggleClass( 'dashicons-arrow-up-alt2', ! collapsed );

		syncToolbar();
	}

	/**
	 * 工具列：「共 N 個方案（M 個暫停銷售）」，以及「全部展開」／「全部收合」——還有任何
	 * 一張收著就是「全部展開」，全部都開著才變成「全部收合」。
	 */
	function syncToolbar() {
		var $all = $rows().find( '.uappt-plan-row' );
		var inactive = $all.filter( function () {
			return $( this ).find( '.uappt-plan-inactive' ).is( ':checked' );
		} ).length;
		var anyCollapsed = $all.filter( '.is-collapsed' ).length > 0;
		var $bar = $( '.uappt-plans-toolbar' );

		$bar.prop( 'hidden', ! $all.length );
		$bar.find( '.uappt-plans-count' ).text(
			fmt( i18n.plans_count, $all.length ) + ( inactive ? fmt( i18n.plans_inactive, inactive ) : '' )
		);
		$bar.find( '.uappt-plans-toggle-all' )
			.text( anyCollapsed ? i18n.expand_all : i18n.collapse_all )
			.data( 'collapse', ! anyCollapsed );
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
		if ( $row.hasClass( 'is-collapsed' ) ) {
			refreshSummary( $row );
		}
		syncToolbar();
	}

	/**
	 * 「進階」按鈕右上角的紅點：只要前／後緩衝有值、或方案已停用，就代表這個展開層
	 * 裡藏著會實際生效的設定，不能讓管理者以為收起來的欄位是空的。
	 */
	function refreshAdvancedDot( $row ) {
		var hasValues =
			$.trim( $row.find( '[data-field="buffer_before"]' ).val() ) !== '' ||
			$.trim( $row.find( '[data-field="buffer_after"]' ).val() ) !== '' ||
			$row.find( '.uappt-plan-inactive' ).is( ':checked' ) ||
			// 可預約時段不是「沿用商品」：明確選「不限制」也算，那是一個會蓋過商品設定的值。
			'inherit' !== ( $row.find( '.uappt-time-rule-modes input:checked' ).val() || 'inherit' );
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

	/* --- 批次編輯（v3.3.0）----------------------------------------------------
	 *
	 * 方案很多時要一次調價、換人、暫停：勾選幾個方案，在動作列選要做什麼。
	 *
	 * ⚠️ **只改各張卡片自己的欄位**，按商品的「更新」才跟著表單一起存。不另外開寫入路徑：
	 * 一來寫入不用 AJAX 是設計紀律 #8，二來這樣「改到一半不想要了」只要不按更新就好，跟手動
	 * 改卡片完全一樣。改到的方案加上「已修改」標籤。
	 *
	 * 進入批次模式時全部收合（清單才短）、拖曳與單張刪除先關掉，免得跟勾選打架。
	 */
	function $batch() {
		return $( '.uappt-plans-batch' );
	}

	function isBatchMode() {
		return $( '.uappt-plans-group' ).hasClass( 'is-batch' );
	}

	function $pickedRows() {
		return $rows().find( '.uappt-plan-row.is-picked' );
	}

	function enterBatchMode() {
		$rows()
			.find( '.uappt-plan-row' )
			.each( function () {
				var $row = $( this );
				setCollapsed( $row, true );
				if ( ! $row.find( '.uappt-plan-pick' ).length ) {
					$( '<input type="checkbox" class="uappt-plan-pick" />' ).prependTo( $row.find( '.uappt-plan-head' ) );
				}
			} );
		$( '.uappt-plans-group' ).addClass( 'is-batch' );
		$batch().prop( 'hidden', false );
		$batch().find( '.uappt-batch-result' ).prop( 'hidden', true );
		if ( $.fn.sortable && $rows().hasClass( 'ui-sortable' ) ) {
			$rows().sortable( 'disable' );
		}
		syncBatchBar();
	}

	function exitBatchMode() {
		$rows().find( '.uappt-plan-pick' ).remove();
		$rows().find( '.uappt-plan-row' ).removeClass( 'is-picked' );
		$( '.uappt-plans-group' ).removeClass( 'is-batch' );
		$batch().prop( 'hidden', true );
		closeBatchPanels();
		if ( $.fn.sortable && $rows().hasClass( 'ui-sortable' ) ) {
			$rows().sortable( 'enable' );
		}
		syncToolbar();
	}

	function setPicked( $row, picked ) {
		$row.toggleClass( 'is-picked', picked );
		$row.find( '.uappt-plan-pick' ).prop( 'checked', picked );
	}

	function syncBatchBar() {
		var total = $rows().find( '.uappt-plan-row' ).length;
		var picked = $pickedRows().length;
		$batch().find( '.uappt-batch-count' ).text( fmt( i18n.batch_selected, picked ) );
		$batch().find( '.uappt-batch-all' )
			.prop( 'checked', total > 0 && picked === total )
			.prop( 'indeterminate', picked > 0 && picked < total );
		$batch().find( '.uappt-batch-actions .button' ).prop( 'disabled', ! picked );
		if ( ! picked ) {
			closeBatchPanels();
		}
	}

	function closeBatchPanels() {
		$batch().find( '.uappt-batch-panel' ).prop( 'hidden', true );
		$batch().find( '.uappt-batch-open' ).removeClass( 'is-active' );
	}

	function openBatchPanel( name ) {
		closeBatchPanels();
		$batch().find( '.uappt-batch-panel[data-panel="' + name + '"]' ).prop( 'hidden', false );
		$batch().find( '.uappt-batch-open[data-panel="' + name + '"]' ).addClass( 'is-active' );
		$batch().find( '.uappt-batch-result' ).prop( 'hidden', true );
		if ( 'staff' === name ) {
			initEnhancedSelect( $batch().find( '.uappt-batch-panel[data-panel="staff"]' ) );
		}
	}

	function showBatchResult( text ) {
		$batch().find( '.uappt-batch-result' ).text( text ).prop( 'hidden', false );
	}

	function markModified( $row ) {
		$row.addClass( 'is-modified' );
		refreshSummary( $row );
	}

	/**
	 * 售價寫回欄位：照 WooCommerce 的小數位數與小數點符號（wc_input_price 會用店家設定的
	 * 小數點驗證，寫成 1200.5 而店家設定是逗號的話，存檔時會被當成格式錯誤）。
	 */
	function writePrice( $input, value ) {
		var decimals = parseInt( i18n.price_decimals, 10 ) || 0;
		var text = Math.max( 0, value ).toFixed( decimals );
		$input.val( text.replace( '.', i18n.decimal_sep || '.' ) ).trigger( 'change' );
	}

	function readPrice( raw ) {
		var sep = i18n.decimal_sep || '.';
		return parseFloat( String( raw ).split( sep ).join( '.' ).replace( /[^0-9.\-]/g, '' ) );
	}

	function applyBatch( panel ) {
		var $panel = $batch().find( '.uappt-batch-panel[data-panel="' + panel + '"]' );
		var $targets = $pickedRows();
		var skipped = 0;

		if ( 'staff' === panel ) {
			var mode = $panel.find( 'input[type="radio"]:checked' ).val();
			var chosen = $panel.find( '.uappt-batch-staff' ).val() || [];
			if ( 'inherit' !== mode && ! chosen.length ) {
				window.alert( i18n.batch_need_staff );
				return;
			}
			$targets.each( function () {
				var $select = $( this ).find( '.uappt-plan-staff' );
				var current = $select.val() || [];
				var next;
				if ( 'add' === mode ) {
					next = current.concat( chosen.filter( function ( id ) { return current.indexOf( id ) === -1; } ) );
				} else if ( 'remove' === mode ) {
					next = current.filter( function ( id ) { return chosen.indexOf( id ) === -1; } );
				} else if ( 'replace' === mode ) {
					next = chosen.slice();
				} else {
					next = [];
				}
				$select.val( next ).trigger( 'change' );
				markModified( $( this ) );
			} );
		} else if ( 'price' === panel ) {
			var priceMode = $panel.find( 'input[type="radio"]:checked' ).val();
			var amount = parseFloat( $panel.find( '.uappt-batch-price' ).val() );
			if ( isNaN( amount ) ) {
				window.alert( i18n.batch_need_number );
				return;
			}
			$targets.each( function () {
				var $input = $( this ).find( '.wc_input_price' );
				var raw = $.trim( $input.val() );
				if ( 'set' === priceMode ) {
					writePrice( $input, amount );
				} else if ( '' === raw || isNaN( readPrice( raw ) ) ) {
					// 沿用商品售價的方案沒有自己的數字可以加減，跳過並告訴管理者。
					skipped++;
					return;
				} else if ( 'amount' === priceMode ) {
					writePrice( $input, readPrice( raw ) + amount );
				} else {
					writePrice( $input, readPrice( raw ) * ( 1 + amount / 100 ) );
				}
				markModified( $( this ) );
			} );
		} else if ( 'duration' === panel ) {
			var raw = $.trim( $panel.find( '.uappt-batch-duration' ).val() );
			var minutes = parseInt( raw, 10 );
			if ( '' !== raw && ( isNaN( minutes ) || minutes < 5 ) ) {
				window.alert( i18n.batch_need_number );
				return;
			}
			$targets.each( function () {
				$( this ).find( '[data-field="duration"]' ).val( '' === raw ? '' : minutes ).trigger( 'input' );
				markModified( $( this ) );
			} );
		}

		closeBatchPanels();
		var applied = $targets.length - skipped;
		showBatchResult( fmt( i18n.batch_applied, applied ) + ( skipped ? ' ' + fmt( i18n.batch_price_skipped, skipped ) : '' ) );
	}

	function applyBatchInactive( inactive ) {
		var $targets = $pickedRows();
		$targets.each( function () {
			var $box = $( this ).find( '.uappt-plan-inactive' );
			if ( $box.is( ':checked' ) !== inactive ) {
				$box.prop( 'checked', inactive ).trigger( 'change' );
				markModified( $( this ) );
			}
		} );
		closeBatchPanels();
		showBatchResult( fmt( i18n.batch_applied, $targets.length ) );
	}

	function deletePicked() {
		var $targets = $pickedRows();
		if ( ! $targets.length || ! window.confirm( fmt( i18n.batch_confirm_delete, $targets.length ) ) ) {
			return;
		}
		var count = $targets.length;
		$targets.remove();
		syncEmptyNotice();
		if ( ! $rows().find( '.uappt-plan-row' ).length ) {
			exitBatchMode();
			return;
		}
		syncBatchBar();
		showBatchResult( fmt( i18n.batch_deleted, count ) );
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
		// 可預約時段選了「沿用商品」以外的模式也算（跟 PHP 的 row_has_content() 一致）。
		if ( 'inherit' !== ( $row.find( '.uappt-time-rule-modes input:checked' ).val() || 'inherit' ) ) {
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

		// 收著的話先展開，名稱欄位跟錯誤訊息才看得到。
		if ( $offender.hasClass( 'is-collapsed' ) ) {
			setCollapsed( $offender, false );
		}

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

		$( document ).on( 'click', '.uappt-plan-collapse', function ( e ) {
			e.preventDefault();
			var $row = $( this ).closest( '.uappt-plan-row' );
			setCollapsed( $row, ! $row.hasClass( 'is-collapsed' ) );
		} );

		// 收著的時候整行都可以點開（按鈕除外：刪除就是刪除，不要順便展開）。
		$( document ).on( 'click', '.uappt-plan-row.is-collapsed .uappt-plan-head', function ( e ) {
			if ( $( e.target ).closest( 'button, a' ).length ) {
				return;
			}
			var $row = $( this ).closest( '.uappt-plan-row' );
			// 批次模式：點整行就是勾選／取消（勾選框本身被點到時，瀏覽器已經切換過了）。
			if ( isBatchMode() ) {
				var $box = $row.find( '.uappt-plan-pick' );
				setPicked( $row, $( e.target ).is( $box ) ? $box.prop( 'checked' ) : ! $row.hasClass( 'is-picked' ) );
				syncBatchBar();
				return;
			}
			setCollapsed( $row, false );
		} );

		$( document ).on( 'click', '.uappt-plans-batch-toggle', function ( e ) {
			e.preventDefault();
			enterBatchMode();
		} );
		$( document ).on( 'click', '.uappt-batch-done', function ( e ) {
			e.preventDefault();
			exitBatchMode();
		} );
		$( document ).on( 'change', '.uappt-batch-all', function () {
			var on = $( this ).is( ':checked' );
			$rows()
				.find( '.uappt-plan-row' )
				.each( function () {
					setPicked( $( this ), on );
				} );
			syncBatchBar();
		} );
		$( document ).on( 'click', '.uappt-batch-open', function ( e ) {
			e.preventDefault();
			openBatchPanel( $( this ).data( 'panel' ) );
		} );
		$( document ).on( 'click', '.uappt-batch-cancel', function ( e ) {
			e.preventDefault();
			closeBatchPanels();
		} );
		$( document ).on( 'click', '.uappt-batch-apply', function ( e ) {
			e.preventDefault();
			applyBatch( $( this ).closest( '.uappt-batch-panel' ).data( 'panel' ) );
		} );
		$( document ).on( 'click', '.uappt-batch-inactive', function ( e ) {
			e.preventDefault();
			applyBatchInactive( '1' === String( $( this ).data( 'value' ) ) );
		} );
		$( document ).on( 'click', '.uappt-batch-delete', function ( e ) {
			e.preventDefault();
			deletePicked();
		} );

		$( document ).on( 'click', '.uappt-plans-toggle-all', function ( e ) {
			e.preventDefault();
			var collapse = !! $( this ).data( 'collapse' );
			$rows()
				.find( '.uappt-plan-row' )
				.each( function () {
					setCollapsed( $( this ), collapse );
				} );
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

		// 可預約時段（v3.4.0）改了：方案自己的編輯器→更新那張卡片的紅點與摘要；
		// 商品層級的編輯器→所有「沿用商品」的方案摘要都要跟著換。
		$( document ).on( 'uappt:time-rule-change', '.uappt-time-rule', function () {
			var $plan = $( this ).closest( '.uappt-plan-row' );
			var $targets = $plan.length ? $plan : $rows().find( '.uappt-plan-row' );
			$targets.each( function () {
				refreshAdvancedDot( $( this ) );
				if ( $( this ).hasClass( 'is-collapsed' ) ) {
					refreshSummary( $( this ) );
				}
			} );
		} );

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

		$rows()
			.find( '.uappt-plan-row' )
			.each( function () {
				refreshAdvancedDot( $( this ) );
				setCollapsed( $( this ), true ); // 既有方案預設收合，新增的方案（範本）是展開的。
			} );
		syncEmptyNotice();
	} );
} )( jQuery );
