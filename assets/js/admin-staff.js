/**
 * 人員編輯頁：
 * 1. 時間欄位（type=text）離開焦點時自動格式化，接受 "930"、"9:5"、"0930" 等手動輸入。
 * 2. 「複製第一列到所有日」：把星期一那列的四個時間值複製到其他六天。
 * 3. 時段分類的分界時間鏡射：改了分界值，下一段開頭顯示的時間同步更新，
 *    讓管理員一眼看出三段是怎麼接續的。
 * 4. 排班方式三選一（固定班／彈性班／24 小時，v2.93.0 起取代「24 小時營業」勾選框）：
 *    只顯示選到的那一項相關的區塊；選 24 小時時藏起「這個月的班」。
 * 5. 人員照片：WordPress 內建媒體選擇器，只寫附件 ID 到 hidden input，存檔走一般表單送出。
 *
 * 這裡的格式化只是即時體驗回饋，真正的驗證與正規化一律以伺服器端
 * UAPPT_Staff::sanitize_time() 為準，兩邊邏輯刻意保持一致。
 */
( function ( $ ) {
	'use strict';

	/**
	 * 把使用者輸入的字串正規化成 "HH:mm"；無法辨識就原樣返回，讓伺服器端存檔時
	 * 用完整的驗證訊息告訴使用者哪裡錯了，這裡不擋輸入、只做能辨識的格式化。
	 *
	 * @param {string} raw 輸入值。
	 * @return {string}
	 */
	function formatTime( raw ) {
		var value = String( raw || '' ).trim();
		if ( '' === value ) {
			return '';
		}

		var colonMatch = value.match( /^(\d{1,2}):(\d{1,2})$/ );
		if ( colonMatch ) {
			var h1 = parseInt( colonMatch[ 1 ], 10 );
			var m1 = parseInt( colonMatch[ 2 ], 10 );
			if ( h1 <= 23 && m1 <= 59 ) {
				return pad( h1 ) + ':' + pad( m1 );
			}
			return value;
		}

		if ( /^\d{1,4}$/.test( value ) ) {
			var len = value.length;
			var hour;
			var minute;
			if ( len <= 2 ) {
				hour = parseInt( value, 10 );
				minute = 0;
			} else if ( 3 === len ) {
				hour = parseInt( value.substring( 0, 1 ), 10 );
				minute = parseInt( value.substring( 1, 3 ), 10 );
			} else {
				hour = parseInt( value.substring( 0, 2 ), 10 );
				minute = parseInt( value.substring( 2, 4 ), 10 );
			}
			if ( hour <= 23 && minute <= 59 ) {
				return pad( hour ) + ':' + pad( minute );
			}
		}

		return value;
	}

	function pad( n ) {
		return ( n < 10 ? '0' : '' ) + n;
	}

	/**
	 * 把「改這一天」那張表單填成某一格的現況。
	 *
	 * ⚠️ **這張表單不是新東西**，是原本就在的「新增／更新逐日調整」。這裡只是用 JS
	 * 把它填好——寫入仍然走 admin-post ＋ nonce ＋ capability（設計紀律 #8）。所以
	 * 這段壞掉的最差情況是「要自己填日期」，不會變成存不進去。
	 *
	 * @param {jQuery} $cell 月曆格子。
	 */
	function fillDayForm( $cell ) {
		var $dayForm = $( '.uappt-day-form' );
		if ( ! $dayForm.length || ! $cell.length ) {
			return;
		}

		var date   = $cell.data( 'uapptDate' );
		var state  = $cell.data( 'uapptState' );
		var note   = $cell.data( 'uapptNote' );
		var ranges = $cell.data( 'uapptRanges' );

		if ( ! date ) {
			return;
		}

		// jQuery 會把 data-uappt-ranges 的 JSON 自動解析成陣列；保險起見兩種都接。
		if ( typeof ranges === 'string' ) {
			try {
				ranges = JSON.parse( ranges );
			} catch ( err ) {
				ranges = [];
			}
		}
		if ( ! $.isArray( ranges ) ) {
			ranges = [];
		}

		$dayForm.find( '#uappt-override-date' ).val( date );
		$dayForm.find( '#uappt-override-note' ).val( note || '' );

		// 點不同狀態的日子，預設選哪個類型：
		//
		// | 點到的日子 | 預設 | 理由 |
		// | --- | --- | --- |
		// | 已有自訂時段 | 自訂時段（帶出現況） | 多半是要微調 |
		// | 例休 / 請假 | **原本那一種** | 點進來多半是要改備註，不該順手把請假改成排休 |
		// | 店休 | 自訂時段（空白） | 會點進來多半就是要讓這個人破例上班 |
		// | 未排班 | 請假 | radio 總要選一個，維持表單原本的預設 |
		var wantType = 'hours';
		if ( 'off' === state || 'leave' === state ) {
			wantType = state;
		} else if ( 'shop' !== state && ! ranges.length ) {
			wantType = 'leave';
		}
		$dayForm.find( '.uappt-override-type[value="' + wantType + '"' + ']' )
			.prop( 'checked', true )
			.trigger( 'change' );

		$dayForm.find( '[name^="override_hours"]' ).each( function ( i ) {
			var r = ranges[ Math.floor( i / 2 ) ];
			$( this ).val( r ? ( i % 2 === 0 ? r[ 0 ] : r[ 1 ] ) : '' );
		} );
	}

	/**
	 * 月曆排班：**先選日子，再選要排什麼**。
	 *
	 * v2.85.0 是「選畫筆 → 點格子」，v2.91.0 換掉。畫筆是一個**看不見的模式**：
	 * 選了「不塗」再點日子，什麼都不會發生，而使用者不會知道為什麼——對不熟電腦的
	 * 人這是致命的。而且它還帶來一個只有實際拖過才會發現的 bug（v2.88.1：拖曳的
	 * 起點不會被塗到）。
	 *
	 * 「選取 → 動作」是使用者早就會的模式（選檔案再選功能），順序也跟講話一樣：
	 * 「11 月 5 日排早班」，不是「拿著早班去點 11 月 5 日」。沒選日子時班別鈕整列
	 * 不存在，所以不可能出現「點了沒反應」。
	 *
	 * 拖曳一併拿掉：它只在滑鼠上能用、觸控上跟捲動打架，而選多天 ＋ 點星期標題選
	 * 整欄已經覆蓋它所有用途。
	 *
	 * ⚠️ **這段只動畫面，一行都不寫入。** 塗好的結果存在每一格那兩個 hidden input
	 * 裡（**名稱由 PHP 印好**，見 month-grid.php），按下「儲存這個月」才走
	 * admin-post ＋ nonce（設計紀律 #8）。所以這段壞掉的最差情況是「要用下面的
	 * 單日表單一天一天改」，不會變成存不進去。
	 *
	 * ⚠️ **dirty 旗標就是 input 的 disabled**，不另外發明一個欄位：disabled 的欄位
	 * 不會被送出，所以「沒碰過的日子」天生就不在 $_POST 裡——核准排班申請留下的
	 * source／request_id 也就不會被整月重寫洗掉。
	 *
	 * @param {jQuery} $wrap 頁面容器。
	 */
	function initMonthPaint( $wrap ) {
		var $form = $wrap.find( '.uappt-month-paint' );
		if ( ! $form.length ) {
			return;
		}

		var $dirty = $form.find( '#uappt-paint-dirty' );
		var $reset = $form.find( '#uappt-paint-reset' );

		// 原狀快照：還原時要把 class、兩個欄位的值、以及 disabled 全部放回去。
		// 在這裡存一次就好——之後所有變動都是從這個基準出發的。
		$form.find( '.uappt-cal-day.is-paintable' ).each( function () {
			var $cell = $( this );
			$cell.data( 'uapptOriginClass', this.className );
			$cell.data( 'uapptOriginType', $cell.find( '[data-uappt-paint="type"]' ).val() );
			$cell.data( 'uapptOriginRanges', $cell.find( '[data-uappt-paint="ranges"]' ).val() );
		} );

		function syncDirty() {
			var n = $form.find( '.uappt-cal-day.is-dirty' ).length;
			$dirty.text( ( $dirty.data( 'template' ) || '未儲存 %d 天' ).replace( '%d', n ) )
				.prop( 'hidden', 0 === n );
			$reset.prop( 'hidden', 0 === n );
		}

		/**
		 * 把一格塗成畫筆的樣子。
		 *
		 * ⚠️ **不重建格子裡的 HTML。** 要在 JS 裡組出 `<span class="uappt-cal-range">`
		 * 那一套，等於把 month-grid.php 的結構複製一份到這裡——那份複本一改版就會
		 * 悄悄跟本尊分岔。改成「掛一個 data 屬性，讓 CSS 用 attr() 印出來」，JS 只
		 * 產生純文字。順帶的好處是未儲存的樣子跟已存檔的長得不一樣，一眼看得出
		 * 哪幾天還沒存。
		 *
		 * @param {jQuery} $cell 格子。
		 * @param {Object} item  要排什麼：{ type, ranges（JSON 字串）, label, short }。
		 *                       **標籤一律由呼叫端給**：班別鈕的從按鈕上讀，產生器的
		 *                       由 PHP 算好（見 UAPPT_Staff::plan_label() 的 ⚠️）。
		 */
		function paint( $cell, item ) {
			if ( ! $cell.length || ! $cell.hasClass( 'is-paintable' ) ) {
				return;
			}

			var type   = String( item.type );
			var ranges = item.ranges || '[]';
			// 兩種長度：桌機放得下「早班 09:00–13:00」，手機一格只有 46px 寬，
			// 只放得下「早班」。兩個字串都掛上去，由 CSS 的斷點決定用哪一個
			// ——翻譯一律留在 PHP 那一側。
			var label  = item.label;
			var short  = item.short || item.label;

			// ⚠️ **排成「跟原本一模一樣」不算改過。** 不比對的話，「複製上個月」這種
			// 一次套滿整個月的動作會讓「未儲存 31 天」這個數字失去意義——實際上可能
			// 只有三天真的不一樣。數字要能回答「按下去會改幾天」，不是「我碰過幾格」。
			//
			// 順帶也讓誤觸可以自己復原：排錯了再排回原本那個班別，那一格就乾淨了。
			if ( type === String( $cell.data( 'uapptOriginType' ) ) && sameRanges( ranges, $cell.data( 'uapptOriginRanges' ) ) ) {
				clean( $cell );
				syncDirty();
				return;
			}

			$cell.find( '[data-uappt-paint="type"]' ).val( type ).prop( 'disabled', false );
			$cell.find( '[data-uappt-paint="ranges"]' ).val( ranges ).prop( 'disabled', false );

			// 底色跟著結果走，不然塗完整片顏色還是舊的，看不出排成什麼樣子。
			// 班別顏色（v2.99.0）一起換：先拿掉舊的，排成班別才加新的。
			$cell.removeClass( 'is-open is-closed is-unset' )
				.removeClass( function ( i, cls ) {
					return ( cls.match( /(^|\s)(has-shift|uappt-shift-c-\S+)/g ) || [] ).join( ' ' );
				} )
				.addClass( 'is-dirty' )
				.addClass( 'hours' === type ? 'is-open' : ( 'clear' === type ? 'is-unset' : 'is-closed' ) )
				.attr( 'data-uappt-pending', label )
				.attr( 'data-uappt-pending-short', short );
			if ( 'hours' === type && item.color ) {
				$cell.addClass( 'has-shift uappt-shift-c-' + item.color );
			}

			syncDirty();
		}

		/**
		 * 比對兩組時段是不是同一份。
		 *
		 * 兩邊都是 JSON 字串，但**不能直接比字串**：`[["09:00","13:00"]]` 跟
		 * `[ ["09:00", "13:00"] ]` 是同一份班，空白不同而已。解析後重新序列化才問得出
		 * 「這兩天的班一樣嗎」。
		 *
		 * @param {string} a 其中一組。
		 * @param {string} b 另一組。
		 * @return {boolean}
		 */
		function sameRanges( a, b ) {
			function norm( v ) {
				try {
					var parsed = JSON.parse( v || '[]' );
					return JSON.stringify( $.isArray( parsed ) ? parsed : [] );
				} catch ( err ) {
					return '[]';
				}
			}
			return norm( a ) === norm( b );
		}

		/**
		 * 把一格放回原狀（class、兩個欄位的值、disabled）。
		 *
		 * @param {jQuery} $cell 格子。
		 */
		function clean( $cell ) {
			$cell.get( 0 ).className = $cell.data( 'uapptOriginClass' );
			$cell.removeAttr( 'data-uappt-pending' ).removeAttr( 'data-uappt-pending-short' );
			$cell.find( '[data-uappt-paint="type"]' ).val( $cell.data( 'uapptOriginType' ) ).prop( 'disabled', true );
			$cell.find( '[data-uappt-paint="ranges"]' ).val( $cell.data( 'uapptOriginRanges' ) ).prop( 'disabled', true );
		}

		function resetAll() {
			$form.find( '.uappt-cal-day.is-dirty' ).each( function () {
				clean( $( this ) );
			} );
			syncDirty();
		}

		/**
		 * 產生器：把一整份「哪一天要排成什麼」套到月曆上。
		 *
		 * ⚠️ **走的是跟手動排班完全相同的那一支 paint()**，不是另一條路。所以產生出來
		 * 的結果一樣是未儲存的、一樣能還原、一樣只送改過的日子——「跟原本一樣的日子
		 * 不算改過」那條規則也就自動適用，按一下「複製上個月」不會變成「未儲存 31 天」。
		 *
		 * @param {Object} plan date => { t: 類型, r: 時段 }
		 */
		function applyPlan( plan ) {
			if ( ! plan ) {
				return;
			}

			$form.find( '.uappt-cal-day.is-paintable' ).each( function () {
				var $cell = $( this );
				var day   = plan[ String( $cell.data( 'uapptDate' ) ) ];
				if ( ! day ) {
					return;
				}

				// 標籤直接用 PHP 算好的（day.l / day.s）。不在這裡「找一顆最像的畫筆」
				// ——複製過來的時段不保證等於任何班別，挑錯的話格子會寫著「早班」卻
				// 存進別的時段，畫面騙人而存檔是對的，最難發現的那一種。
				paint( $cell, {
					type: day.t,
					ranges: JSON.stringify( day.r || [] ),
					label: day.l,
					short: day.s,
					color: day.c || ''
				} );
			} );
		}

		$reset.on( 'click', resetAll );


		/**
		 * 一顆班別鈕翻成 paint() 吃的格式。標籤直接讀按鈕上的字，翻譯因此只有
		 * PHP 那一份。
		 *
		 * @param {jQuery} $shift 班別按鈕。
		 * @return {Object}
		 */
		function shiftItem( $shift ) {
			return {
				type: String( $shift.data( 'uapptShift' ) ),
				ranges: $shift.attr( 'data-uappt-ranges' ) || '[]',
				// 自訂時段（v2.100.0）那顆鈕會直接帶 data-uappt-label／-short：它的字是
				// JS 填的，從按鈕文字拼回來會變成「09:30 09:30–14:00」。
				label: $shift.attr( 'data-uappt-label' ) || $.trim( $shift.text().replace( /\s+/g, ' ' ) ),
				short: $shift.attr( 'data-uappt-short' ) || $.trim( $shift.find( '.uappt-shift-name' ).text() ),
				color: $shift.attr( 'data-uappt-color' ) || ''
			};
		}

		function $selected() {
			return $form.find( '.uappt-cal-day.is-selected' );
		}

		/**
		 * 選取狀態變了：更新那一列的提示與顯示／隱藏。
		 *
		 * ⚠️ **沒選日子時整列是 hidden，不是 disabled。** disabled 的按鈕仍然看得到，
		 * 使用者會去點它然後納悶為什麼沒反應；整列不存在的話，「要先選日子」這件事
		 * 是靠畫面結構說的，不用一句說明。
		 */
		function syncSelection() {
			var $days  = $selected();
			var n      = $days.length;
			var $pick  = $form.find( '.uappt-shift-pick' );
			var $label = $pick.find( '.uappt-shift-pick-label' );

			$pick.prop( 'hidden', 0 === n );
			// 「其他…」會把那一天帶進「改這一天」，而那張表單一次只能改一天。
			$pick.find( '.uappt-shift-detail' ).prop( 'hidden', 1 !== n );

			if ( 0 === n ) {
				return;
			}

			if ( 1 === n ) {
				$label.text( ( $label.data( 'one' ) || '%s' ).replace( '%s', $days.first().data( 'uapptDate' ) ) );
				// 只選一天時順便把「改這一天」填好——兩條路同時可用，不用先決定
				// 要走哪一條（這也是 v2.90.0 以前那個「畫筆模式」想解決卻解錯的事）。
				fillDayForm( $days.first() );
			} else {
				$label.text( ( $label.data( 'many' ) || '%s' ).replace( '%s', n ) );
			}
		}

		function clearSelection() {
			$form.find( '.uappt-cal-day.is-selected' ).removeClass( 'is-selected' );
			syncSelection();
		}

		// 點一天＝選起來／取消選取。**沒有模式**：點日子永遠是選日子。
		$form.on( 'click', '.uappt-cal-day.is-paintable', function ( e ) {
			e.preventDefault();
			$( this ).toggleClass( 'is-selected' );
			syncSelection();
		} );

		// 點星期標題＝整欄一起選（「每個週日」）。再點一次取消。
		$form.on( 'click', '.uappt-cal-weekday-paint', function () {
			// ⚠️ 欄位索引直接讀 PHP 帶出來的 data-column，不要在這裡放第二份星期
			// 順序表——月曆的格線是週一起算的，抄一份 sun 開頭的就會整欄錯開
			// （v2.85.0 踩過）。
			var index = parseInt( $( this ).data( 'column' ), 10 );
			if ( isNaN( index ) || index < 0 || index > 6 ) {
				return;
			}

			var $cells = $form.find( '.uappt-cal-week' ).map( function () {
				var $cell = $( this ).children().eq( index );
				return $cell.hasClass( 'is-paintable' ) ? $cell.get( 0 ) : null;
			} );

			// 整欄已經全選了就當成「取消整欄」——同一個動作來回切換，不用另外
			// 找一顆「取消」。
			var allOn = $cells.length && ! $cells.filter( function () {
				return ! $( this ).hasClass( 'is-selected' );
			} ).length;

			$cells.each( function () {
				$( this ).toggleClass( 'is-selected', ! allOn );
			} );
			syncSelection();
		} );

		// 選好日子之後點班別：套用到每一天，然後把選取清掉——清掉是刻意的，
		// 不然下一次點班別會再套一次到同一批日子上，而使用者早就忘了還選著。
		$form.on( 'click', '.uappt-shift', function () {
			var item = shiftItem( $( this ) );
			$selected().each( function () {
				paint( $( this ), item );
			} );
			clearSelection();
		} );

		$form.on( 'click', '.uappt-shift-cancel', clearSelection );

		// 「其他…」：把「改這一天」展開再捲過去。它是 <details>，所以要先 open
		// 才捲——不然捲到的是一條收起來的標題列，使用者會以為按錯了。
		$form.on( 'click', '.uappt-shift-detail', function () {
			var $anchor = $( '#uappt-day-editor' );
			if ( ! $anchor.length ) {
				return;
			}
			$anchor.prop( 'open', true );
			$( 'html, body' ).animate( { scrollTop: $anchor.offset().top - 60 }, 200 );
		} );


		// 三顆產生器。資料整包放在 data 屬性裡（見 staff-edit.php 的說明）。
		$form.on( 'click', '.uappt-generator', function () {
			var $btn = $( this );
			var raw  = $btn.attr( 'data-uappt-plan' );

			// 「複製同事」的資料掛在下拉的 option 上，不是按鈕上。
			if ( 'uappt-gen-mate-apply' === this.id ) {
				raw = $form.find( '#uappt-gen-mate option:selected' ).attr( 'data-uappt-plan' );
				if ( ! raw ) {
					return; // 還沒選人就按，什麼都不做比跳一個 alert 安靜。
				}
			}

			try {
				applyPlan( JSON.parse( raw || 'null' ) );
			} catch ( err ) {
				// 解析失敗就什麼都不做：產生器壞掉的最差情況是「要自己塗」，
				// 不該把已經塗好的東西弄亂。
			}
		} );

		// 塗了沒存就離開會掉。這是「整月一次送出」換來的代價，講出來比靜悄悄好。
		$( window ).on( 'beforeunload.uapptPaint', function () {
			if ( $form.find( '.uappt-cal-day.is-dirty' ).length ) {
				return '';
			}
		} );
		// 自己送出時不要再問一次——那正是使用者要的動作。
		$form.on( 'submit', function () {
			$( window ).off( 'beforeunload.uapptPaint' );
		} );

		syncDirty();
	}

	function syncBoundaryMirrors() {
		$( '.uappt-boundary-mirror' ).each( function () {
			var $mirror = $( this );
			var index = $mirror.data( 'mirror' );
			var $source = $( '#uappt-boundary-' + index );
			if ( $source.length ) {
				$mirror.text( $source.val() );
			}
		} );
	}

	/*
	 * 排班方式三選一（v2.93.0，取代原本的「24 小時營業」勾選框）。
	 *
	 * - 卡片裡只顯示跟選到的那一項有關的那一塊（固定班＝樣板；其他＝一句話）。
	 * - 選 24 小時時整張「這個月的班」藏起來——跟原本勾選框的行為一樣。
	 *
	 * ⚠️ 用 `hidden` 屬性，不是 jQuery 的 toggle()：伺服器端印的初始狀態就是
	 * `hidden`，兩邊用同一個機制才不會出現「伺服器說藏、JS 說顯示」各說各話。
	 * 藏起來的樣板欄位**照樣送出**——存下去的就是原本的樣板，切回固定班時還在。
	 */
	function syncHoursVisibility() {
		var $checked = $( 'input[name="schedule_kind"]:checked' );
		if ( ! $( 'input[name="schedule_kind"]' ).length ) {
			return;
		}
		var kind = $checked.length ? String( $checked.val() ) : '';

		$( '.uappt-kind-note' ).each( function () {
			this.hidden = String( $( this ).data( 'uappt-kind' ) ) !== kind;
		} );
		$( '.uappt-hours-section' ).toggle( '24h' !== kind );
	}

	$( function () {
		var $wrap = $( '.uappt-wrap' );
		if ( ! $wrap.length ) {
			return;
		}

		// 1. 時間欄位自動格式化。
		$wrap.on( 'blur', '.uappt-time-input', function () {
			var $input = $( this );
			$input.val( formatTime( $input.val() ) );
			syncBoundaryMirrors();
		} );

		// 2. 每週班表的互動（v2.80.0 改版）。
		//
		// ⚠️ 「上班／公休」**不是獨立欄位**，是推導出來的：這天沒有任何時段就是
		// 公休。所以勾選框沒有 name，切到公休時要真的把欄位清空，不是只隱藏
		// ——隱藏的欄位照樣會被送出，那天就會變成「看起來公休、實際照常營業」。
		// 原值先存進 data 裡，勾回來就還原，誤觸不會弄丟班表。
		function syncDayRow( $row ) {
			var working = $row.find( '.uappt-day-working' ).is( ':checked' );
			$row.toggleClass( 'is-off', ! working );
			var l10n = ( window.UAPPT_Admin_Staff && window.UAPPT_Admin_Staff.i18n ) || {};
			$row.find( '.uappt-week-state' ).text( working ? ( l10n.working || '上班' ) : ( l10n.closed || '公休' ) );

			if ( ! working ) {
				$row.find( '.uappt-time-input' ).each( function () {
					var $i = $( this );
					if ( $i.val() ) {
						$i.data( 'uapptKept', $i.val() );
					}
					$i.val( '' );
				} );
			} else {
				$row.find( '.uappt-time-input' ).each( function () {
					var $i = $( this );
					if ( ! $i.val() && $i.data( 'uapptKept' ) ) {
						$i.val( $i.data( 'uapptKept' ) );
					}
				} );
			}

			syncCrossMidnight( $row );
		}

		// 跨午夜徽章：結束早於開始就是上到隔天。這個規則以前只寫在說明文字裡，
		// 要讀 171 個字才知道；現在一邊打字一邊看得到。
		function syncCrossMidnight( $scope ) {
			$scope.find( '.uappt-range' ).each( function () {
				var $r      = $( this );
				var $inputs = $r.find( '.uappt-time-input' );
				var start   = formatTime( $inputs.eq( 0 ).val() );
				var end     = formatTime( $inputs.eq( 1 ).val() );
				var cross   = start && end && end <= start;
				$r.find( '.uappt-range-cross' ).prop( 'hidden', ! cross );
			} );
		}

		var $week = $( '.uappt-week' );

		$week.on( 'change', '.uappt-day-working', function () {
			syncDayRow( $( this ).closest( '.uappt-week-row' ) );
		} );

		// 「＋加一段」只是把預先印好、藏起來的那一段顯示出來（三段一律在 DOM 裡，
		// 沒有 JS 時全部看得到，不會比改版前退步）。
		$week.on( 'click', '.uappt-range-add', function () {
			var $next = $( this ).closest( '.uappt-week-row' ).find( '.uappt-range.is-extra' ).first();
			if ( $next.length ) {
				$next.removeClass( 'is-extra' ).find( '.uappt-time-input' ).first().trigger( 'focus' );
			}
		} );

		$week.on( 'click', '.uappt-range-remove', function () {
			var $r   = $( this ).closest( '.uappt-range' );
			var $row = $r.closest( '.uappt-week-row' );
			$r.find( '.uappt-time-input' ).val( '' ).removeData( 'uapptKept' );
			// 第一段不收起來：它是「這天上不上班」的入口，收掉就沒東西可按了。
			if ( $row.find( '.uappt-range' ).index( $r ) > 0 ) {
				$r.addClass( 'is-extra' );
			}
			syncCrossMidnight( $row );
		} );

		$week.on( 'input', '.uappt-time-input', function () {
			var $row = $( this ).closest( '.uappt-week-row' );

			// 打了時間就自動勾「上班」。不做的話會出現「這一列寫著公休，但我
			// 明明填了 09:00–21:00」——存檔結果是對的（伺服器從時段推導），
			// 但畫面在騙人，而且要重新整理才會變回來。
			if ( $( this ).val() && ! $row.find( '.uappt-day-working' ).is( ':checked' ) ) {
				$row.find( '.uappt-day-working' ).prop( 'checked', true );
				syncDayRow( $row );
				return;
			}

			syncCrossMidnight( $row );
		} );

		// 套用：一律以「週一」那一列為準。以前是「第一列」，而第一列是週一還是
		// 週日要看 $day_labels 怎麼排——講「週一」才不會每次都要回去確認。
		function applyFrom( $source, $targets ) {
			if ( ! $source.length ) {
				return;
			}
			var values = [];
			$source.find( '.uappt-time-input' ).each( function ( i ) {
				values[ i ] = $( this ).val();
			} );
			var working = $source.find( '.uappt-day-working' ).is( ':checked' );

			$targets.not( $source ).each( function () {
				var $t = $( this );
				$t.find( '.uappt-time-input' ).each( function ( i ) {
					$( this ).val( values[ i ] || '' ).removeData( 'uapptKept' );
				} );
				$t.find( '.uappt-range' ).each( function ( i ) {
					var filled = $( this ).find( '.uappt-time-input' ).filter( function () {
						return !! $( this ).val();
					} ).length > 0;
					$( this ).toggleClass( 'is-extra', i > 0 && ! filled );
				} );
				$t.find( '.uappt-day-working' ).prop( 'checked', working );
				syncDayRow( $t );
			} );
		}

		var weekdayKeys = [ 'mon', 'tue', 'wed', 'thu', 'fri' ];

		$( '#uappt-apply-weekdays' ).on( 'click', function () {
			var $rows = $week.find( '.uappt-week-row' ).filter( function () {
				return weekdayKeys.indexOf( $( this ).data( 'day' ) ) !== -1;
			} );
			applyFrom( $week.find( '.uappt-week-row[data-day="mon"]' ), $rows );
		} );

		$( '#uappt-apply-all' ).on( 'click', function () {
			applyFrom( $week.find( '.uappt-week-row[data-day="mon"]' ), $week.find( '.uappt-week-row' ) );
		} );

		// 3. 鍵盤可達：格子不是連結，自己補 Enter／Space。
		//
		// ⚠️ v2.91.0 起**點格子＝選取**（見 initMonthPaint 的說明），所以這裡不再
		// 有「點一天就填單日表單並捲過去」的 handler——那件事改由「只選了一天」
		// 時順便做（fillDayForm()），而捲動只在按下「其他…」時才發生。每選一天就
		// 把畫面捲走，連選五天會跳五次。
		$( document ).on( 'keydown', '.uappt-cal-day.is-paintable', function ( e ) {
			if ( 13 === e.which || 32 === e.which ) {
				e.preventDefault();
				$( this ).trigger( 'click' );
			}
		} );

		// 4. 分界時間鏡射（輸入中即時更新，不必等離開焦點）。
		$wrap.on( 'input', '.uappt-boundary-input', syncBoundaryMirrors );
		syncBoundaryMirrors();

		// 5. 排班方式三選一：切換樣板與說明的顯示。
		$wrap.on( 'change', 'input[name="schedule_kind"]', syncHoursVisibility );
		syncHoursVisibility();

		// 6. 人員照片選擇器。
		initPhotoField();

		// 7. 設定卡片的「未儲存」標示。
		//
		// ⚠️ **收合起來不能把改到一半的東西藏掉。** 一張卡片一顆鈕之後，最容易出事
		// 的情境就是「改了基本資料 → 沒按儲存 → 把卡片收起來 → 以為存過了」。標題列
		// 的徽章讓這件事在收合狀態下仍然看得見。
		//
		// 只綁 .uappt-card-form（四張設定卡片）：月曆那張卡片有自己的「未儲存 N 天」，
		// 而且它的 hidden input 是用 JS 改值的（不會觸發 change），綁了也抓不到。
		$wrap.on( 'input change', '.uappt-card-form :input', function () {
			$( this ).closest( '.uappt-card' ).find( '> .uappt-card-head .uappt-card-dirty' ).prop( 'hidden', false );
		} );

		// 送出就是要存了，徽章不該留著——轉址後整頁重畫，但送出到轉址之間有一段
		// 空窗，留著會看起來像「按了沒反應」。
		$wrap.on( 'submit', '.uappt-card-form', function () {
			$( this ).closest( '.uappt-card' ).find( '> .uappt-card-head .uappt-card-dirty' ).prop( 'hidden', true );
		} );

		// 8. 月曆塗抹：選畫筆、點格子、整月一次送出。
		initMonthPaint( $wrap );

		// 9. 「改這一天」的類型 ←→ 時段欄位連動。
		//
		// ⚠️ **這段原本是 staff-edit.php 裡的一段 inline script，用原生
		// addEventListener('change') 綁的，而點月曆的程式碼（上面第 3 段）是用
		// jQuery 的 .trigger('change') 切類型。jQuery 3.7.1 的 trigger 只會跑它
		// 自己註冊的 handler——leverageNative 只套用在 click／focus／blur 三種事
		// 件上，change 不在裡面，所以原生 handler 根本不會被呼叫。** 症狀是「點
		// 一天，時段都帶進表單了，但『當天時段』那一列還是收著」，看起來像月曆
		// 壞了，其實是兩種事件系統沒接上。兩邊都走 jQuery 就不會再有這個落差。
		//
		// 另外：那一列的 inline style="display:none" 一起拿掉了，改由這裡在初始化
		// 時收合。JS 掛掉時要留在「可用」的那一側——寫死 display:none 的話，JS 一
		// 壞掉那幾個時段欄位就永遠打不開。
		function syncOverrideHoursRow() {
			var type = $( '.uappt-override-type:checked' ).val();
			$( '.uappt-override-hours-row' ).toggle( 'hours' === type );
		}
		$wrap.on( 'change', '.uappt-override-type', syncOverrideHoursRow );
		syncOverrideHoursRow();

		// 10. 班別下拉：選一個就把時間欄位填好。
		//
		// 這只是**填欄位的捷徑**，不是另一條寫入路徑：填完之後送出的仍然是那張
		// 表單原本的時間欄位，走 admin-post + nonce（設計紀律 #8）。班別本身不會
		// 被送出（下拉沒有 name），班表存的永遠是具體時段。
		$wrap.on( 'change', '.uappt-preset-pick', function () {
			var $select = $( this );
			var $option = $select.find( 'option:selected' );
			var target  = $select.data( 'uapptPresetTarget' );
			var $form   = $select.closest( 'form' );

			if ( ! target || ! $form.length || '' === $select.val() ) {
				return; // 「自己填時間」不動既有的值，不然誤觸就把填好的清掉了。
			}

			var ranges = $option.data( 'uapptRanges' );
			// jQuery 會把 data-* 的 JSON 自動解析；保險起見兩種都接（跟點月曆
			// 那段同樣的理由）。
			if ( typeof ranges === 'string' ) {
				try {
					ranges = JSON.parse( ranges );
				} catch ( err ) {
					ranges = [];
				}
			}
			if ( ! $.isArray( ranges ) ) {
				return;
			}

			// 先切類型再填欄位：類型切過去才會把時段那一列展開，順序反了的話會先
			// 往看不見的欄位裡寫值。
			if ( '1' === String( $select.data( 'uapptPresetSetType' ) ) ) {
				$form.find( '.uappt-override-type[value="hours"]' )
					.prop( 'checked', true )
					.trigger( 'change' );
			}

			// ⚠️ 填不滿的欄位要**清空**，不是留著。留著的話從「全天（一段）」切到
			// 「早班＋晚班（兩段）」再切回來，第二段會是上一個班別的殘值——而那
			// 一段照樣會被送出，排出去的班就多了一段沒人要的時間。
			$form.find( '[name^="' + target + '"]' ).each( function ( i ) {
				var pair = ranges[ Math.floor( i / 2 ) ];
				$( this ).val( pair ? ( i % 2 === 0 ? pair[ 0 ] : pair[ 1 ] ) : '' );
			} );
		} );
	} );

	/**
	 * 人員照片欄位：開啟 WordPress 媒體選擇器，選完只把附件 ID 寫進 hidden
	 * input，圖片本身不上傳到別的地方——存檔走的是一般表單送出，跟其他欄位
	 * 同一條路，不需要額外的 AJAX。
	 *
	 * wp.media 由 wp_enqueue_media() 提供，而那支只在新增／編輯頁載入
	 * （見 UAPPT_Admin::enqueue_assets()），所以這裡先確認物件存在再綁定，
	 * 避免在列表頁丟出 ReferenceError 把同一支檔案裡其他功能一起打斷。
	 */
	function initPhotoField() {
		var $field = $( '.uappt-photo-field' );
		if ( ! $field.length || 'undefined' === typeof wp || ! wp.media ) {
			return;
		}

		var i18n = ( window.UAPPT_Admin_Staff && window.UAPPT_Admin_Staff.i18n ) || {};
		var $input = $field.find( '#uappt-photo-id' );
		var $preview = $field.find( '.uappt-photo-preview' );
		var $selectBtn = $field.find( '.uappt-photo-select' );
		var $removeBtn = $field.find( '.uappt-photo-remove' );
		var frame;

		function render( id, url ) {
			$input.val( id || 0 );
			$preview.html( url ? $( '<img />' ).attr( 'src', url ) : '' );
			$selectBtn.text( url ? ( i18n.photo_change || '更換照片' ) : ( i18n.photo_select || '選擇照片' ) );
			$removeBtn.toggle( !! url );
		}

		$selectBtn.on( 'click', function () {
			if ( ! frame ) {
				frame = wp.media( {
					title: i18n.photo_title || '選擇人員照片',
					button: { text: i18n.photo_button || '使用這張照片' },
					library: { type: 'image' },
					multiple: false
				} );

				frame.on( 'select', function () {
					var attachment = frame.state().get( 'selection' ).first().toJSON();
					// 優先用縮圖尺寸當預覽，沒有產生縮圖（例如很小的圖或 SVG）
					// 時退回原圖，不要讓預覽變成破圖。
					var url = ( attachment.sizes && attachment.sizes.thumbnail )
						? attachment.sizes.thumbnail.url
						: attachment.url;
					render( attachment.id, url );
				} );
			}
			frame.open();
		} );

		$removeBtn.on( 'click', function () {
			render( 0, '' );
		} );
	}
} )( jQuery );
