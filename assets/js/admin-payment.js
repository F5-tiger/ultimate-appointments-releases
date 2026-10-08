/**
 * 設定頁「收款設定」的列增減（v2.58.0）。
 *
 * 沒有 JS 時這一頁仍然完整可用：PHP 永遠多印一列空白供新增，移除則是把名稱
 * 清空再存檔（清洗時名稱空白的列會被丟掉）。這支只是讓那兩件事順手一點。
 *
 * 原生 DOM API，不依賴 jQuery——設定頁其他腳本（admin-settings.js）也是這樣。
 */
( function () {
	'use strict';

	/**
	 * 把一列裡所有欄位名稱的索引換掉。
	 *
	 * 表單欄位是 payment_methods[3][label] 這種形式，複製出來的新列必須換成
	 * 沒有用過的索引，否則 PHP 端會用同一個鍵覆蓋掉前一列。
	 *
	 * @param {HTMLElement} row   列。
	 * @param {number}      index 新索引。
	 */
	function reindex( row, index ) {
		var fields = row.querySelectorAll( '[name^="payment_methods["]' );
		Array.prototype.forEach.call( fields, function ( field ) {
			field.name = field.name.replace(
				/^payment_methods\[\d+\]/,
				'payment_methods[' + index + ']'
			);
		} );
	}

	/**
	 * 目前用到的最大索引 + 1。
	 *
	 * 不用「列數」當新索引：中間移除過列之後，列數會跟已存在的索引撞號。
	 *
	 * @param {HTMLElement} body tbody。
	 * @return {number} 下一個可用索引。
	 */
	function nextIndex( body ) {
		var max = -1;
		var fields = body.querySelectorAll( '[name^="payment_methods["]' );
		Array.prototype.forEach.call( fields, function ( field ) {
			var match = field.name.match( /^payment_methods\[(\d+)\]/ );
			if ( match ) {
				max = Math.max( max, parseInt( match[ 1 ], 10 ) );
			}
		} );
		return max + 1;
	}

	function init() {
		var body = document.querySelector( '.uappt-pm-body' );
		if ( ! body ) {
			return;
		}

		var addButton = document.querySelector( '.uappt-pm-add' );
		if ( addButton ) {
			addButton.addEventListener( 'click', function () {
				var rows = body.querySelectorAll( '.uappt-pm-row' );
				if ( ! rows.length ) {
					return;
				}

				// 複製最後一列（PHP 印出來的那列空白）而不是自己拼 HTML：
				// 欄位結構只定義在 PHP 一處，之後加欄位這裡不用跟著改。
				var clone = rows[ rows.length - 1 ].cloneNode( true );
				reindex( clone, nextIndex( body ) );

				// 複製來源可能已經被填過內容，一律清空。
				Array.prototype.forEach.call(
					clone.querySelectorAll( 'input' ),
					function ( input ) {
						input.value = '';
					}
				);
				Array.prototype.forEach.call(
					clone.querySelectorAll( 'select' ),
					function ( select ) {
						select.selectedIndex = 0;
					}
				);

				body.appendChild( clone );

				// 新增後把游標放進名稱欄：按下按鈕的下一個動作一定是打字。
				var label = clone.querySelector( 'input[type="text"]' );
				if ( label ) {
					label.focus();
				}
			} );
		}

		// 事件委派：新增出來的列不用各自綁一次。
		body.addEventListener( 'click', function ( event ) {
			var button = event.target.closest( '.uappt-pm-remove' );
			if ( ! button ) {
				return;
			}

			var row = button.closest( '.uappt-pm-row' );
			if ( ! row ) {
				return;
			}

			// 最後一列不真的移除，只清空——整張表沒有任何列時，連新增按鈕要
			// 複製的樣板都沒有了。
			if ( body.querySelectorAll( '.uappt-pm-row' ).length <= 1 ) {
				Array.prototype.forEach.call(
					row.querySelectorAll( 'input[type="text"], input[type="number"]' ),
					function ( input ) {
						input.value = '';
					}
				);
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
