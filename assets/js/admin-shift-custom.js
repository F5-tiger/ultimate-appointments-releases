/**
 * 班別列上的「自訂時段…」（v2.100.0）。人員編輯頁的月曆與全店月排班表共用。
 *
 * 計畫見 docs/shift-ui-plan.md 的 D4，標記見 includes/views/partials/shift-custom.php。
 *
 * ⚠️ **這支不排班。** 它只把時段填進面板裡那顆藏起來的 `.uappt-shift`（painter）、再替
 * 使用者按下去——兩邊頁面本來就會處理「按了班別鈕」（排上去、可以還原、只送改過的格子、
 * 離開前提醒）。用原生 click() 而不是 jQuery 的 trigger()：人員編輯頁的處理器是 jQuery
 * 委派、月排班表是原生 addEventListener，原生事件兩邊都收得到；反過來 trigger() 不會
 * 觸發原生監聽器（v2.88.x 踩過）。
 *
 * 勾「存成班別」時：
 * - 班別列上立刻多一顆新的班別鈕（可以接著排別的日子）；
 * - 表單多帶 `new_presets[n][name|start|end|color]`，按「儲存這個月」時跟排好的日子一起存
 *   （UAPPT_Admin::save_new_presets_from_post()）。
 *
 * 時間容錯跟每週樣板同一套（「930」→「09:30」）；真正的驗證仍在伺服器
 * （UAPPT_Staff::sanitize_ranges()），這裡只擋明顯沒填好的，讓錯誤當場看得到。
 */
( function () {
	'use strict';

	function pad( n ) {
		return ( n < 10 ? '0' : '' ) + n;
	}

	// 跟 admin-staff.js 的 formatTime() 同一套規則（那支包在 jQuery 的閉包裡拿不到）。
	function formatTime( raw ) {
		var v = String( raw || '' ).trim();
		var m = v.match( /^(\d{1,2}):(\d{1,2})$/ );
		if ( m ) {
			return ( +m[ 1 ] <= 23 && +m[ 2 ] <= 59 ) ? pad( +m[ 1 ] ) + ':' + pad( +m[ 2 ] ) : v;
		}
		if ( /^\d{1,4}$/.test( v ) ) {
			var h = v.length <= 2 ? +v : ( 3 === v.length ? +v.slice( 0, 1 ) : +v.slice( 0, 2 ) );
			var i = v.length <= 2 ? 0 : ( 3 === v.length ? +v.slice( 1 ) : +v.slice( 2 ) );
			if ( h <= 23 && i <= 59 ) {
				return pad( h ) + ':' + pad( i );
			}
		}
		return v;
	}

	var VALID = /^([01]\d|2[0-3]):[0-5]\d$/;
	var added = 0;

	function init( panel ) {
		var pick    = panel.closest( '.uappt-shift-pick' );
		var form    = panel.closest( 'form' );
		var toggle  = pick ? pick.querySelector( '.uappt-shift-custom-toggle' ) : null;
		var start   = panel.querySelector( '.uappt-custom-start' );
		var end     = panel.querySelector( '.uappt-custom-end' );
		var check   = panel.querySelector( '.uappt-custom-save-check' );
		var nameBox = panel.querySelector( '.uappt-custom-name-wrap' );
		var name    = panel.querySelector( '.uappt-custom-name' );
		var error   = panel.querySelector( '.uappt-custom-error' );
		var painter = panel.querySelector( '.uappt-custom-painter' );
		var colors  = [];
		try {
			colors = JSON.parse( panel.getAttribute( 'data-colors' ) || '[]' );
		} catch ( err ) {
			colors = [];
		}

		if ( ! pick || ! form || ! toggle || ! painter ) {
			return;
		}

		function setOpen( open ) {
			panel.hidden = ! open;
			toggle.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
			toggle.classList.toggle( 'is-open', open );
			if ( open ) {
				start.focus();
			}
		}

		function showError( msg ) {
			error.textContent = msg;
			error.hidden = ! msg;
		}

		function reset() {
			start.value = '';
			end.value = '';
			check.checked = false;
			name.value = '';
			nameBox.hidden = true;
			showError( '' );
			setOpen( false );
		}

		// 已經在班別列上的班別名稱（含這一頁剛存成班別、還沒按儲存的）。
		function existingNames() {
			return Array.prototype.map.call(
				pick.querySelectorAll( '.uappt-shift--hours:not(.uappt-custom-painter) .uappt-shift-name' ),
				function ( el ) {
					return el.textContent.trim();
				}
			);
		}

		// 新班別配一個還沒被用掉的顏色——跟伺服器的 fill_colors() 同一條規則，存檔後顏色
		// 才不會跳。
		function nextColor() {
			var used = Array.prototype.map.call(
				pick.querySelectorAll( '.uappt-shift--hours[data-uappt-color]:not(.uappt-custom-painter)' ),
				function ( el ) {
					return el.getAttribute( 'data-uappt-color' );
				}
			);
			var free = colors.filter( function ( c ) {
				return used.indexOf( c ) < 0;
			} );
			return free.length ? free[ 0 ] : ( colors[ used.length % ( colors.length || 1 ) ] || '' );
		}

		function hidden( key, value ) {
			var input = document.createElement( 'input' );
			input.type = 'hidden';
			input.name = 'new_presets[' + added + '][' + key + ']';
			input.value = value;
			form.appendChild( input );
		}

		toggle.addEventListener( 'click', function () {
			setOpen( panel.hidden );
		} );

		check.addEventListener( 'change', function () {
			nameBox.hidden = ! check.checked;
			if ( check.checked ) {
				name.focus();
			}
		} );

		[ start, end ].forEach( function ( input ) {
			input.addEventListener( 'blur', function () {
				input.value = formatTime( input.value );
			} );
			// 在時間欄位按 Enter＝套用；不攔的話會把整張表單送出去。
			input.addEventListener( 'keydown', function ( e ) {
				if ( 'Enter' === e.key ) {
					e.preventDefault();
					panel.querySelector( '.uappt-custom-apply' ).click();
				}
			} );
		} );

		name.addEventListener( 'keydown', function ( e ) {
			if ( 'Enter' === e.key ) {
				e.preventDefault();
				panel.querySelector( '.uappt-custom-apply' ).click();
			}
		} );

		panel.querySelector( '.uappt-custom-apply' ).addEventListener( 'click', function () {
			var s = formatTime( start.value );
			var t = formatTime( end.value );
			start.value = s;
			end.value = t;

			if ( ! VALID.test( s ) || ! VALID.test( t ) ) {
				showError( panel.getAttribute( 'data-msg-time' ) );
				return;
			}
			if ( s === t ) {
				showError( panel.getAttribute( 'data-msg-same' ) );
				return;
			}

			var saving = check.checked;
			var title  = name.value.trim();
			if ( saving && ! title ) {
				showError( panel.getAttribute( 'data-msg-name' ) );
				name.focus();
				return;
			}
			if ( saving && existingNames().indexOf( title ) >= 0 ) {
				showError( ( panel.getAttribute( 'data-msg-dup' ) || '%s' ).replace( '%s', title ) );
				name.focus();
				return;
			}

			var range  = s + '–' + t;
			var ranges = JSON.stringify( [ [ s, t ] ] );
			var color  = saving ? nextColor() : '';

			// 存成班別：班別列上立刻多一顆，表單帶上要新增的班別。
			if ( saving ) {
				var btn = document.createElement( 'button' );
				btn.type = 'button';
				btn.className = 'uappt-shift uappt-shift--hours' + ( color ? ' has-shift uappt-shift-c-' + color : '' );
				btn.setAttribute( 'data-uappt-shift', 'hours' );
				btn.setAttribute( 'data-uappt-ranges', ranges );
				btn.setAttribute( 'data-uappt-long', title + ' ' + range );
				// 標籤明寫：這顆鈕的兩個 span 是 JS 接起來的，中間沒有空白，從按鈕文字拼回來
				// 會變成「中班11:00–15:00」。
				btn.setAttribute( 'data-uappt-label', title + ' ' + range );
				btn.setAttribute( 'data-uappt-short', title );
				if ( color ) {
					btn.setAttribute( 'data-uappt-color', color );
				}
				var n = document.createElement( 'span' );
				n.className = 'uappt-shift-name';
				n.textContent = title;
				var tm = document.createElement( 'span' );
				tm.className = 'uappt-shift-time';
				tm.textContent = range;
				btn.appendChild( n );
				btn.appendChild( tm );
				pick.insertBefore( btn, toggle );

				hidden( 'name', title );
				hidden( 'start', s );
				hidden( 'end', t );
				hidden( 'color', color );
				added++;
			}

			// 排上去：填好那顆藏起來的鈕再按。標籤跟伺服器的 plan_label() 一致——
			// 班別印「名稱 時段」、短標籤印名稱；不是班別印時段、短標籤印開始時間。
			painter.setAttribute( 'data-uappt-ranges', ranges );
			painter.setAttribute( 'data-uappt-label', saving ? title + ' ' + range : range );
			painter.setAttribute( 'data-uappt-long', saving ? title + ' ' + range : range );
			painter.setAttribute( 'data-uappt-short', saving ? title : s );
			if ( color ) {
				painter.setAttribute( 'data-uappt-color', color );
			} else {
				painter.removeAttribute( 'data-uappt-color' );
			}
			painter.querySelector( '.uappt-shift-name' ).textContent = saving ? title : s;

			reset();
			painter.click();
		} );
	}

	document.querySelectorAll( '.uappt-shift-custom' ).forEach( init );
}() );
