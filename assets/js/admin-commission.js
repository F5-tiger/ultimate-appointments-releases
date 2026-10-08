/**
 * 人員編輯頁「業績抽成」級距列的增減（v2.63.0）。
 *
 * 跟收款方式設定同一個模式（assets/js/admin-payment.js）：沒有 JS 時這一段
 * 仍然完整可用——PHP 永遠多印一列空白供新增，移除則是把比例清空再存檔
 * （清洗時 0% 的列會被丟掉）。這支只是讓那兩件事順手一點。
 */
( function () {
	'use strict';

	function reindex( row, index ) {
		Array.prototype.forEach.call(
			row.querySelectorAll( '[name^="commission_tiers["]' ),
			function ( field ) {
				field.name = field.name.replace(
					/^commission_tiers\[\d+\]/,
					'commission_tiers[' + index + ']'
				);
			}
		);
	}

	/**
	 * 下一個沒用過的索引。
	 *
	 * 不用「列數」：中間移除過列之後，列數會跟已存在的索引撞號，PHP 端就會
	 * 用同一個鍵覆蓋掉前一列。
	 *
	 * @param {HTMLElement} body tbody。
	 * @return {number} 索引。
	 */
	function nextIndex( body ) {
		var max = -1;
		Array.prototype.forEach.call(
			body.querySelectorAll( '[name^="commission_tiers["]' ),
			function ( field ) {
				var m = field.name.match( /^commission_tiers\[(\d+)\]/ );
				if ( m ) {
					max = Math.max( max, parseInt( m[ 1 ], 10 ) );
				}
			}
		);
		return max + 1;
	}

	function init() {
		var body = document.querySelector( '.uappt-tier-body' );
		if ( ! body ) {
			return;
		}

		var add = document.querySelector( '.uappt-tier-add' );
		if ( add ) {
			add.addEventListener( 'click', function () {
				var rows = body.querySelectorAll( '.uappt-tier-row' );
				if ( ! rows.length ) {
					return;
				}
				var clone = rows[ rows.length - 1 ].cloneNode( true );
				reindex( clone, nextIndex( body ) );
				Array.prototype.forEach.call( clone.querySelectorAll( 'input' ), function ( i ) {
					i.value = '';
				} );
				body.appendChild( clone );
				var first = clone.querySelector( 'input' );
				if ( first ) {
					first.focus();
				}
			} );
		}

		body.addEventListener( 'click', function ( e ) {
			var btn = e.target.closest( '.uappt-tier-remove' );
			if ( ! btn ) {
				return;
			}
			var row = btn.closest( '.uappt-tier-row' );
			if ( ! row ) {
				return;
			}
			// 最後一列不真的移除、只清空：整張表沒有列時，新增按鈕就沒有
			// 可以複製的樣板了。
			if ( body.querySelectorAll( '.uappt-tier-row' ).length <= 1 ) {
				Array.prototype.forEach.call( row.querySelectorAll( 'input' ), function ( i ) {
					i.value = '';
				} );
				return;
			}
			row.parentNode.removeChild( row );
		} );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
