/**
 * 商品編輯頁：可預約時段編輯器（v3.4.0）。
 *
 * 一個 `.uappt-time-rule` 就是一組規則：模式（沿用商品／不限制／固定場次／限定開始
 * 時段）＋若干列「星期＋時間」。商品層級一組，每個方案卡片的「進階」裡也各一組，
 * 所以事件全部委派在 document 上——方案卡片是 JS 動態新增的。
 *
 * 版面由 data-mode 決定（admin.css）：這裡只負責同步 data-mode、新增刪除列、
 * 整理時間格式，以及送出前的檢查。
 *
 * @package Ultimate_Appointments
 */
( function ( $ ) {
	'use strict';

	var i18n = window.UAPPT_TimeRule || {};
	var productI18n = window.UAPPT_AdminProduct || {};

	function fmt( template, value ) {
		return String( template || '' ).replace( /%s/, value );
	}

	/**
	 * 跟 UAPPT_Time_Rule::hm() 同一套規則：接受 9:00、09:00、全形冒號。
	 *
	 * @param {string} raw 時間。
	 * @return {string} HH:MM；看不懂回空字串。
	 */
	function hm( raw ) {
		var m = String( raw || '' ).match( /^\s*(\d{1,2})\s*[:：]\s*(\d{2})\s*$/ );
		if ( ! m ) {
			return '';
		}
		var h = parseInt( m[ 1 ], 10 );
		var i = parseInt( m[ 2 ], 10 );
		if ( h > 23 || i > 59 ) {
			return '';
		}
		return ( h < 10 ? '0' : '' ) + h + ':' + m[ 2 ];
	}

	/**
	 * 跟 UAPPT_Time_Rule::parse_times() 同一套規則。
	 *
	 * @param {string} str 「10:00、14:00」。
	 * @return {{times: string[], invalid: string[]}}
	 */
	function parseTimes( str ) {
		var seen = {};
		var times = [];
		var invalid = [];

		String( str || '' ).split( /[\s,，、;；]+/ ).forEach( function ( token ) {
			if ( '' === token ) {
				return;
			}
			var t = hm( token );
			if ( ! t ) {
				invalid.push( token );
				return;
			}
			if ( ! seen[ t ] ) {
				seen[ t ] = true;
				times.push( t );
			}
		} );

		times.sort();
		return { times: times, invalid: invalid };
	}

	function modeOf( $editor ) {
		return $editor.find( '.uappt-time-rule-modes input:checked' ).val() || 'none';
	}

	/** 通知方案卡片的摘要標籤重算（admin-product.js 聽這個事件）。 */
	function changed( $editor ) {
		$editor.trigger( 'uappt:time-rule-change' );
	}

	function clearErrors( $scope ) {
		$scope.find( '.uappt-time-rule-error' ).remove();
		$scope.find( '.uappt-field-error' ).removeClass( 'uappt-field-error' );
	}

	// --- 互動 ---------------------------------------------------------------

	$( document ).on( 'change', '.uappt-time-rule-modes input', function () {
		var $editor = $( this ).closest( '.uappt-time-rule' );
		$editor.attr( 'data-mode', modeOf( $editor ) );
		clearErrors( $editor );
		changed( $editor );
	} );

	$( document ).on( 'click', '.uappt-time-rule-add', function () {
		var $editor = $( this ).closest( '.uappt-time-rule' );
		var template = $( '#uappt-time-rule-row-template' ).html() || '';
		var next = parseInt( $editor.attr( 'data-next' ), 10 ) || 0;

		// 索引只要在這一組裡不重複就好（PHP 存檔時會重新排），所以用一直遞增的
		// 計數器，不用「目前幾列」——刪掉中間一列再新增，用列數會撞號。
		var html = template
			.split( '__NAME__' ).join( $editor.attr( 'data-name' ) )
			.split( '__ROW__' ).join( String( next ) );

		$editor.attr( 'data-next', next + 1 );

		var $row = $( html ).appendTo( $editor.find( '.uappt-time-rule-rows' ) );
		$row.find( 'fixed' === modeOf( $editor ) ? '.uappt-time-rule-times' : '.uappt-time-rule-from' ).trigger( 'focus' );
		changed( $editor );
	} );

	$( document ).on( 'click', '.uappt-time-rule-remove', function () {
		var $row = $( this ).closest( '.uappt-time-rule-row' );
		var $editor = $row.closest( '.uappt-time-rule' );

		// 最後一列不刪，改成清空：留一列空白列，切模式時才有地方填。
		if ( $editor.find( '.uappt-time-rule-row' ).length > 1 ) {
			$row.remove();
		} else {
			$row.find( 'input[type="text"], input[type="time"]' ).val( '' );
			$row.find( '.uappt-weekday input' ).prop( 'checked', true );
			clearErrors( $row );
		}
		changed( $editor );
	} );

	// 離開欄位時把時間整理成標準寫法（補零、排序、去重、頓號分隔）。看不懂的片段
	// 留著不動，送出前的檢查會指出來——自動刪掉的話管理者不會發現少了一場。
	$( document ).on( 'blur', '.uappt-time-rule-times', function () {
		var parsed = parseTimes( this.value );
		if ( ! parsed.invalid.length ) {
			this.value = parsed.times.join( '、' );
		}
		changed( $( this ).closest( '.uappt-time-rule' ) );
	} );

	$( document ).on( 'input change', '.uappt-time-rule-row input', function () {
		var $row = $( this ).closest( '.uappt-time-rule-row' );
		if ( $row.find( '.uappt-time-rule-error' ).length ) {
			clearErrors( $row );
		}
		if ( 'checkbox' === this.type || 'time' === this.type ) {
			changed( $row.closest( '.uappt-time-rule' ) );
		}
	} );

	// --- 送出前檢查 -----------------------------------------------------------

	/**
	 * 檢查一組規則；有問題回傳 { $row, $input, message }。
	 *
	 * 整列空白的列（沒填任何時間）在「還有別列有效」時略過——存檔時本來就會丟掉，
	 * 不必逼管理者去刪一列多按出來的空白列。全部空白才算錯。
	 */
	function findProblem( $editor ) {
		var mode = modeOf( $editor );
		if ( 'fixed' !== mode && 'range' !== mode ) {
			return null;
		}

		var problem = null;
		var valid = 0;
		var $rows = $editor.find( '.uappt-time-rule-row' );

		$rows.each( function () {
			var $row = $( this );
			var hasDays = $row.find( '.uappt-weekday input:checked' ).length > 0;

			if ( 'fixed' === mode ) {
				var $times = $row.find( '.uappt-time-rule-times' );
				var parsed = parseTimes( $times.val() );
				if ( parsed.invalid.length ) {
					problem = { $row: $row, $input: $times, message: fmt( i18n.bad_times, parsed.invalid.join( '、' ) ) };
					return false;
				}
				if ( ! parsed.times.length ) {
					return;
				}
				if ( ! hasDays ) {
					problem = { $row: $row, $input: $row.find( '.uappt-weekday input' ).first(), message: i18n.need_days };
					return false;
				}
				valid++;
				return;
			}

			var $from = $row.find( '.uappt-time-rule-from' );
			var $to = $row.find( '.uappt-time-rule-to' );
			var from = hm( $from.val() );
			var to = hm( $to.val() );
			if ( ! $from.val() && ! $to.val() ) {
				return;
			}
			if ( ! from || ! to || from > to ) {
				problem = { $row: $row, $input: from ? $to : $from, message: i18n.need_range };
				return false;
			}
			if ( ! hasDays ) {
				problem = { $row: $row, $input: $row.find( '.uappt-weekday input' ).first(), message: i18n.need_days };
				return false;
			}
			valid++;
		} );

		if ( problem ) {
			return problem;
		}

		if ( ! valid ) {
			var $first = $rows.first();
			return {
				$row: $first,
				$input: $first.find( 'fixed' === mode ? '.uappt-time-rule-times' : '.uappt-time-rule-from' ),
				message: 'fixed' === mode ? i18n.need_times : i18n.need_range
			};
		}

		return null;
	}

	/** 把藏著的編輯器打開：切到預約設定分頁、展開收合的方案卡片與它的「進階」。 */
	function reveal( $editor ) {
		$( '.wc-tabs a[href="#uappt_booking_product_data"]' ).trigger( 'click' );

		var $plan = $editor.closest( '.uappt-plan-row' );
		if ( $plan.length ) {
			if ( $plan.hasClass( 'is-collapsed' ) ) {
				$plan.find( '.uappt-plan-collapse' ).first().trigger( 'click' );
			}
			var $advanced = $plan.find( '.uappt-plan-advanced' );
			if ( ! $advanced.is( ':visible' ) ) {
				$plan.find( '.uappt-toggle-advanced' ).first().trigger( 'click' );
			}
		}
	}

	// ⚠️ 綁在 DOM 就緒之後、不是腳本一載入就綁：admin-product.js 的檢查（方案名稱、
	// 人員必填）是在它自己的 $( function () {} ) 裡綁的，這裡要排在它後面。jQuery 的
	// 事件依註冊順序執行，腳本載入當下就綁的話反而會先跑。
	$( function () {
		$( '#post' ).on( 'submit', onSubmit );
	} );

	function onSubmit( e ) {
		// 前面的檢查（方案名稱、人員必填）已經擋下來的話，不要再跳到別的地方。
		if ( e.isDefaultPrevented() ) {
			return;
		}
		// 只有預約商品才檢查：別的商品類型的表單裡這些欄位是藏著的，也不會被存。
		if ( productI18n.product_type && $( '#product-type' ).val() !== productI18n.product_type ) {
			return;
		}

		var found = null;
		$( '#uappt_booking_product_data .uappt-time-rule' ).each( function () {
			var problem = findProblem( $( this ) );
			if ( problem ) {
				found = $.extend( { $editor: $( this ) }, problem );
				return false;
			}
		} );

		if ( ! found ) {
			return;
		}

		e.preventDefault();
		reveal( found.$editor );
		clearErrors( found.$editor );

		$( '<p class="uappt-time-rule-error"></p>' ).text( found.message || '' ).appendTo( found.$row );
		found.$input.addClass( 'uappt-field-error' );
		found.$row.get( 0 ).scrollIntoView( { block: 'center' } );
		found.$input.trigger( 'focus' );
	}

	// 給 admin-product.js 的收合摘要用（方案卡片上的「場次 10:00、14:00」標籤）。
	window.UAPPT_TimeRuleEditor = {
		mode: modeOf,
		parseTimes: parseTimes,
		hm: hm
	};
}( jQuery ) );
