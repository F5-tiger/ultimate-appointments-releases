<?php
/**
 * Partial：報表「人員」頁籤的抽成試算（v2.63.0）。
 *
 * ⚠️ **這裡的「當月業績」不會等於上面人員總表的業績**，而且這是刻意的：
 * 抽成級距是以整個日曆月的業績判定的，跟報表選的統計區間無關。兩個數字
 * 並排卻不相等很容易被當成 bug，所以每一列都帶月份，面板上也寫明。
 *
 * 傳入變數：$staff_commission（build_commission_report() 的結果）、$uappt_pct。
 *
 * @package Ultimate_Appointments
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$uappt_comm_setup = ! empty( $staff_commission['extra']['has_setup'] );
?>
<div class="uappt-panel">
	<?php UAPPT_Admin::panel_head( 'user-check', __( '人員抽成試算', 'ultimate-appointments' ) ); ?>

	<?php if ( ! $uappt_comm_setup ) : ?>
		<div class="uappt-panel-body">
			<p class="description">
				<?php
				printf(
					/* translators: %s: 指向人員管理的連結 */
					esc_html__( '還沒有人員設定業績抽成級距。到 %s 編輯人員，在「業績抽成」那一段填入級距之後，這裡就會逐月算出抽成。', 'ultimate-appointments' ),
					'<a href="' . esc_url( UAPPT_Admin::url( 'staff' ) ) . '">' . esc_html__( '人員管理', 'ultimate-appointments' ) . '</a>'
				);
				?>
			</p>
		</div>
	<?php else : ?>
		<?php
		// 表格用共用的 partial，所以要把 $report 換過去再換回來——同一頁後面
		// 還有別的表格在用它。
		$uappt_comm_backup = $report;
		$report           = $staff_commission;
		// ⚠️ 排序連結要一起關掉再還原。人員總表在同一頁已經設了 $uappt_sort_url，
		// 不收掉的話這張表的表頭也會變成排序連結——而那些連結帶的是
		// orderby=month 這種人員總表根本不認得的欄位，按下去只會讓上面那張表
		// 跳回預設排序，看起來像壞掉。
		$uappt_comm_sort_backup = isset( $uappt_sort_url ) ? $uappt_sort_url : null;
		$uappt_sort_url         = '';
		require UAPPT_PLUGIN_DIR . 'includes/views/partials/report-table.php';
		$report                = $uappt_comm_backup;
		if ( null !== $uappt_comm_sort_backup ) {
			$uappt_sort_url = $uappt_comm_sort_backup;
		}
		?>
		<div class="uappt-panel-body">
			<p class="description">
				<?php esc_html_e( '⚠️ 「當月業績」是**整個日曆月**的數字，不是你選的統計區間——抽成級距本來就是按月結算的，看一週算不出適用哪一級。所以這張表的業績跟上面人員總表的業績不會一樣，兩者都沒有錯。', 'ultimate-appointments' ); ?>
			</p>
			<p class="description">
				<?php esc_html_e( '標「（暫估）」的是還沒結束的月份，數字會隨月底繼續累加。「最高級距」在累進制底下指的是這個月觸及到的最高那一級，不是整筆適用的比例。', 'ultimate-appointments' ); ?>
			</p>
			<p class="description">
				<?php esc_html_e( '⚠️ 這是試算，供管理參考，不是薪資單：不含底薪保障、全勤與各項獎金，也沒有跨月結算或預扣。指定加價走自己的分成比例，不參與級距判定。', 'ultimate-appointments' ); ?>
			</p>
		</div>
	<?php endif; ?>
</div>
