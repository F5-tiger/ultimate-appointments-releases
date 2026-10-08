/**
 * 全店月排班表（v2.94.0）：人員 × 日期一張表，**先選格子、再選班別**。
 *
 * 操作跟人員編輯頁的月曆（admin-staff.js 的 initMonthPaint()）是同一套，差別只有：
 *
 * - 格子跨人：點人名選他整個月、點日期選那天所有人。
 * - 底部「上班人數」跟著改。
 * - ⚠️ **送出時才把改過的格子打包成一個 JSON 欄位**（`roster`），格子本身不帶任何
 *   name 的 input。理由是 PHP 的 max_input_vars（預設 1000）：全店表每格兩個欄位，
 *   20 人就超過了，而超過的部分 PHP 會**靜默丟掉**。見 UAPPT_Admin::handle_save_roster()。
 *
 * v2.95.0 加上：
 *
 * - 兩顆產生器（複製上個月／套用固定班樣板），走的是**同一支 paint()**——產生出來
 *   的結果一樣是未儲存、一樣能還原、一樣只送改過的格子。
 * - 整月／一週切換。手機預設一週：31 欄在 375px 上只看得到三四天，而且要一直橫捲。
 *   ⚠️ 整張月表照樣印出來，切週只是藏欄（CSS 看 data-show-week），**不換頁**——
 *   改過的格子留在畫面上，最後一次存。換頁的話每切一週都要先存一次。
 *
 * v2.96.0 加上待審申請（虛線框的格子）：選起來按「接受申請」／「不接受」。
 *
 * ⚠️ **「這天排成什麼」跟「這筆申請准不准」是兩件事，各記各的**，送出時也是兩個
 * 欄位（`roster` 與 `roster_requests`）：
 *
 * - 接受＝照申請排上去。所以接受會**蓋掉**那一格排過的班，之後在同一格再排班，
 *   接受也會被取消——兩個互相矛盾的指示不能同時存在。
 * - 不接受＝只是不准，那天要排什麼另外決定。所以不接受跟排班**可以並存**
 *   （「你申請的早班不准，改排晚班」）。
 * - 在有申請的格子上直接排班、沒按接受也沒按不接受：申請**留著**等另外處理
 *   （使用者決定，見 docs/staff-roster-plan.md 的 Q4）。
 *
 * 這段只動畫面，一行都不寫入——寫入走 admin-post ＋ nonce（設計紀律 #8）。
 *
 * 不用 jQuery：v2.88.x 踩過 jQuery 的 `.trigger()` 不會觸發原生 addEventListener
 * 的坑，這支從頭到尾只用原生事件，就不會有兩套事件系統各說各話。
 */
( function () {
	'use strict';

	var form = document.querySelector( '.uappt-roster-form' );
	if ( ! form ) {
		return;
	}

	var table   = form.querySelector( '.uappt-roster' );
	var payload = form.querySelector( '.uappt-roster-payload' );
	var reqLoad = form.querySelector( '.uappt-roster-requests-payload' );
	var pick    = form.querySelector( '.uappt-shift-pick' );
	var label   = form.querySelector( '.uappt-shift-pick-label' );
	var detail  = form.querySelector( '.uappt-roster-detail' );
	var dirtyEl = form.querySelector( '.uappt-roster-dirty' );
	var reset   = form.querySelector( '.uappt-roster-reset' );
	var toolbar = form.querySelector( '.uappt-roster-toolbar' );
	var weeknav = form.querySelector( '.uappt-roster-weeknav' );
	var weekTx  = form.querySelector( '.uappt-roster-week-label' );
	var weekIn  = form.querySelector( '.uappt-roster-week-input' );

	if ( ! table || ! payload ) {
		return;
	}

	function all( selector, root ) {
		return Array.prototype.slice.call( ( root || table ).querySelectorAll( selector ) );
	}

	/**
	 * 這一格現在看不看得到（週檢視時，別週的欄是藏起來的）。
	 *
	 * 點人名、方向鍵都只該作用在**看得到**的格子上：週檢視下點人名卻選了整個月，
	 * 動作列會說「已選 29 格」，而畫面上只看得到 7 格。
	 */
	function isVisible( cell ) {
		if ( 'week' !== table.getAttribute( 'data-view' ) ) {
			return true;
		}
		return cell.getAttribute( 'data-week' ) === table.getAttribute( 'data-show-week' );
	}

	var STATE_CLASSES = [ 'is-open', 'is-closed', 'is-unset', 'is-shop-closed' ];

	/**
	 * 班別顏色（v2.99.0）：格子上的 `uappt-shift-c-{色}`，沒有就是空字串。
	 */
	function colorOf( cell ) {
		var m = cell.className.match( /(?:^|\s)uappt-shift-c-(\S+)/ );
		return m ? m[ 1 ] : '';
	}

	function setColor( cell, color ) {
		cell.className = cell.className.replace( /(?:^|\s)(?:has-shift|uappt-shift-c-\S+)/g, '' ).trim();
		if ( color ) {
			cell.classList.add( 'has-shift', 'uappt-shift-c-' + color );
		}
	}

	// 原狀快照。還原時要把狀態、標籤、提示全部放回去。
	all( '.uappt-roster-cell.is-paintable' ).forEach( function ( cell ) {
		var text = cell.querySelector( '.uappt-roster-label' );
		var req  = cell.getAttribute( 'data-request' );
		try {
			cell.uapptRequest = req ? JSON.parse( req ) : null;
		} catch ( err ) {
			cell.uapptRequest = null; // 壞掉的就當沒有申請：最差是要去排班申請頁處理。
		}
		cell.uapptOrigin = {
			type: cell.getAttribute( 'data-type' ),
			ranges: cell.getAttribute( 'data-ranges' ) || '[]',
			label: text ? text.textContent : '',
			title: cell.getAttribute( 'title' ) || '',
			color: colorOf( cell ),
			state: STATE_CLASSES.filter( function ( c ) {
				return cell.classList.contains( c );
			} )[ 0 ] || 'is-unset'
		};
	} );

	/**
	 * 兩組時段是不是同一份。字串可能空白不同，解析後重新序列化才比得準。
	 */
	function sameRanges( a, b ) {
		function norm( v ) {
			try {
				var parsed = JSON.parse( v || '[]' );
				return JSON.stringify( Array.isArray( parsed ) ? parsed : [] );
			} catch ( err ) {
				return '[]';
			}
		}
		return norm( a ) === norm( b );
	}

	function setState( cell, state ) {
		STATE_CLASSES.forEach( function ( c ) {
			cell.classList.remove( c );
		} );
		cell.classList.add( state );
	}

	function setLabel( cell, text, long ) {
		var span = cell.querySelector( '.uappt-roster-label' );
		if ( span ) {
			span.textContent = text;
		}
		var head = cell.getAttribute( 'data-head' ) || '';
		cell.setAttribute( 'title', ( head + ' ' + ( long || text ) ).trim() );
	}

	function clean( cell ) {
		var o = cell.uapptOrigin;
		cell.classList.remove( 'is-dirty' );
		cell.setAttribute( 'data-type', o.type );
		cell.setAttribute( 'data-ranges', o.ranges );
		setState( cell, o.state );
		setColor( cell, o.color );
		var span = cell.querySelector( '.uappt-roster-label' );
		if ( span ) {
			span.textContent = o.label;
		}
		cell.setAttribute( 'title', o.title );
	}

	/**
	 * 把一格排成某個班。
	 *
	 * ⚠️ **排成「跟原本一模一樣」不算改過**——理由同 initMonthPaint() 的 paint()：
	 * 「未儲存 N 格」要回答的是「按下去會改幾格」，不是「我碰過幾格」。順帶讓誤觸
	 * 可以自己復原：排錯了再排回原本那個班，那一格就乾淨了。
	 *
	 * @param {Element} cell 格子。
	 * @param {Object}  item { type, ranges（JSON 字串）, short, long }
	 */
	function paint( cell, item ) {
		if ( ! cell.classList.contains( 'is-paintable' ) ) {
			return;
		}
		// 已經「接受申請」的格子又被排了別的班：接受取消（兩個指示互相矛盾）。
		// 「不接受」留著——不准跟另外排班可以並存。
		if ( cell.classList.contains( 'is-accepted' ) ) {
			undoAccept( cell );
		}
		var o = cell.uapptOrigin;
		if ( item.type === o.type && sameRanges( item.ranges, o.ranges ) ) {
			clean( cell );
			return;
		}

		cell.classList.add( 'is-dirty' );
		cell.setAttribute( 'data-type', item.type );
		cell.setAttribute( 'data-ranges', item.ranges );

		setColor( cell, 'hours' === item.type ? ( item.color || '' ) : '' );
		if ( 'hours' === item.type ) {
			setState( cell, 'is-open' );
		} else if ( 'clear' === item.type ) {
			// 清掉之後是「未排班」還是「店休」，看那天店有沒有開。
			setState( cell, cell.hasAttribute( 'data-shop' ) ? 'is-shop-closed' : 'is-unset' );
		} else {
			setState( cell, 'is-closed' );
		}
		setLabel( cell, 'clear' === item.type ? '' : item.short, item.long );
	}

	/**
	 * 接受這一格的申請：格子先照申請的樣子顯示（上班人數也跟著算），按儲存才真的
	 * 核准。排過的班會被蓋掉——接受的意思就是「照員工申請的排」。
	 */
	function accept( cell ) {
		var req = cell.uapptRequest;
		if ( ! req || cell.classList.contains( 'is-accepted' ) ) {
			return;
		}
		clean( cell );
		cell.classList.remove( 'is-rejected' );
		cell.classList.add( 'is-accepted' );
		cell.setAttribute( 'data-type', req.type );
		setState( cell, 'hours' === req.type ? 'is-open' : 'is-closed' );
		setColor( cell, 'hours' === req.type ? ( req.color || '' ) : '' );
		setLabel( cell, req.short, req.long );
	}

	function undoAccept( cell ) {
		cell.classList.remove( 'is-accepted' );
		clean( cell );
	}

	function reject( cell ) {
		if ( ! cell.uapptRequest ) {
			return;
		}
		if ( cell.classList.contains( 'is-accepted' ) ) {
			undoAccept( cell );
		}
		cell.classList.add( 'is-rejected' );
	}

	function clearDecision( cell ) {
		if ( cell.classList.contains( 'is-accepted' ) ) {
			undoAccept( cell );
		}
		cell.classList.remove( 'is-rejected' );
	}

	/**
	 * 底部「上班人數」：每一欄有幾格是上班（含 24 小時人員的全天）。
	 * 店休日 0 人印「—」，營業日 0 人標出來——跟 PHP 印的初始值同一條規則。
	 */
	function syncCounts() {
		all( '.uappt-roster-count' ).forEach( function ( foot ) {
			var col = foot.getAttribute( 'data-col' );
			var n   = all( '.uappt-roster-cell[data-col="' + col + '"]' ).filter( function ( cell ) {
				var t = cell.getAttribute( 'data-type' );
				return 'hours' === t || 'allday' === t;
			} ).length;
			var shop = foot.hasAttribute( 'data-shop' );
			foot.textContent = ( 0 === n && shop ) ? '—' : String( n );
			foot.classList.toggle( 'is-empty', 0 === n && ! shop );
		} );
	}

	function syncDirty() {
		// 「未儲存 N 格」把接受／不接受也算進去：它們一樣要按儲存才會生效。
		var n = all( '.uappt-roster-cell.is-dirty, .uappt-roster-cell.is-accepted, .uappt-roster-cell.is-rejected' ).length;
		if ( dirtyEl ) {
			dirtyEl.textContent = ( dirtyEl.getAttribute( 'data-template' ) || '%d' ).replace( '%d', n );
			dirtyEl.hidden = 0 === n;
		}
		if ( reset ) {
			reset.hidden = 0 === n;
		}
		syncCounts();
	}

	function selected() {
		return all( '.uappt-roster-cell.is-selected' );
	}

	/**
	 * 選取變了：更新動作列。
	 *
	 * ⚠️ **沒選格子時整列是 hidden，不是 disabled**——理由同人員編輯頁：disabled 的
	 * 按鈕看得到、點了沒反應，使用者會納悶；整列不存在的話「要先選格子」是畫面
	 * 結構在說，不用一句說明。
	 */
	function syncSelection() {
		var cells = selected();
		var n     = cells.length;

		if ( pick ) {
			pick.hidden = 0 === n;
		}
		if ( detail ) {
			detail.hidden = 1 !== n;
		}

		// 選到的格子裡有幾筆申請。數字寫在鈕上：選了一整列 30 格、其中 6 格有申請，
		// 按下去處理的是那 6 筆——鈕上不寫的話，看起來像是要對 30 格做什麼。
		var withReq = cells.filter( function ( cell ) {
			return !! cell.uapptRequest;
		} ).length;
		all( '.uappt-roster-decide', form ).forEach( function ( btn ) {
			btn.hidden = 0 === withReq;
			btn.textContent = ( btn.getAttribute( 'data-template' ) || '%d' ).replace( '%d', withReq );
		} );
		if ( 0 === n || ! label ) {
			return;
		}

		if ( 1 === n ) {
			label.textContent = ( label.getAttribute( 'data-one' ) || '%s' ).replace( '%s', cells[ 0 ].getAttribute( 'data-head' ) || '' );
			var row = cells[ 0 ].closest( 'tr' );
			if ( detail && row ) {
				detail.setAttribute( 'href', row.getAttribute( 'data-edit' ) || '#' );
			}
		} else {
			label.textContent = ( label.getAttribute( 'data-many' ) || '%s' ).replace( '%s', n );
		}
	}

	function clearSelection() {
		selected().forEach( function ( cell ) {
			cell.classList.remove( 'is-selected' );
		} );
		syncSelection();
	}

	/**
	 * 一整排一起選／取消。整排已經全選了就當成「取消整排」——同一個動作來回切換，
	 * 不用另外找一顆「取消」。
	 */
	function toggleGroup( cells ) {
		if ( ! cells.length ) {
			return;
		}
		var allOn = cells.every( function ( cell ) {
			return cell.classList.contains( 'is-selected' );
		} );
		cells.forEach( function ( cell ) {
			cell.classList.toggle( 'is-selected', ! allOn );
		} );
		syncSelection();
	}

	table.addEventListener( 'click', function ( e ) {
		var cell = e.target.closest( '.uappt-roster-cell.is-paintable' );
		if ( cell ) {
			cell.classList.toggle( 'is-selected' );
			syncSelection();
			return;
		}

		var rowBtn = e.target.closest( '.uappt-roster-row' );
		if ( rowBtn ) {
			toggleGroup( all( '.uappt-roster-cell.is-paintable', rowBtn.closest( 'tr' ) ).filter( isVisible ) );
			return;
		}

		var colBtn = e.target.closest( '.uappt-roster-col' );
		if ( colBtn ) {
			// 欄位索引直接讀 PHP 帶出來的 data-col，不在這裡自己數第幾欄。
			toggleGroup( all( '.uappt-roster-cell.is-paintable[data-col="' + colBtn.getAttribute( 'data-col' ) + '"]' ) );
		}
	} );

	// 鍵盤：Enter／空白鍵＝點一下；方向鍵在格子之間移動。格子是 role="button"
	// ＋ tabindex，不補這段的話鍵盤使用者選得到卻按不下去。
	table.addEventListener( 'keydown', function ( e ) {
		var cell = e.target.closest( '.uappt-roster-cell' );
		if ( ! cell ) {
			return;
		}

		if ( 'Enter' === e.key || ' ' === e.key ) {
			e.preventDefault();
			cell.click();
			return;
		}

		var row = cell.closest( 'tr' );
		var col = parseInt( cell.getAttribute( 'data-col' ), 10 );
		var target = null;

		if ( 'ArrowLeft' === e.key || 'ArrowRight' === e.key ) {
			var step  = 'ArrowLeft' === e.key ? -1 : 1;
			var cells = all( '.uappt-roster-cell.is-paintable', row ).filter( isVisible );
			var index = cells.indexOf( cell );
			target = cells[ index + step ] || null;
		} else if ( 'ArrowUp' === e.key || 'ArrowDown' === e.key ) {
			var sibling = 'ArrowUp' === e.key ? row.previousElementSibling : row.nextElementSibling;
			while ( sibling && ! target ) {
				target = sibling.querySelector( '.uappt-roster-cell.is-paintable[data-col="' + col + '"]' );
				sibling = 'ArrowUp' === e.key ? sibling.previousElementSibling : sibling.nextElementSibling;
			}
		}

		if ( target ) {
			e.preventDefault();
			target.focus();
		}
	} );

	// 選好格子之後點班別：套到每一格，然後把選取清掉——不清的話下一次點班別會
	// 再套一次到同一批格子上，而使用者早就忘了還選著。
	if ( pick ) {
		pick.addEventListener( 'click', function ( e ) {
			var decide = e.target.closest( '.uappt-roster-decide' );
			if ( decide ) {
				var fn = 'approve' === decide.getAttribute( 'data-decision' ) ? accept : reject;
				selected().forEach( function ( cell ) {
					fn( cell );
				} );
				clearSelection();
				syncDirty();
				return;
			}

			var shift = e.target.closest( '.uappt-shift' );
			if ( shift ) {
				var nameEl = shift.querySelector( '.uappt-shift-name' );
				var item   = {
					type: shift.getAttribute( 'data-uappt-shift' ),
					ranges: shift.getAttribute( 'data-uappt-ranges' ) || '[]',
					// 自訂時段（v2.100.0）那顆鈕直接帶 data-uappt-short，理由同 admin-staff.js。
					short: shift.getAttribute( 'data-uappt-short' ) || ( nameEl ? nameEl.textContent.trim() : '' ),
					long: shift.getAttribute( 'data-uappt-long' ) || ( nameEl ? nameEl.textContent.trim() : '' ),
					color: shift.getAttribute( 'data-uappt-color' ) || ''
				};
				selected().forEach( function ( cell ) {
					paint( cell, item );
				} );
				clearSelection();
				syncDirty();
				return;
			}
			if ( e.target.closest( '.uappt-shift-cancel' ) ) {
				clearSelection();
			}
		} );
	}

	/**
	 * 產生器：把每一列帶的那份計畫（PHP 印在 <tr> 的 data-prev／data-template）
	 * 套上去。沒帶那份資料的列不動——上個月沒排班的人、彈性班與 24 小時人員的樣板，
	 * 理由見 UAPPT_Admin::render_roster_page()。
	 *
	 * 標籤直接用 PHP 算好的（l／s），不在這裡找「最像的班別」——理由同
	 * UAPPT_Staff::plan_label() 的 ⚠️：對不上的時段掛錯班別名，畫面會騙人。
	 */
	function applyGenerator( key ) {
		all( 'tbody tr' ).forEach( function ( row ) {
			var raw = row.getAttribute( 'data-' + key );
			if ( ! raw ) {
				return;
			}
			var plan;
			try {
				plan = JSON.parse( raw );
			} catch ( err ) {
				return; // 壞掉的最差情況是「要自己排」，不該把已經排好的弄亂。
			}
			all( '.uappt-roster-cell.is-paintable', row ).forEach( function ( cell ) {
				var day = plan[ cell.getAttribute( 'data-date' ) ];
				if ( ! day ) {
					return;
				}
				paint( cell, {
					type: day.t,
					ranges: JSON.stringify( day.r || [] ),
					short: day.s,
					long: day.l,
					color: day.c || ''
				} );
			} );
		} );
		clearSelection();
		syncDirty();
	}

	/**
	 * 切換整月／一週，以及週檢視要看哪一週。
	 *
	 * 切週時把選取清掉：選了第一週的格子、切到第二週，動作列還寫著「已選 3 格」，
	 * 而那 3 格在畫面上看不到——點班別下去會改到看不見的地方。
	 */
	var weeks = parseInt( table.getAttribute( 'data-weeks' ), 10 ) || 1;

	function showWeek( n ) {
		n = Math.max( 0, Math.min( weeks - 1, n ) );
		table.setAttribute( 'data-show-week', String( n ) );

		var heads = all( 'thead th[data-week="' + n + '"]' );
		if ( weekTx && heads.length ) {
			var first = heads[ 0 ].getAttribute( 'data-short' );
			var last  = heads[ heads.length - 1 ].getAttribute( 'data-short' );
			weekTx.textContent = first === last ? first : first + '–' + last;
		}
		all( '.uappt-roster-week-step', form ).forEach( function ( btn ) {
			var step = parseInt( btn.getAttribute( 'data-step' ), 10 );
			btn.disabled = ( n + step < 0 ) || ( n + step > weeks - 1 );
		} );
		syncWeekInput();
	}

	function setView( view, remember ) {
		table.setAttribute( 'data-view', view );
		all( '.uappt-roster-view-btn', form ).forEach( function ( btn ) {
			btn.setAttribute( 'aria-pressed', btn.getAttribute( 'data-view' ) === view ? 'true' : 'false' );
		} );
		if ( weeknav ) {
			weeknav.hidden = 'week' !== view;
		}
		// 只記使用者自己按的，不記預設值；手機上按的也不記（手機一律從一週開始，記了
		// 也不會用到，反而會讓同一台平板轉橫向變寬時跳成整月）。
		if ( remember && ! ( window.matchMedia && window.matchMedia( '(max-width: 782px)' ).matches ) ) {
			try {
				window.localStorage.setItem( 'uapptRosterView', view );
			} catch ( err ) {
				// 私密視窗等情況拿不到 localStorage：只是下次不記得，不影響這次。
			}
		}
		syncWeekInput();
	}

	function syncWeekInput() {
		if ( weekIn ) {
			weekIn.value = 'week' === table.getAttribute( 'data-view' ) ? table.getAttribute( 'data-show-week' ) : '';
		}
	}

	if ( toolbar ) {
		toolbar.hidden = false;
		toolbar.addEventListener( 'click', function ( e ) {
			var gen = e.target.closest( '.uappt-roster-gen' );
			if ( gen ) {
				applyGenerator( gen.getAttribute( 'data-gen' ) );
				return;
			}
			var viewBtn = e.target.closest( '.uappt-roster-view-btn' );
			if ( viewBtn ) {
				clearSelection();
				setView( viewBtn.getAttribute( 'data-view' ), true );
				return;
			}
			var step = e.target.closest( '.uappt-roster-week-step' );
			if ( step ) {
				clearSelection();
				showWeek( parseInt( table.getAttribute( 'data-show-week' ), 10 ) + parseInt( step.getAttribute( 'data-step' ), 10 ) );
			}
		} );
	}

	// 一進來用哪種檢視：
	// - 手機**一律從一週開始**（v2.101.0，使用者決定）：上次在手機上按過「整月」也不沿用
	//   ——31 欄在 375px 上就是使用者回報的「密密麻麻」，想看整月每次自己按一下。
	// - 桌機：使用者自己選過就照他選的，沒選過就整月。
	// ⚠️ 兩邊都能切換，只有一進來的樣子不同（寬度是這個斷點特有的理由）。
	var savedView = null;
	try {
		savedView = window.localStorage.getItem( 'uapptRosterView' );
	} catch ( err ) {
		savedView = null;
	}
	var narrow = window.matchMedia && window.matchMedia( '(max-width: 782px)' ).matches;
	showWeek( parseInt( table.getAttribute( 'data-show-week' ), 10 ) || 0 );
	if ( narrow ) {
		setView( 'week' );
	} else {
		setView( 'week' === savedView || 'month' === savedView ? savedView : 'month' );
	}

	if ( reset ) {
		reset.addEventListener( 'click', function () {
			all( '.uappt-roster-cell.is-accepted, .uappt-roster-cell.is-rejected' ).forEach( clearDecision );
			all( '.uappt-roster-cell.is-dirty' ).forEach( clean );
			syncDirty();
		} );
	}

	// 送出：把改過的格子打包成 { 人員 ID: { 日期: { type, ranges } } }。
	form.addEventListener( 'submit', function () {
		var out = {};
		all( '.uappt-roster-cell.is-dirty' ).forEach( function ( cell ) {
			var staff = cell.closest( 'tr' ).getAttribute( 'data-staff' );
			var ranges;
			try {
				ranges = JSON.parse( cell.getAttribute( 'data-ranges' ) || '[]' );
			} catch ( err ) {
				ranges = [];
			}
			out[ staff ] = out[ staff ] || {};
			out[ staff ][ cell.getAttribute( 'data-date' ) ] = {
				type: cell.getAttribute( 'data-type' ),
				ranges: ranges
			};
		} );
		payload.value = JSON.stringify( out );

		if ( reqLoad ) {
			var decisions = {};
			all( '.uappt-roster-cell.is-accepted, .uappt-roster-cell.is-rejected' ).forEach( function ( cell ) {
				if ( cell.uapptRequest ) {
					decisions[ cell.uapptRequest.id ] = cell.classList.contains( 'is-accepted' ) ? 'approve' : 'reject';
				}
			} );
			reqLoad.value = JSON.stringify( decisions );
		}
		window.removeEventListener( 'beforeunload', warnUnsaved );
	} );

	// 排了沒存就離開會掉。這是「整張一次送出」換來的代價，講出來比靜悄悄好。
	function warnUnsaved( e ) {
		if ( table.querySelector( '.uappt-roster-cell.is-dirty, .uappt-roster-cell.is-accepted, .uappt-roster-cell.is-rejected' ) ) {
			e.preventDefault();
			e.returnValue = '';
		}
	}
	window.addEventListener( 'beforeunload', warnUnsaved );

	syncDirty();
}() );
