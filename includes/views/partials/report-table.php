<?php
/**
 * Partial：報表的通用表格（欄位由 builder 提供）。
 *
 * 「營收」與「人員」共用同一份表格——**欄位與資料 key 都來自 builder 的
 * `columns`／`fields`，這裡只負責「怎麼印一格」**。要加欄位請改 builder，
 * 不要在這裡另外算數字，那樣 CSV 就不會跟著有（v2.25.0 就定下的紀律）。
 *
 * 傳入變數：$report（['rows','columns','fields','totals']）、$uappt_pct。
 *
 * 選配（不設就維持原本行為，所以其他頁籤不受影響）：
 * - $uappt_no_totals   true ＝ 不印合計列（「Top 20」那種排行加總沒有意義）
 * - $uappt_sort_url    設了就把表頭變成排序連結（值是不含 orderby／order 的網址）
 * - $uappt_orderby / $uappt_order  目前的排序狀態，用來畫箭頭與決定下一次點擊的方向
 *
 * 異常標示走資料而不是走 view：builder 在每一列放 `flags`（欄位 => class）與
 * `flag_notes`（欄位 => 說明），這裡照著印就好——判斷邏輯留在 builder，CSV 也
 * 才有機會沿用同一套判斷。
 *
 * @package Ultimate_Appointments
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// 哪些欄位要當成金額印、哪些當成百分比印。用欄位名稱判斷而不是讓 builder 多帶
// 一份型別對照表：欄位名稱本來就唯一，多一份對照表就多一個會忘記同步的東西。
// 清單本身放在 UAPPT_Admin，因為 CSV 匯出也要用同一份（見 report_money_fields()）。
$uappt_money_fields = UAPPT_Admin::report_money_fields();
$uappt_rate_fields  = UAPPT_Admin::report_rate_fields();
// 合計列。`$uappt_no_totals` 由呼叫端設成 true 就不印——「Top 20 客人」那種
// 排行把前 20 名加起來沒有意義（它不是全體，只是被截斷的一段）。
$uappt_row_totals = ( empty( $uappt_no_totals ) && isset( $report['totals'] ) ) ? $report['totals'] : array();
?>
<?php $uappt_sortable = ! empty( $uappt_sort_url ); ?>
<?php // 捲動容器要包在表格外面：position: sticky 的參考對象是「可捲動的祖先」， ?>
<?php // 套在表格自己身上凍結欄一樣釘不住（見 admin.css 的 .uappt-table--matrix）。 ?>
<div class="uappt-table-scroll">
<table class="widefat striped uappt-table uappt-table--matrix">
	<thead>
		<tr>
			<?php foreach ( $report['columns'] as $uappt_i => $uappt_col ) : ?>
				<?php
				$uappt_field   = isset( $report['fields'][ $uappt_i ] ) ? $report['fields'][ $uappt_i ] : '';
				$uappt_is_sort = $uappt_sortable && $uappt_field;
				$uappt_active  = $uappt_is_sort && ( $uappt_orderby === $uappt_field );
				// 點目前這一欄就反向，點別欄一律從大到小開始——數字欄位九成的
				// 情況想先看最大的那幾筆。
				$uappt_next    = ( $uappt_active && 'desc' === $uappt_order ) ? 'asc' : 'desc';
				?>
				<th class="<?php echo $uappt_active ? 'is-sorted' : ''; ?>">
					<?php if ( $uappt_is_sort ) : ?>
						<a href="<?php echo esc_url( add_query_arg( array( 'orderby' => $uappt_field, 'order' => $uappt_next ), $uappt_sort_url ) ); ?>">
							<?php echo esc_html( $uappt_col ); ?>
							<span class="uappt-sort-arrow" aria-hidden="true"><?php echo $uappt_active ? ( 'desc' === $uappt_order ? '▼' : '▲' ) : '⇅'; ?></span>
						</a>
					<?php else : ?>
						<?php echo esc_html( $uappt_col ); ?>
					<?php endif; ?>
				</th>
			<?php endforeach; ?>
		</tr>
	</thead>
	<tbody>
		<?php if ( empty( $report['rows'] ) ) : ?>
			<tr><td colspan="<?php echo count( $report['columns'] ); ?>"><?php esc_html_e( '這段期間沒有符合條件的資料。', 'ultimate-appointments' ); ?></td></tr>
		<?php endif; ?>

		<?php foreach ( $report['rows'] as $uappt_row ) : ?>
			<tr>
				<?php foreach ( $report['fields'] as $uappt_i => $uappt_field ) : ?>
					<?php
					$uappt_value = isset( $uappt_row[ $uappt_field ] ) ? $uappt_row[ $uappt_field ] : '';
					$uappt_flag  = isset( $uappt_row['flags'][ $uappt_field ] ) ? $uappt_row['flags'][ $uappt_field ] : '';
					$uappt_note  = isset( $uappt_row['flag_notes'][ $uappt_field ] ) ? $uappt_row['flag_notes'][ $uappt_field ] : '';
					?>
					<td
						class="<?php echo esc_attr( $uappt_flag ); ?>"
						data-label="<?php echo esc_attr( $report['columns'][ $uappt_i ] ); ?>"
						<?php echo $uappt_note ? 'title="' . esc_attr( $uappt_note ) . '"' : ''; ?>
					>
						<?php if ( in_array( $uappt_field, $uappt_money_fields, true ) ) : ?>
							<?php echo wp_kses_post( wc_price( (float) $uappt_value ) ); ?>
						<?php elseif ( in_array( $uappt_field, $uappt_rate_fields, true ) ) : ?>
							<?php echo esc_html( $uappt_pct( $uappt_value ) ); ?>
						<?php else : ?>
							<?php echo esc_html( $uappt_value ); ?>
						<?php endif; ?>
					</td>
				<?php endforeach; ?>
			</tr>
		<?php endforeach; ?>
	</tbody>

	<?php if ( ! empty( $report['rows'] ) && ! empty( $uappt_row_totals ) ) : ?>
		<tfoot>
			<tr>
				<?php foreach ( $report['fields'] as $uappt_i => $uappt_field ) : ?>
					<?php
					// data-label 跟 tbody 的儲存格同樣要補：手機版把合計列也拆成
					// 直式，沒有標籤的話那一列會變成一串無名數字。
					//
					// 第一格例外——它的內容就是「合計」兩個字，再冠上「收款方式」
					// 這種欄名會變成「收款方式　合計」。不給 data-label，CSS 那條
					// `:not([data-label])::before { content: none }` 就會讓它成為
					// 整塊的標題。
					?>
					<th <?php echo 0 === $uappt_i ? '' : 'data-label="' . esc_attr( $report['columns'][ $uappt_i ] ) . '"'; ?>>
						<?php if ( 0 === $uappt_i ) : ?>
							<?php esc_html_e( '合計', 'ultimate-appointments' ); ?>
						<?php // ⚠️ totals 裡**沒有**這個欄位時要印「—」，不能印 wc_price(0)。 ?>
						<?php // 抽成試算的合計只有「抽成合計」有意義（跨月又跨人的業績 ?>
						<?php // 加總不是任何人要看的數字），其餘欄位刻意不給——印成 NT$0 ?>
						<?php // 會被讀成「這期間業績是零」。 ?>
						<?php elseif ( ! isset( $uappt_row_totals[ $uappt_field ] ) ) : ?>
							—
						<?php elseif ( in_array( $uappt_field, $uappt_money_fields, true ) ) : ?>
							<?php echo wp_kses_post( wc_price( (float) $uappt_row_totals[ $uappt_field ] ) ); ?>
						<?php elseif ( in_array( $uappt_field, $uappt_rate_fields, true ) ) : ?>
							<?php // 「佔比」類欄位的合計必然是 100%，印出來沒有資訊量。 ?>
							<?php echo in_array( $uappt_field, array( 'revenue_share', 'staff_share' ), true ) ? '—' : esc_html( $uappt_pct( isset( $uappt_row_totals[ $uappt_field ] ) ? $uappt_row_totals[ $uappt_field ] : 0 ) ); ?>
						<?php elseif ( isset( $uappt_row_totals[ $uappt_field ] ) && is_numeric( $uappt_row_totals[ $uappt_field ] ) ) : ?>
							<?php echo esc_html( $uappt_row_totals[ $uappt_field ] ); ?>
						<?php else : ?>
							—
						<?php endif; ?>
					</th>
				<?php endforeach; ?>
			</tr>
		</tfoot>
	<?php endif; ?>
</table>
</div>

<?php
// v2.59.0 之前這裡是一段警告：「合計列的不重複客人是把每一列加起來的，同一位
// 客人來了三天會被算成三位」。那個問題已經在 UAPPT_Admin::normalize_period_totals()
// 修掉了——合計列現在印的是整段期間去重一次的真值。
//
// 但**加不起來**這件事仍然需要說明：使用者把 21 天的客數加起來會得到 186，
// 合計卻寫 51，看起來像算錯。所以警告改成解釋，而不是拿掉。
?>
<?php if ( in_array( 'customer_count', $report['fields'], true ) && ! empty( $report['rows'] ) ) : ?>
	<p class="description uappt-hint">
		<?php esc_html_e( '「不重複客人」那一欄的合計不等於各列相加——同一位客人來了三天，逐列會算三次，合計則是整段期間去重一次的真正人數。其餘欄位都是單純加總。', 'ultimate-appointments' ); ?>
	</p>
<?php endif; ?>
