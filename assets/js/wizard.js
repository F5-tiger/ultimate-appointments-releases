/**
 * 前台預約精靈。
 *
 * 一頁一個選項，選完自動進下一步：選人 → 選服務 → 選方案 → 選時間 → 確認。
 * `data-mode="service_first"` 時「人」排到「方案」後面。
 *
 * 「服務」是商品、「方案」是商品裡的服務方案（v3.4.0 拆成兩步，之前是全部方案
 * 攤平在同一頁）。只有一個方案的商品選了就直接定案，「方案」那一步會從步驟列
 * 消失，所以步驟清單不是固定的，見 buildSteps()／next()。
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

	function fmt( template, value ) {
		return String( template || '' ).replace( /%[sd]/, value );
	}

	/**
	 * 把 /services 的方案清單依商品分組，保留後端排好的順序。
	 *
	 * @param {Array} list service_payload 陣列。
	 * @return {Array} 每個元素：{ product_id, name, image, plans: [] }
	 */
	function groupByProduct( list ) {
		var groups = [];
		var byId = {};

		list.forEach( function ( s ) {
			var g = byId[ s.product_id ];
			if ( ! g ) {
				g = byId[ s.product_id ] = {
					product_id: s.product_id,
					name: s.product_name,
					image: s.product_image || '',
					plans: []
				};
				groups.push( g );
			}
			g.plans.push( s );
		} );

		return groups;
	}

	/**
	 * 摘要、確認頁上的服務名稱：「美甲美睫・法式彩繪」。
	 *
	 * 只寫方案名稱的話，「油壓」「指壓」分不出是哪一項服務底下的。沒有方案的
	 * 商品（plan_key 空字串）名稱本來就是商品名稱，不重複寫兩次。
	 */
	function serviceLabel( s ) {
		if ( ! s ) {
			return '';
		}
		return ( s.plan_key && s.name !== s.product_name ) ? s.product_name + '・' + s.name : s.name;
	}

	/**
	 * 金額格式，照 WooCommerce 的設定（貨幣符號、小數位數、千分位、符號位置）。
	 * 確認頁的合計要算「方案價 × 人數 ＋ 指定加價」，伺服器給的 price_html 只是
	 * 單價，所以要能自己格式化。
	 *
	 * @param {number} amount 金額。
	 * @return {string}
	 */
	function money( amount ) {
		var c = CFG.currency || {};
		var decimals = parseInt( c.decimals, 10 ) || 0;
		var fixed = Math.abs( Number( amount ) || 0 ).toFixed( decimals ).split( '.' );
		var whole = fixed[ 0 ].replace( /\B(?=(\d{3})+(?!\d))/g, undefined !== c.thousand ? c.thousand : ',' );
		var number = ( amount < 0 ? '-' : '' ) + whole + ( fixed[ 1 ] ? ( c.decimal || '.' ) + fixed[ 1 ] : '' );
		return String( c.format || '%1$s%2$s' ).replace( '%1$s', c.symbol || '' ).replace( '%2$s', number );
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
			// 選定的商品（groupByProduct() 的一組）；service 才是最後定案的方案。
			product: null,
			service: null,
			// 方案是自動定案的（這個商品只有一個方案可選），「方案」那一步不顯示。
			planAuto: false,
			// 選人那一步被跳過：人員清單是空的（全站都不開放指定人員），由店家安排。
			staffAuto: false,
			// 指定人員的加價（v3.4.1）：確認頁的合計要算進去，跟購物車同一個公式。
			staffAdjust: 0,
			date: '',
			slot: null,
			// 時段回應裡的團體預約設定（v3.4.1）：開了才在確認頁顯示報名人數。
			group: { enabled: false, max: 0 },
			participants: 1,
			month: new Date()
		};

		// 請求序號（v3.4.1）：快速換日期、翻月份時，較早的請求晚回來不能蓋掉新的畫面。
		this.slotsSeq = 0;
		this.monthSeq = 0;

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

	/**
	 * 時間之前的選擇步驟。
	 *
	 * @param {boolean} includeAuto 要不要包含「自動定案而跳過」的方案步驟。清除
	 *                              狀態時要包含——它雖然沒顯示，選擇還是存在。
	 */
	Wizard.prototype.choiceSteps = function ( includeAuto ) {
		var steps = ( 'service_first' === this.mode )
			? [ 'service', 'plan', 'staff' ]
			: [ 'staff', 'service', 'plan' ];

		return steps.filter( function ( name ) {
			if ( 'staff' === name ) {
				if ( this.locked.staff ) {
					return false;
				}
				// 不開放指定人員的服務直接由店家安排，不要讓客人走進一個沒有選項的
				// 選人頁（v3.4.1 以前會卡在「目前沒有可預約的服務人員」）。人員清單
				// 整個是空的（全站不開放指定）也一樣跳過，見 stepStaff()。
				if ( ! includeAuto && ( this.state.staffAuto || ( this.state.service && false === this.state.service.staff_choice ) ) ) {
					return false;
				}
				return true;
			}
			if ( 'service' === name ) {
				// 限定商品（data-product）時不用選商品，直接從方案開始。
				return ! this.locked.service && ! this.locked.product;
			}
			if ( 'plan' === name ) {
				if ( this.locked.service ) {
					return false;
				}
				// 站上沒有多方案的商品就整格不放（CFG.multiPlan）。但只要實際遇到
				// 多方案的商品、或限定了商品（方案數要問過才知道）就一定要放，
				// 不然旗標一過時，客人會帶著沒定案的方案直接跳到選時間。
				var needed = false !== CFG.multiPlan
					|| this.locked.product
					|| ( this.state.product && this.state.product.plans.length > 1 );
				if ( ! needed ) {
					return false;
				}
				return includeAuto || ! this.state.planAuto;
			}
			return true;
		}, this );
	};

	/** 依模式、鎖定值與目前的選擇決定實際要走的步驟。 */
	Wizard.prototype.buildSteps = function () {
		return this.choiceSteps( false ).concat( [ 'time', 'confirm' ] );
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
			plan: t( 'stepPlan' ),
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
			var name = this.steps[ target ];
			this.clearStateAfter( name );
			// 清掉選擇之後，原本自動跳過的「方案」可能要回來，步驟要重算。
			this.steps = this.buildSteps();
			target = Math.max( 0, this.steps.indexOf( name ) );
		}

		this.index = target;
		this.enter();
	};

	/**
	 * 清掉某一步之後、各步驟各自負責的選擇。
	 *
	 * 鎖定的值（shortcode 屬性或網址參數指定的人員／服務）永遠保留——那不是
	 * 客人在流程中選的，回到前面也不該被清掉。鎖定的步驟本來就不在清單裡，
	 * 所以這個迴圈自然不會碰到它們。
	 *
	 * 走的是**包含自動跳過的方案步驟**的清單：方案雖然沒顯示，選擇還是存在。
	 * 例如限定商品＋先選人，這位人員只做得了一個方案，方案是自動定案的；
	 * 退回選人那一步時不清掉它，換一位人員後就會帶著上一位的方案往下走。
	 *
	 * @param {string} name 要回到的步驟名稱。
	 */
	Wizard.prototype.clearStateAfter = function ( name ) {
		var steps = this.choiceSteps( true ).concat( [ 'time', 'confirm' ] );

		for ( var i = steps.indexOf( name ) + 1; i < steps.length; i++ ) {
			if ( 'staff' === steps[ i ] ) {
				this.state.staffId = 0;
				this.state.staffName = '';
				this.state.staffAdjust = 0;
				this.state.staffAuto = false;
			} else if ( 'service' === steps[ i ] ) {
				this.state.product = null;
				this.state.service = null;
				this.state.planAuto = false;
			} else if ( 'plan' === steps[ i ] ) {
				this.state.service = null;
				this.state.planAuto = false;
				// 限定商品時沒有「服務」那一步，state.product 是方案那一步依**當時的人員**
				// 查回來的快取。換人之後不清掉，就會沿用上一位人員的方案（v3.4.1 修正）。
				if ( this.locked.product ) {
					this.state.product = null;
				}
			} else if ( 'time' === steps[ i ] ) {
				this.state.date = '';
				this.state.slot = null;
				this.state.participants = 1;
			}
		}
	};

	/**
	 * 往下一步。
	 *
	 * 不能單純 index + 1：剛才的選擇可能讓「方案」那一步消失（只有一個方案的
	 * 商品），步驟要先重算，再找「原本排在後面、現在還在」的第一步。
	 */
	Wizard.prototype.next = function () {
		var after = this.steps.slice( this.index + 1 );
		this.steps = this.buildSteps();

		for ( var i = 0; i < after.length; i++ ) {
			var at = this.steps.indexOf( after[ i ] );
			if ( -1 !== at ) {
				this.index = at;
				this.enter();
				return;
			}
		}
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
			plan: this.stepPlan,
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
				// 還不知道服務就拿到空清單：全站都不開放指定人員（REST 刻意回空的，
				// 見 UAPPT_Rest::get_staff()）。照那邊註解的本意退回「先選服務」——
				// 由店家安排，跳過這一步（v3.4.1 以前會停在這裡）。
				if ( ! this.state.service ) {
					this.state.staffAuto = true;
					this.state.staffId = 0;
					this.state.staffName = '';
					this.state.staffAdjust = 0;
					this.next();
					return;
				}
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
			this.state.staffAdjust = isAny ? 0 : ( Number( s.price_adjustment ) || 0 );
			this.next();
		}.bind( this ) );

		return card;
	};

	// --- 步驟：選服務項目（商品） -----------------------------------------

	Wizard.prototype.stepService = function () {
		// 跟 stepStaff() 是對稱的：已經知道人員時只列這個人做得了的服務，
		// 而「已經知道」同樣不包含「退回來改」——先選項目模式下退回這一步時，
		// goTo() 會先把 staffId 清掉，否則服務清單會被上一輪選的人員縮掉。
		api( 'services', {
			staff_id: this.state.staffId || 0
		} ).then( function ( data ) {
			var groups = groupByProduct( data.services || [] );
			if ( ! groups.length ) {
				this.setBody( [ el( 'p', 'uappt-wiz-empty', t( 'noService' ) ) ] );
				return;
			}

			// 整份清單都沒有主圖就不畫圖片那一欄：一整排首字色塊不好看，
			// 沒在用商品圖的店家拿掉反而乾淨。有一部分有圖時才用色塊補位，
			// 卡片的文字才會對齊。
			var withThumb = groups.some( function ( g ) {
				return !! g.image;
			} );

			var grid = el( 'div', 'uappt-wiz-grid uappt-wiz-grid-service' );
			groups.forEach( function ( g ) {
				grid.appendChild( this.productCard( g, withThumb ) );
			}, this );

			this.setBody( [ grid ] );
		}.bind( this ) ).catch( this.fail.bind( this ) );
	};

	Wizard.prototype.productCard = function ( g, withThumb ) {
		var card = el( 'button', 'uappt-wiz-card uappt-wiz-card-service uappt-wiz-card-product' );
		card.type = 'button';

		if ( withThumb ) {
			var thumb = el( 'span', 'uappt-wiz-thumb' );
			if ( g.image ) {
				var img = document.createElement( 'img' );
				img.src = g.image;
				img.alt = '';
				img.loading = 'lazy';
				thumb.appendChild( img );
			} else {
				thumb.classList.add( 'is-initial' );
				thumb.textContent = String( g.name || '' ).trim().charAt( 0 );
			}
			card.appendChild( thumb );
		}

		var durations = g.plans.map( function ( p ) {
			return p.duration_minutes;
		} );
		var minDur = Math.min.apply( null, durations );
		var maxDur = Math.max.apply( null, durations );
		var cheapest = g.plans.reduce( function ( a, b ) {
			return b.price < a.price ? b : a;
		} );
		var samePrice = g.plans.every( function ( p ) {
			return p.price === cheapest.price;
		} );

		// 右欄跟方案卡片同一個結構（時長在上、價格在下），兩步切換時價格的位置
		// 不會跳。方案數放在名稱底下：原本跟時長併成一行「60–90 分鐘・3 個方案」，
		// 手機上會從「方」「案」中間折行。
		var main = el( 'span', 'uappt-wiz-card-main' );
		main.appendChild( el( 'span', 'uappt-wiz-card-name', g.name ) );
		if ( g.plans.length > 1 ) {
			main.appendChild( el( 'span', 'uappt-wiz-card-note', fmt( t( 'planCount' ), g.plans.length ) ) );
		}
		card.appendChild( main );

		var meta = el( 'span', 'uappt-wiz-card-meta' );
		meta.appendChild( el( 'span', 'uappt-wiz-card-note', ( minDur === maxDur ? minDur : minDur + '–' + maxDur ) + ' ' + t( 'minutes' ) ) );
		meta.appendChild( el(
			'span',
			'uappt-wiz-card-price',
			samePrice ? cheapest.price_html : fmt( t( 'priceFrom' ), cheapest.price_html )
		) );
		card.appendChild( meta );

		card.addEventListener( 'click', function () {
			this.state.product = g;
			// 只有一個方案可選：直接定案，「方案」那一步會從步驟列消失。
			if ( 1 === g.plans.length ) {
				this.pickPlan( g.plans[ 0 ], true );
			} else {
				this.state.service = null;
				this.state.planAuto = false;
				this.next();
			}
		}.bind( this ) );

		return card;
	};

	// --- 步驟：選方案 -----------------------------------------------------

	Wizard.prototype.stepPlan = function () {
		// 從「選擇服務」點進來時方案已經在手上（那一步就是依同一位人員查的）。
		// 限定商品（data-product）時沒有那一步，要自己查。
		var ready = this.state.product
			? Promise.resolve( [ this.state.product ] )
			: api( 'services', {
				staff_id: this.state.staffId || 0,
				product_id: this.locked.product || 0
			} ).then( function ( data ) {
				return groupByProduct( data.services || [] );
			} );

		ready.then( function ( groups ) {
			var g = groups[ 0 ];
			if ( ! g || ! g.plans.length ) {
				this.setBody( [ el( 'p', 'uappt-wiz-empty', t( 'noService' ) ) ] );
				return;
			}

			this.state.product = g;

			// 限定商品＋先選人：這位人員只做得了其中一個方案時一樣直接定案。
			if ( 1 === g.plans.length ) {
				this.pickPlan( g.plans[ 0 ], true );
				return;
			}

			var grid = el( 'div', 'uappt-wiz-grid uappt-wiz-grid-service' );
			g.plans.forEach( function ( s ) {
				var card = el( 'button', 'uappt-wiz-card uappt-wiz-card-service' );
				card.type = 'button';

				card.appendChild( el( 'span', 'uappt-wiz-card-name', s.name ) );

				var meta = el( 'span', 'uappt-wiz-card-meta' );
				meta.appendChild( el( 'span', 'uappt-wiz-card-note', s.duration_minutes + ' ' + t( 'minutes' ) ) );
				meta.appendChild( el( 'span', 'uappt-wiz-card-price', s.price_html ) );
				card.appendChild( meta );

				card.addEventListener( 'click', this.pickPlan.bind( this, s, false ) );
				grid.appendChild( card );
			}, this );

			// 方案名稱常常只是「60 分鐘」「進階」這種詞，上面標出是哪一項服務。
			this.setBody( [ el( 'p', 'uappt-wiz-step-head', g.name ), grid ] );
		}.bind( this ) ).catch( this.fail.bind( this ) );
	};

	/**
	 * 方案定案。
	 *
	 * @param {Object}  s    service_payload。
	 * @param {boolean} auto 是不是因為只有一個方案而自動定案（「方案」那一步不顯示）。
	 */
	Wizard.prototype.pickPlan = function ( s, auto ) {
		this.state.service = s;
		this.state.planAuto = !! auto;
		// 這個服務不開放客人指定人員時，把先前選的人清掉，
		// 避免摘要顯示一位其實不會被採用的人員。
		if ( false === s.staff_choice ) {
			this.state.staffId = 0;
			this.state.staffName = '';
			this.state.staffAdjust = 0;
		}
		this.next();
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
		this.state.participants = 1;
		// 換了步驟，之前那一輪還在路上的請求一律作廢。
		this.slotsSeq++;
		this.monthSeq++;

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

		// 快速連按「下個月」時，前一個月的回應晚到不能把月曆蓋回去。
		var seq = ++this.monthSeq;

		api( 'days', {
			product_id: this.state.service.product_id,
			plan_key: this.state.service.plan_key,
			staff_id: this.state.staffId || 0,
			from: ymd( first ),
			to: ymd( last )
		} ).then( function ( data ) {
			if ( seq === this.monthSeq ) {
				this.renderCalendar( first, last, data.days || {} );
			}
		}.bind( this ) ).catch( function ( err ) {
			if ( seq === this.monthSeq ) {
				this.fail( err );
			}
		}.bind( this ) );
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
		this.slotsSeq++; // 上個月選的那天的時段還在路上的話，回來時不要畫出來。
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

		// ⚠️ 只認最新那一次（v3.4.1）：先點 10/20 再點 10/21，10/20 的回應比較晚到的話，
		// 以前會在「已選 10/21」的畫面上畫出 10/20 的時段，客人點下去送出的是
		// 「10/21＋10/20 的時間」。
		var seq = ++this.slotsSeq;

		api( 'slots', {
			product_id: this.state.service.product_id,
			plan_key: this.state.service.plan_key,
			staff_id: this.state.staffId || 0,
			date: date
		} ).then( function ( data ) {
			if ( seq === this.slotsSeq && date === this.state.date ) {
				this.renderSlots( data );
			}
		}.bind( this ) ).catch( function ( err ) {
			if ( seq !== this.slotsSeq ) {
				return;
			}
			this.slotsEl.textContent = '';
			this.slotsEl.appendChild( el( 'p', 'uappt-wiz-error', err.message || t( 'error' ) ) );
		}.bind( this ) );
	};

	Wizard.prototype.renderSlots = function ( data ) {
		var slots = data.slots || [];
		this.slotsEl.textContent = '';

		// 團體預約：確認頁要不要顯示報名人數、上限多少（每個時段另外有 max_group_size）。
		this.state.group = {
			enabled: !! data.group_booking,
			max: parseInt( data.group_max_participants, 10 ) || 0
		};

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
		var maxPeople = this.maxParticipants();
		this.state.participants = Math.min( Math.max( 1, this.state.participants || 1 ), maxPeople );

		box.appendChild( this.confirmRow( t( 'summaryService' ), serviceLabel( this.state.service ) ) );
		if ( this.state.staffName ) {
			var staffText = this.state.staffName;
			if ( this.state.staffId && this.state.staffAdjust > 0 ) {
				staffText += '（' + t( 'staffSurcharge' ) + ' +' + money( this.state.staffAdjust ) + '）';
			}
			box.appendChild( this.confirmRow( t( 'summaryStaff' ), staffText ) );
		}
		box.appendChild( this.confirmRow(
			t( 'summaryTime' ),
			this.state.date + ' ' + this.state.slot.start + '–' + this.state.slot.end
		) );

		// 報名人數（v3.4.1）：商品開了團體預約才出現。以前精靈完全沒有這個欄位，
		// 開了團體預約的商品改用精靈之後就只能一次報一個人。
		if ( maxPeople > 1 ) {
			box.appendChild( this.participantsRow( maxPeople ) );
		}

		this.totalRow = this.confirmRow( t( 'total' ), '', 'is-total' );
		box.appendChild( this.totalRow );
		this.refreshTotal();

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

	/**
	 * 這個時段最多能報幾人：商品的每單上限（0＝不限）跟這個時段單一人員剩下的名額
	 * 取小的。後端 create_hold() 一樣會再擋一次，這裡只是不讓客人選到一定會失敗的數字。
	 *
	 * @return {number} 沒開團體預約時是 1。
	 */
	Wizard.prototype.maxParticipants = function () {
		if ( ! this.state.group || ! this.state.group.enabled ) {
			return 1;
		}
		var max = this.state.group.max > 0 ? this.state.group.max : 99;
		if ( this.state.slot && this.state.slot.max_group_size ) {
			max = Math.min( max, parseInt( this.state.slot.max_group_size, 10 ) || 1 );
		}
		return Math.max( 1, max );
	};

	Wizard.prototype.participantsRow = function ( maxPeople ) {
		var row = el( 'div', 'uappt-wiz-confirm-row' );
		row.appendChild( el( 'span', 'uappt-wiz-confirm-label', t( 'participants' ) ) );

		var stepper = el( 'span', 'uappt-wiz-stepper' );
		var minus = el( 'button', 'uappt-wiz-stepper-btn', '−' );
		var plus = el( 'button', 'uappt-wiz-stepper-btn', '+' );
		var value = el( 'span', 'uappt-wiz-stepper-value' );
		minus.type = 'button';
		plus.type = 'button';
		minus.setAttribute( 'aria-label', t( 'fewer' ) );
		plus.setAttribute( 'aria-label', t( 'more' ) );
		value.setAttribute( 'aria-live', 'polite' );

		var sync = function () {
			value.textContent = fmt( t( 'people' ), this.state.participants );
			minus.disabled = this.state.participants <= 1;
			plus.disabled = this.state.participants >= maxPeople;
			this.refreshTotal();
		}.bind( this );

		minus.addEventListener( 'click', function () {
			this.state.participants = Math.max( 1, this.state.participants - 1 );
			sync();
		}.bind( this ) );
		plus.addEventListener( 'click', function () {
			this.state.participants = Math.min( maxPeople, this.state.participants + 1 );
			sync();
		}.bind( this ) );

		stepper.appendChild( minus );
		stepper.appendChild( value );
		stepper.appendChild( plus );
		row.appendChild( stepper );
		sync();

		return row;
	};

	/**
	 * 合計＝方案價 × 人數 ＋ 指定加價（v3.4.1），跟購物車 UAPPT_Cart::apply_cart_item_price()
	 * 同一個公式。以前直接顯示方案的單價，指定了有加價的人員時確認頁比購物車少收。
	 * 加價只在客人真的指定了人員時才算（UAPPT_Booking::upcharge_for()）。
	 */
	Wizard.prototype.refreshTotal = function () {
		if ( ! this.totalRow ) {
			return;
		}
		var unit = Number( this.state.service.price ) || 0;
		var people = this.state.participants || 1;
		var adjust = this.state.staffId ? ( Number( this.state.staffAdjust ) || 0 ) : 0;

		var valueEl = this.totalRow.querySelector( '.uappt-wiz-confirm-value' );
		valueEl.textContent = money( unit * people + adjust );

		// 有乘人數或加價時列出算式，客人看得懂數字從哪來。
		var old = this.totalRow.querySelector( '.uappt-wiz-confirm-note' );
		if ( old ) {
			old.parentNode.removeChild( old );
		}
		if ( people > 1 || adjust > 0 ) {
			var parts = [ money( unit ) + ( people > 1 ? ' × ' + fmt( t( 'people' ), people ) : '' ) ];
			if ( adjust > 0 ) {
				parts.push( t( 'staffSurcharge' ) + ' ' + money( adjust ) );
			}
			valueEl.appendChild( el( 'span', 'uappt-wiz-confirm-note', parts.join( ' ＋ ' ) ) );
		}
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
			// 報名人數（v3.4.1）。UAPPT_Cart::validate_and_hold() 讀這個欄位，缺值就是 1。
			uappt_participants: this.state.participants || 1,
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
			parts.push( serviceLabel( this.state.service ) );
		} else if ( this.state.product ) {
			// 選了商品、還在選方案：先讓客人看到自己點了哪一項。
			parts.push( this.state.product.name );
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
						wiz.state.staffAdjust = Number( match.price_adjustment ) || 0;
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
