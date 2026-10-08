/**
 * 前台預約精靈。
 *
 * 一頁一個選項，選完自動進下一步：選人 → 選項目 → 選時間 → 確認。
 * `data-mode="service_first"` 時前兩步對調。
 *
 * 資料全部走 /wp-json/uappt/v1/ 的唯讀端點；最後送出是組一張原生表單 POST
 * 到目前網址（帶 add-to-cart），交給 WooCommerce 既有的加入購物車流程，
 * 所以守門邏輯（時段還在不在、方案有沒有停用）完全沿用後端那一套。
 *
 * 刻意不依賴 jQuery：這支只操作自己容器內的 DOM，沒有必要多一個相依。
 */
( function () {
	'use strict';

	var CFG = window.UAPPT_WizardConfig || {};
	var I18N = CFG.i18n || {};

	function t( key, fallback ) {
		return I18N[ key ] || fallback || '';
	}

	function el( tag, className, text ) {
		var node = document.createElement( tag );
		if ( className ) {
			node.className = className;
		}
		if ( undefined !== text && null !== text ) {
			node.textContent = text;
		}
		return node;
	}

	function api( path, params ) {
		var url = new URL( CFG.rest + path.replace( /^\//, '' ) );
		Object.keys( params || {} ).forEach( function ( k ) {
			if ( null !== params[ k ] && '' !== params[ k ] && undefined !== params[ k ] ) {
				url.searchParams.set( k, params[ k ] );
			}
		} );

		return fetch( url.toString(), {
			credentials: 'same-origin',
			headers: CFG.nonce ? { 'X-WP-Nonce': CFG.nonce } : {}
		} ).then( function ( res ) {
			if ( ! res.ok ) {
				return res.json().catch( function () {
					return {};
				} ).then( function ( body ) {
					throw new Error( body.message || t( 'error' ) );
				} );
			}
			return res.json();
		} );
	}

	/** 本地日期字串（Y-m-d）。刻意不用 toISOString()——那會先轉成 UTC，跨時區會差一天。 */
	function ymd( date ) {
		var m = String( date.getMonth() + 1 ).padStart( 2, '0' );
		var d = String( date.getDate() ).padStart( 2, '0' );
		return date.getFullYear() + '-' + m + '-' + d;
	}

	function Wizard( root ) {
		this.root = root;
		this.mode = root.getAttribute( 'data-mode' ) === 'service_first' ? 'service_first' : 'staff_first';

		// 鎖定值：由 shortcode 屬性或網址參數指定，對應的步驟會被跳過。
		this.locked = {
			staff: parseInt( root.getAttribute( 'data-staff' ), 10 ) || 0,
			service: parseInt( root.getAttribute( 'data-service' ), 10 ) || 0,
			plan: root.getAttribute( 'data-plan' ) || '',
			// 限定商品（不鎖死方案）。跟 locked.service 不同：service 是「這一步
			// 直接跳過」，product 是「這一步只列這個商品的方案」。
			product: parseInt( root.getAttribute( 'data-product' ), 10 ) || 0
		};

		this.state = {
			staffId: this.locked.staff,
			staffName: '',
			service: null,
			date: '',
			slot: null,
			month: new Date()
		};

		this.steps = this.buildSteps();
		this.index = 0;

		this.render();
		// 刻意不在建構子裡 enter()：鎖定服務時要先把服務資料抓回來塞進 state，
		// 否則第一步就是「選時間」，而它一開始就要讀 state.service.product_id。
		// 由 bootstrap() 準備好之後再呼叫 start()。
	}

	Wizard.prototype.start = function () {
		this.steps = this.buildSteps();
		this.index = 0;
		this.enter();
	};

	/** 依模式與鎖定值決定實際要走的步驟。 */
	Wizard.prototype.buildSteps = function () {
		var steps = ( 'service_first' === this.mode )
			? [ 'service', 'staff' ]
			: [ 'staff', 'service' ];

		steps = steps.filter( function ( name ) {
			if ( 'staff' === name && this.locked.staff ) {
				return false;
			}
			if ( 'service' === name && this.locked.service ) {
				return false;
			}
			return true;
		}, this );

		return steps.concat( [ 'time', 'confirm' ] );
	};

	Wizard.prototype.render = function () {
		this.root.textContent = '';

		this.headEl = el( 'div', 'uappt-wiz-head' );
		this.crumbEl = el( 'ol', 'uappt-wiz-crumbs' );
		this.headEl.appendChild( this.crumbEl );

		this.bodyEl = el( 'div', 'uappt-wiz-body' );
		this.summaryEl = el( 'div', 'uappt-wiz-summary' );

		this.root.appendChild( this.headEl );
		this.root.appendChild( this.bodyEl );
		this.root.appendChild( this.summaryEl );
	};

	Wizard.prototype.stepTitle = function ( name ) {
		return {
			staff: t( 'stepStaff' ),
			service: t( 'stepService' ),
			time: t( 'stepTime' ),
			confirm: t( 'stepConfirm' )
		}[ name ] || '';
	};

	Wizard.prototype.renderCrumbs = function () {
		this.crumbEl.textContent = '';
		this.steps.forEach( function ( name, i ) {
			var li = el( 'li', 'uappt-wiz-crumb', this.stepTitle( name ) );
			if ( i === this.index ) {
				li.classList.add( 'is-current' );
			} else if ( i < this.index ) {
				li.classList.add( 'is-done' );
				// 走過的步驟可以點回去改，但不能往前跳（後面的還沒選）。
				li.tabIndex = 0;
				li.addEventListener( 'click', this.goTo.bind( this, i ) );
				li.addEventListener( 'keydown', function ( e ) {
					if ( 'Enter' === e.key || ' ' === e.key ) {
						e.preventDefault();
						this.goTo( i );
					}
				}.bind( this ) );
			}
			this.crumbEl.appendChild( li );
		}, this );
	};

	Wizard.prototype.goTo = function ( index ) {
		var target = Math.max( 0, Math.min( index, this.steps.length - 1 ) );

		// 往回走時，把「後面那些步驟」已經選好的東西清掉。
		//
		// 這不只是為了摘要好看。各步驟是靠「某個 state 有沒有值」來決定行為的，
		// 例如選人那步會在已經知道服務時、只列做得了這個服務的人員。不清的話，
		// 從確認頁點回「選擇服務人員」，就會被上一輪選的服務反過來篩選，
		// 名單裡少掉幾位人員——客人只會覺得人不見了。（實際回報過的症狀。）
		if ( target < this.index ) {
			this.clearStateAfter( target );
		}

		this.index = target;
		this.enter();
	};

	/**
	 * 清掉第 index 步之後、各步驟各自負責的選擇。
	 *
	 * 鎖定的值（shortcode 屬性或網址參數指定的人員／服務）永遠保留——那不是
	 * 客人在流程中選的，回到前面也不該被清掉。鎖定的步驟本來就不在 steps 裡，
	 * 所以這個迴圈自然不會碰到它們。
	 *
	 * @param {number} index 要回到的步驟索引。
	 */
	Wizard.prototype.clearStateAfter = function ( index ) {
		for ( var i = index + 1; i < this.steps.length; i++ ) {
			if ( 'staff' === this.steps[ i ] ) {
				this.state.staffId = 0;
				this.state.staffName = '';
			} else if ( 'service' === this.steps[ i ] ) {
				this.state.service = null;
			} else if ( 'time' === this.steps[ i ] ) {
				this.state.date = '';
				this.state.slot = null;
			}
		}
	};

	Wizard.prototype.next = function () {
		this.goTo( this.index + 1 );
	};

	Wizard.prototype.enter = function () {
		// 換步驟時把精靈捲回視野內。時段那一步的清單很長，客人常常是在畫面
		// 很下方點下去的，下一步渲染在上面——不捲的話他看到的是頁尾，會以為
		// 按了沒反應。首次掛載不捲，那會搶走頁面本來的捲動位置。
		if ( this.mounted && this.root.getBoundingClientRect().top < 0 ) {
			this.root.scrollIntoView( { behavior: 'smooth', block: 'start' } );
		}
		this.mounted = true;

		this.renderCrumbs();
		this.renderSummary();
		this.bodyEl.textContent = '';
		this.bodyEl.appendChild( el( 'div', 'uappt-wiz-loading', t( 'loading' ) ) );

		var name = this.steps[ this.index ];
		var fn = {
			staff: this.stepStaff,
			service: this.stepService,
			time: this.stepTime,
			confirm: this.stepConfirm
		}[ name ];

		if ( fn ) {
			fn.call( this );
		}
	};

	Wizard.prototype.fail = function ( err ) {
		this.bodyEl.textContent = '';
		this.bodyEl.appendChild( el( 'p', 'uappt-wiz-error', ( err && err.message ) || t( 'error' ) ) );
	};

	Wizard.prototype.setBody = function ( nodes ) {
		this.bodyEl.textContent = '';
		nodes.forEach( function ( n ) {
			this.bodyEl.appendChild( n );
		}, this );

		if ( this.index > 0 ) {
			var back = el( 'button', 'uappt-wiz-back', t( 'back' ) );
			back.type = 'button';
			back.addEventListener( 'click', this.goTo.bind( this, this.index - 1 ) );
			this.bodyEl.appendChild( back );
		}
	};

	// --- 步驟：選服務人員 -------------------------------------------------

	Wizard.prototype.stepStaff = function () {
		var params = {};

		// 已經知道服務時，只列做得了這個服務的人員。
		//
		// 「已經知道」的兩種來源：先選項目模式（服務那步排在前面），或是網址／
		// shortcode 鎖定了服務。**不包含**「客人上一輪選過、現在又退回來改」——
		// 那種情況 goTo() 會先把 state.service 清掉，所以這裡看到的 null 就真的
		// 代表服務還沒定案。這個前提如果被破壞，客人退回這一步就會發現人員
		// 名單莫名其妙變短。
		if ( this.state.service ) {
			params.product_id = this.state.service.product_id;
			params.plan_key = this.state.service.plan_key;
		}

		api( 'staff', params ).then( function ( data ) {
			var list = data.staff || [];
			if ( ! list.length ) {
				this.setBody( [ el( 'p', 'uappt-wiz-empty', t( 'noStaff' ) ) ] );
				return;
			}

			var grid = el( 'div', 'uappt-wiz-grid uappt-wiz-grid-staff' );

			list.forEach( function ( s ) {
				grid.appendChild( this.staffCard( s ) );
			}, this );

			// 「不指定」永遠放最後：指定人員是這個精靈的主要情境，把它排在最前面
			// 會變成預設選項，反而不利於客人選自己習慣的設計師。
			grid.appendChild( this.staffCard( {
				id: 0,
				name: t( 'anyStaff' ),
				photo_url: '',
				price_adjustment: 0,
				is_last: false
			}, true ) );

			this.setBody( [ grid ] );
		}.bind( this ) ).catch( this.fail.bind( this ) );
	};

	Wizard.prototype.staffCard = function ( s, isAny ) {
		var card = el( 'button', 'uappt-wiz-card uappt-wiz-card-staff' );
		card.type = 'button';

		var avatar = el( 'span', 'uappt-wiz-avatar' );
		if ( s.photo_url ) {
			var img = document.createElement( 'img' );
			img.src = s.photo_url;
			img.alt = '';
			img.loading = 'lazy';
			avatar.appendChild( img );
		} else {
			// 沒有照片就用姓名首字的色塊，不要留一個空圓圈。
			avatar.classList.add( 'is-initial' );
			avatar.textContent = isAny ? '？' : String( s.name || '' ).trim().charAt( 0 );
		}
		card.appendChild( avatar );

		var meta = el( 'span', 'uappt-wiz-card-meta' );
		meta.appendChild( el( 'span', 'uappt-wiz-card-name', s.name ) );

		if ( isAny ) {
			meta.appendChild( el( 'span', 'uappt-wiz-card-note', t( 'anyStaffHint' ) ) );
		} else if ( s.is_last ) {
			meta.appendChild( el( 'span', 'uappt-wiz-card-badge', t( 'lastStaff' ) ) );
		}

		if ( s.price_adjustment > 0 ) {
			meta.appendChild( el( 'span', 'uappt-wiz-card-note', t( 'staffSurcharge' ) + ' +' + s.price_adjustment ) );
		}

		card.appendChild( meta );

		card.addEventListener( 'click', function () {
			this.state.staffId = s.id;
			this.state.staffName = isAny ? t( 'anyStaff' ) : s.name;
			this.next();
		}.bind( this ) );

		return card;
	};

	// --- 步驟：選服務項目 -------------------------------------------------

	Wizard.prototype.stepService = function () {
		// 跟 stepStaff() 是對稱的：已經知道人員時只列這個人做得了的服務，
		// 而「已經知道」同樣不包含「退回來改」——先選項目模式下退回這一步時，
		// goTo() 會先把 staffId 清掉，否則服務清單會被上一輪選的人員縮掉。
		api( 'services', {
			staff_id: this.state.staffId || 0,
			product_id: this.locked.product || 0
		} ).then( function ( data ) {
			var list = data.services || [];
			if ( ! list.length ) {
				this.setBody( [ el( 'p', 'uappt-wiz-empty', t( 'noService' ) ) ] );
				return;
			}

			var grid = el( 'div', 'uappt-wiz-grid uappt-wiz-grid-service' );

			list.forEach( function ( s ) {
				var card = el( 'button', 'uappt-wiz-card uappt-wiz-card-service' );
				card.type = 'button';

				card.appendChild( el( 'span', 'uappt-wiz-card-name', s.name ) );

				var meta = el( 'span', 'uappt-wiz-card-meta' );
				meta.appendChild( el( 'span', 'uappt-wiz-card-note', s.duration_minutes + ' ' + t( 'minutes' ) ) );
				meta.appendChild( el( 'span', 'uappt-wiz-card-price', s.price_html ) );
				card.appendChild( meta );

				card.addEventListener( 'click', function () {
					this.state.service = s;
					// 這個服務不開放客人指定人員時，把先前選的人清掉，
					// 避免摘要顯示一位其實不會被採用的人員。
					if ( false === s.staff_choice ) {
						this.state.staffId = 0;
						this.state.staffName = '';
					}
					this.next();
				}.bind( this ) );

				grid.appendChild( card );
			}, this );

			this.setBody( [ grid ] );
		}.bind( this ) ).catch( this.fail.bind( this ) );
	};

	// --- 步驟：選時間（月曆 + 時段） ---------------------------------------

	Wizard.prototype.stepTime = function () {
		// 理論上走到這一步一定已經有服務了（buildSteps 保證選項目排在選時間
		// 前面，鎖定服務時 bootstrap 也會先解析好）。萬一沒有，顯示錯誤比
		// 讓後面的 state.service.product_id 丟例外、整個精靈停在載入中好。
		if ( ! this.state.service ) {
			this.fail( new Error( t( 'error' ) ) );
			return;
		}

		this.state.date = '';
		this.state.slot = null;

		var wrap = el( 'div', 'uappt-wiz-time' );
		this.calEl = el( 'div', 'uappt-wiz-cal' );
		this.slotsEl = el( 'div', 'uappt-wiz-slots' );
		this.slotsEl.appendChild( el( 'p', 'uappt-wiz-hint', t( 'pickDate' ) ) );

		wrap.appendChild( this.calEl );
		wrap.appendChild( this.slotsEl );
		this.setBody( [ wrap ] );

		this.loadMonth();
	};

	Wizard.prototype.loadMonth = function () {
		var first = new Date( this.state.month.getFullYear(), this.state.month.getMonth(), 1 );
		var last = new Date( this.state.month.getFullYear(), this.state.month.getMonth() + 1, 0 );

		this.calEl.textContent = '';
		this.calEl.appendChild( el( 'div', 'uappt-wiz-loading', t( 'loading' ) ) );

		api( 'days', {
			product_id: this.state.service.product_id,
			plan_key: this.state.service.plan_key,
			staff_id: this.state.staffId || 0,
			from: ymd( first ),
			to: ymd( last )
		} ).then( function ( data ) {
			this.renderCalendar( first, last, data.days || {} );
		}.bind( this ) ).catch( this.fail.bind( this ) );
	};

	Wizard.prototype.renderCalendar = function ( first, last, days ) {
		this.calEl.textContent = '';

		var nav = el( 'div', 'uappt-wiz-cal-nav' );
		var prev = el( 'button', 'uappt-wiz-cal-prev', '‹' );
		prev.type = 'button';
		prev.setAttribute( 'aria-label', t( 'prevMonth' ) );
		var next = el( 'button', 'uappt-wiz-cal-next', '›' );
		next.type = 'button';
		next.setAttribute( 'aria-label', t( 'nextMonth' ) );

		var label = el( 'strong', 'uappt-wiz-cal-label', first.getFullYear() + ' / ' + String( first.getMonth() + 1 ).padStart( 2, '0' ) );

		// 不讓客人翻到過去的月份：那裡一定全部不可選。
		var now = new Date();
		if ( first <= new Date( now.getFullYear(), now.getMonth(), 1 ) ) {
			prev.disabled = true;
		}

		prev.addEventListener( 'click', this.shiftMonth.bind( this, -1 ) );
		next.addEventListener( 'click', this.shiftMonth.bind( this, 1 ) );

		nav.appendChild( prev );
		nav.appendChild( label );
		nav.appendChild( next );
		this.calEl.appendChild( nav );

		var head = el( 'div', 'uappt-wiz-cal-week uappt-wiz-cal-head' );
		( CFG.weekdays || [] ).forEach( function ( w ) {
			head.appendChild( el( 'span', 'uappt-wiz-cal-wd', w ) );
		} );
		this.calEl.appendChild( head );

		var grid = el( 'div', 'uappt-wiz-cal-grid' );

		// 補上月初之前的空格，讓 1 號落在正確的星期欄位。
		for ( var pad = 0; pad < first.getDay(); pad++ ) {
			grid.appendChild( el( 'span', 'uappt-wiz-cal-cell is-pad' ) );
		}

		for ( var d = 1; d <= last.getDate(); d++ ) {
			var date = new Date( first.getFullYear(), first.getMonth(), d );
			var key = ymd( date );
			var status = days[ key ] || 'closed';
			var open = ( 'open' === status );

			var cell = el( 'button', 'uappt-wiz-cal-cell is-' + status, String( d ) );
			cell.type = 'button';
			cell.disabled = ! open;

			if ( open ) {
				cell.addEventListener( 'click', this.pickDate.bind( this, key, cell ) );
			}

			grid.appendChild( cell );
		}

		this.calEl.appendChild( grid );
	};

	Wizard.prototype.shiftMonth = function ( delta ) {
		this.state.month = new Date( this.state.month.getFullYear(), this.state.month.getMonth() + delta, 1 );
		this.state.date = '';
		this.state.slot = null;
		this.slotsEl.textContent = '';
		this.slotsEl.appendChild( el( 'p', 'uappt-wiz-hint', t( 'pickDate' ) ) );
		this.loadMonth();
	};

	Wizard.prototype.pickDate = function ( date, cell ) {
		this.state.date = date;
		this.state.slot = null;

		Array.prototype.forEach.call(
			this.calEl.querySelectorAll( '.uappt-wiz-cal-cell.is-selected' ),
			function ( n ) {
				n.classList.remove( 'is-selected' );
			}
		);
		cell.classList.add( 'is-selected' );

		this.slotsEl.textContent = '';
		this.slotsEl.appendChild( el( 'div', 'uappt-wiz-loading', t( 'loading' ) ) );

		api( 'slots', {
			product_id: this.state.service.product_id,
			plan_key: this.state.service.plan_key,
			staff_id: this.state.staffId || 0,
			date: date
		} ).then( function ( data ) {
			this.renderSlots( data );
		}.bind( this ) ).catch( function ( err ) {
			this.slotsEl.textContent = '';
			this.slotsEl.appendChild( el( 'p', 'uappt-wiz-error', err.message || t( 'error' ) ) );
		}.bind( this ) );
	};

	Wizard.prototype.renderSlots = function ( data ) {
		var slots = data.slots || [];
		this.slotsEl.textContent = '';

		if ( ! slots.length ) {
			this.slotsEl.appendChild( el( 'p', 'uappt-wiz-empty', t( 'noSlots' ) ) );
			return;
		}

		// 有分類定義就照上午／下午／晚間分組，讓一長串時間好掃視。
		var segments = data.segments || [];
		var groups = segments.length
			? segments.map( function ( seg ) {
				return {
					name: seg.name,
					items: slots.filter( function ( s ) {
						return s.segment === seg.key;
					} )
				};
			} )
			: [ { name: '', items: slots } ];

		groups.forEach( function ( group ) {
			if ( ! group.items.length ) {
				return;
			}
			if ( group.name ) {
				this.slotsEl.appendChild( el( 'h4', 'uappt-wiz-slot-group', group.name ) );
			}

			var row = el( 'div', 'uappt-wiz-slot-row' );
			group.items.forEach( function ( slot ) {
				var chip = el( 'button', 'uappt-wiz-slot', slot.start );
				chip.type = 'button';

				// 只有「還有 2 位以上」才標數字。指定了人員時每個時段的剩餘
				// 幾乎都是 1，每格都掛一個「1」純粹是雜訊——這也是後台
				// show_remaining 預設 auto 的原意（見 UAPPT_Availability_Query
				// ::should_show_remaining()），只是那邊算的是整個商品的總容量，
				// 判斷不到「客人這次只指定了一位人員」。
				if ( data.show_remaining && slot.remaining > 1 ) {
					chip.appendChild( el( 'span', 'uappt-wiz-slot-left', String( slot.remaining ) ) );
				}

				chip.addEventListener( 'click', function () {
					this.state.slot = slot;
					this.next();
				}.bind( this ) );

				row.appendChild( chip );
			}, this );

			this.slotsEl.appendChild( row );
		}, this );
	};

	// --- 步驟：確認 -------------------------------------------------------

	Wizard.prototype.stepConfirm = function () {
		var box = el( 'div', 'uappt-wiz-confirm' );

		box.appendChild( this.confirmRow( t( 'summaryService' ), this.state.service.name ) );
		if ( this.state.staffName ) {
			box.appendChild( this.confirmRow( t( 'summaryStaff' ), this.state.staffName ) );
		}
		box.appendChild( this.confirmRow(
			t( 'summaryTime' ),
			this.state.date + ' ' + this.state.slot.start + '–' + this.state.slot.end
		) );
		box.appendChild( this.confirmRow( t( 'total' ), this.state.service.price_html, 'is-total' ) );

		var submit = el( 'button', 'uappt-wiz-submit', t( 'confirm' ) );
		submit.type = 'button';
		submit.addEventListener( 'click', function () {
			submit.disabled = true;
			submit.textContent = t( 'submitting' );
			this.submit();
		}.bind( this ) );

		box.appendChild( submit );
		box.appendChild( el( 'p', 'uappt-wiz-hint', t( 'nextNote' ) ) );

		this.setBody( [ box ] );
	};

	Wizard.prototype.confirmRow = function ( label, value, modifier ) {
		var row = el( 'div', 'uappt-wiz-confirm-row' + ( modifier ? ' ' + modifier : '' ) );
		row.appendChild( el( 'span', 'uappt-wiz-confirm-label', label || '' ) );
		row.appendChild( el( 'span', 'uappt-wiz-confirm-value', value ) );
		return row;
	};

	/**
	 * 組一張原生表單 POST 出去。
	 *
	 * 刻意不用 AJAX：這樣走的是 WooCommerce 既有的加入購物車流程，
	 * UAPPT_Cart::validate_and_hold() 的守門、暫留、價格計算全部沿用，
	 * 成功後由 UAPPT_Cart::redirect_wizard_after_add() 轉去購物車頁（或結帳頁，
	 * 看「設定 ▸ 前台顯示 ▸ 預約送出後前往」）。
	 * action 用目前網址：驗證失敗時客人會留在這一頁看到錯誤訊息。
	 */
	Wizard.prototype.submit = function () {
		var form = document.createElement( 'form' );
		form.method = 'post';
		form.action = window.location.href;
		form.style.display = 'none';

		var fields = {
			'add-to-cart': this.state.service.product_id,
			quantity: 1,
			uappt_plan_key: this.state.service.plan_key,
			uappt_booking_date: this.state.date,
			uappt_booking_time: this.state.slot.start,
			uappt_booking_staff: this.state.staffId || 0,
			uappt_booking_nonce: CFG.addNonce
		};
		fields[ CFG.sourceField ] = CFG.sourceValue;

		Object.keys( fields ).forEach( function ( name ) {
			var input = document.createElement( 'input' );
			input.type = 'hidden';
			input.name = name;
			input.value = fields[ name ];
			form.appendChild( input );
		} );

		document.body.appendChild( form );
		form.submit();
	};

	// --- 底部摘要列 -------------------------------------------------------

	Wizard.prototype.renderSummary = function () {
		var parts = [];
		if ( this.state.staffName ) {
			parts.push( this.state.staffName );
		}
		if ( this.state.service ) {
			parts.push( this.state.service.name );
		}
		if ( this.state.date && this.state.slot ) {
			parts.push( this.state.date + ' ' + this.state.slot.start );
		}

		this.summaryEl.textContent = '';
		if ( ! parts.length ) {
			this.summaryEl.classList.remove( 'is-visible' );
			return;
		}

		this.summaryEl.classList.add( 'is-visible' );
		parts.forEach( function ( p ) {
			this.summaryEl.appendChild( el( 'span', 'uappt-wiz-summary-item', p ) );
		}, this );
	};

	/**
	 * 開場：把鎖定的人員／服務先解析成真正的資料，再啟動精靈。
	 *
	 * 兩種鎖定都需要先問後端：鎖定服務要拿到名稱／時長／價格（選時間那步一
	 * 開始就要用 product_id），鎖定人員要拿到名字（否則摘要列會是空的，客人
	 * 從 /預約?staff=3 進來完全看不出自己被指定給誰）。
	 *
	 * 解析不到就把鎖定拿掉、讓那一步正常出現——網址帶了一個已經下架的服務或
	 * 已離職的人員時，總比卡在載入中好。
	 */
	function bootstrap( root ) {
		var wiz = new Wizard( root );
		var jobs = [];

		if ( wiz.locked.service ) {
			jobs.push(
				api( 'services', {} ).then( function ( data ) {
					var match = ( data.services || [] ).filter( function ( s ) {
						return s.product_id === wiz.locked.service
							&& ( ! wiz.locked.plan || s.plan_key === wiz.locked.plan );
					} )[ 0 ];

					if ( match ) {
						wiz.state.service = match;
					} else {
						wiz.locked.service = 0;
					}
				} )
			);
		}

		// 限定商品：先問這個商品有幾個方案。只有一個的話沒什麼好選的，
		// 直接當成鎖定、跳過選項目那一步（商品頁用精靈模式時最常見的情況）。
		if ( wiz.locked.product && ! wiz.locked.service ) {
			jobs.push(
				api( 'services', { product_id: wiz.locked.product } ).then( function ( data ) {
					var list = data.services || [];
					if ( 1 === list.length ) {
						wiz.state.service = list[ 0 ];
						wiz.locked.service = list[ 0 ].product_id;
					}
				} )
			);
		}

		if ( wiz.locked.staff ) {
			jobs.push(
				api( 'staff', {} ).then( function ( data ) {
					var match = ( data.staff || [] ).filter( function ( s ) {
						return s.id === wiz.locked.staff;
					} )[ 0 ];

					if ( match ) {
						wiz.state.staffName = match.name;
					} else {
						wiz.locked.staff = 0;
						wiz.state.staffId = 0;
					}
				} )
			);
		}

		if ( ! jobs.length ) {
			wiz.start();
			return;
		}

		Promise.all( jobs ).then( function () {
			wiz.start();
		} ).catch( function () {
			// 查不到就退回完整流程，不要讓客人卡在載入中。
			wiz.locked.service = 0;
			wiz.locked.staff = 0;
			wiz.state.service = null;
			wiz.state.staffId = 0;
			wiz.start();
		} );
	}

	/**
	 * 掛載單一個精靈容器。
	 *
	 * 會被好幾條路徑呼叫（DOM 就緒、Elementor 動態插入），所以標記一下避免
	 * 同一個容器被掛載兩次——那會變成兩套事件監聽器打架。
	 *
	 * @param {Element} root 容器。
	 */
	function mount( root ) {
		if ( ! root || root.getAttribute( 'data-uappt-mounted' ) ) {
			return;
		}
		root.setAttribute( 'data-uappt-mounted', '1' );
		bootstrap( root );
	}

	function mountAll( scope ) {
		var container = scope || document;
		Array.prototype.forEach.call( container.querySelectorAll( '.uappt-wiz' ), mount );
	}

	// 腳本有可能在 DOM 就緒「之後」才執行——Elementor 是在渲染當下才 enqueue
	// （見 widget 的 get_script_depends()），前端優化外掛也可能把它 defer 掉。
	// 那種情況下 DOMContentLoaded 已經觸發過，只監聽它會永遠等不到。
	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', function () {
			mountAll();
		} );
	} else {
		mountAll();
	}

	// Elementor 在編輯器預覽裡是用 AJAX 把 widget 塞進既有的 DOM 的，不會有
	// 新的 DOMContentLoaded——沒有這一段的話，剛拖進去的精靈會一直停在
	// 「載入中…」，要整頁重新載入才會動。彈窗（popup）與其他延遲插入的場合
	// 也走同一條路。
	window.addEventListener( 'elementor/frontend/init', function () {
		if ( ! window.elementorFrontend || ! elementorFrontend.hooks ) {
			return;
		}

		var type = CFG.elementorWidget || 'uappt_booking';

		elementorFrontend.hooks.addAction(
			'frontend/element_ready/' + type + '.default',
			function ( $scope ) {
				var node = ( $scope && $scope[ 0 ] ) ? $scope[ 0 ] : null;
				if ( node ) {
					mountAll( node );
				}
			}
		);
	} );
} )();
