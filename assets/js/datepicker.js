/**
 * 日期選擇器：把本外掛的原生日期欄位換成 flatpickr，跟終極電商同一個樣子，月份星期用中文。
 *
 * markup 照舊寫 `type="date"`，由這裡換成 flatpickr：JS 沒載入（被快取外掛合併壞、佈景
 * 報錯）時退回瀏覽器原生的日期欄位，功能不會壞。換的時候把 type 改成 text、加上
 * `.uappt-date`——admin.css 裡原本對 `input[type="date"]` 寫的規則要一起認這個 class。
 *
 * 不改任何資料格式：值一樣是 `Y-m-d`、`min`／`max` 屬性照樣生效、選完日期 flatpickr
 * 會對 input 觸發原生的 `change`，既有的 `on( 'change' )` 與 `onchange="this.form.submit()"`
 * 都不用動。
 *
 * ⚠️ 程式要改日期時用 `UAPPT_Datepicker.setValue()`，不要直接寫 `.value`：直接寫的話
 * 輸入框看起來對了，flatpickr 內部卻還停在舊日期，打開月曆會跳回去。
 *
 * @package Ultimate_Appointments
 */

( function () {
	'use strict';

	/**
	 * flatpickr 的語系只是兩組名稱陣列，自己寫一份就好，不需要另外下載語系檔。
	 * 一週從星期日開始，跟終極電商（flatpickr 預設）一致。
	 */
	var ZH_TW = {
		weekdays: {
			shorthand: [ '日', '一', '二', '三', '四', '五', '六' ],
			longhand: [ '星期日', '星期一', '星期二', '星期三', '星期四', '星期五', '星期六' ],
		},
		months: {
			shorthand: [ '1月', '2月', '3月', '4月', '5月', '6月', '7月', '8月', '9月', '10月', '11月', '12月' ],
			longhand: [ '一月', '二月', '三月', '四月', '五月', '六月', '七月', '八月', '九月', '十月', '十一月', '十二月' ],
		},
		firstDayOfWeek: 0,
		rangeSeparator: ' 至 ',
		weekAbbreviation: '週',
		scrollTitle: '滾動切換',
		toggleTitle: '點擊切換',
		time_24hr: true,
	};

	// 只換本外掛自己的欄位：前台商品頁可能還有佈景或其他外掛的日期欄位，不能順手改掉。
	var SELECTOR = '.uappt-wrap input[type="date"], .uappt-booking-widget input[type="date"]';

	/**
	 * 把一個日期欄位換成 flatpickr。重複呼叫是安全的（已經換過就直接回傳那個實例）。
	 *
	 * @param {HTMLInputElement} input 日期欄位。
	 * @return {Object|null} flatpickr 實例；flatpickr 沒載入時回 null（欄位維持原生）。
	 */
	function init( input ) {
		if ( ! input ) {
			return null;
		}
		if ( input._flatpickr ) {
			return input._flatpickr;
		}
		if ( typeof window.flatpickr !== 'function' ) {
			return null;
		}

		var min = input.getAttribute( 'min' );
		var max = input.getAttribute( 'max' );

		input.setAttribute( 'type', 'text' );
		input.setAttribute( 'autocomplete', 'off' );
		input.classList.add( 'uappt-date' );

		var fp = window.flatpickr( input, {
			locale: ZH_TW,
			dateFormat: 'Y-m-d',
			// 每一格的 aria-label。預設是英文順序「F j, Y」，套上中文月份會變成「十月 4, 2026」。
			ariaDateFormat: 'Y 年 n 月 j 日',
			minDate: min || null,
			maxDate: max || null,
			// 手機也用 flatpickr：預設會退回原生選擇器，那樣手機上不但長得不一樣，前台
			// 「沒空的日子灰掉」也會失效（原生欄位沒辦法逐日停用）。
			disableMobile: true,
		} );

		if ( input.hasAttribute( 'data-uappt-clearable' ) ) {
			addClearButton( input, fp );
		}

		return fp;
	}

	/**
	 * 選填欄位旁邊的「×」。
	 *
	 * flatpickr 的輸入框是唯讀的（不然手機點下去會跳鍵盤），沒辦法用鍵盤把日期刪掉；
	 * 篩選期間、公休日期這類可以留空的欄位一定要有地方清。跟終極電商折扣規則旁邊的
	 * 「清除」是同一個意思。
	 *
	 * 刻意做成 input 的下一個兄弟節點、不另外包一層：admin.css 有幾條 `> input` 的子代
	 * 選擇器（篩選列的寬度與等分），多包一層就全部對不上。
	 */
	function addClearButton( input, fp ) {
		var btn = document.createElement( 'button' );
		btn.type = 'button';
		btn.className = 'uappt-date-clear';
		btn.textContent = '×';
		btn.setAttribute( 'aria-label', '清除日期' );
		btn.title = '清除日期';
		btn.hidden = '' === input.value;

		btn.addEventListener( 'click', function () {
			fp.clear(); // 會觸發 change，跟手動選日期走同一條路。
		} );

		fp.config.onChange.push( function () {
			btn.hidden = '' === input.value;
		} );

		input.classList.add( 'has-clear' );
		input.insertAdjacentElement( 'afterend', btn );
	}

	/**
	 * 用程式設定日期（例如人員編輯頁點月曆上的一天，帶進請假表單）。
	 *
	 * @param {HTMLInputElement} input 日期欄位。
	 * @param {string}           value Y-m-d；空字串代表清空。
	 */
	function setValue( input, value ) {
		var fp = init( input );
		if ( ! fp ) {
			input.value = value;
			return;
		}
		if ( value ) {
			fp.setDate( value, false );
		} else {
			fp.clear( false );
		}
		// 不觸發 change：呼叫端是程式，不是使用者選了日期。清除鈕的顯示要自己同步。
		var btn = input.nextElementSibling;
		if ( btn && btn.classList.contains( 'uappt-date-clear' ) ) {
			btn.hidden = '' === input.value;
		}
	}

	function initAll( root ) {
		Array.prototype.forEach.call( ( root || document ).querySelectorAll( SELECTOR ), init );
	}

	window.UAPPT_Datepicker = {
		init: init,
		initAll: initAll,
		setValue: setValue,
	};

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', function () {
			initAll();
		} );
	} else {
		initAll();
	}
}() );
