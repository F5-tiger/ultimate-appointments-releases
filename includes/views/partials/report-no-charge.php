<?php
/**
 * Partial：報表「人員」頁籤的未收費／招待明細（v2.64.0）。
 *
 * 這張表存在的理由很具體：招待的單**材料照扣、業績卻是 0**，於是那一筆的
 * 毛利是負的——在報表上跟其他單混在一起，看到的人只會覺得「這位人員成本
 * 怎麼特別高」。標記原因之後，被拉低的那一塊有了歸屬。
 *
 * 欄位對齊實體店家原本手寫的那張表（日期／姓名／療程／耗材／因素／關係）。
 *
 * 傳入變數：$staff_no_charge、$report、$uappt_pct。
 *
 * @package Ultimate_Appointments
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$uappt_nc_rows   = isset( $staff_no_charge['rows'] ) ? $staff_no_charge['rows'] : array();
$uappt_nc_reason = isset( $staff_no_charge['extra']['by_reason'] ) ? $staff_no_charge['extra']['by_reason'] : array();
$uappt_nc_cost   = isset( $staff_no_charge['extra']['total_cost'] ) ? (float) $staff_no_charge['extra']['total_cost'] : 0.0;
?>
<div class="uappt-panel">
	<?php UAPPT_Admin::panel_head( 'pencil', __( '未收費／招待', 'ultimate-appointments' ) ); ?>

	<?php if ( ! $uappt_nc_rows ) : ?>
		<div class="uappt-panel-body">
			<p class="description">
				<?php esc_html_e( '這段期間沒有標記為未收費的預約。做了服務但沒收錢時（招待、員工親友、重做補償…），到那筆預約的「金額與收款」裡選一個原因——不標記的話，那一筆的材料成本會混進該人員的毛利裡，看報表的人只會覺得這個人成本特別高。', 'ultimate-appointments' ); ?>
			</p>
		</div>
	<?php else : ?>
		<div class="uappt-panel-body">
			<div class="uappt-dash-cards">
				<div class="uappt-dash-card is-static">
					<span class="uappt-dash-card-value"><?php echo esc_html( count( $uappt_nc_rows ) ); ?></span>
					<span class="uappt-dash-card-label"><?php esc_html_e( '未收費筆數', 'ultimate-appointments' ); ?></span>
				</div>
				<div class="uappt-dash-card is-static <?php echo $uappt_nc_cost > 0 ? 'is-warning' : ''; ?>">
					<span class="uappt-dash-card-value"><?php echo wp_kses_post( wc_price( $uappt_nc_cost ) ); ?></span>
					<span class="uappt-dash-card-label"><?php esc_html_e( '吃掉的材料成本', 'ultimate-appointments' ); ?></span>
					<span class="uappt-dash-card-sub"><?php esc_html_e( '這筆錢真的花掉了，但沒有對應的業績', 'ultimate-appointments' ); ?></span>
				</div>
			</div>

			<?php
			// 原因分布：一眼看出是「偶爾招待熟客」還是「重做特別多」——後者
			// 是品質問題，跟招待完全是兩回事，混在一個數字裡看不出來。
			$uappt_bar_items  = array();
			$uappt_bar_counts = array();
			foreach ( $uappt_nc_reason as $uappt_nc_label => $uappt_nc_data ) {
				$uappt_bar_items[ $uappt_nc_label ]  = $uappt_nc_label;
				$uappt_bar_counts[ $uappt_nc_label ] = $uappt_nc_data['count'];
			}
			$uappt_bar_money = false;
			?>
			<?php if ( count( $uappt_bar_items ) > 1 ) : ?>
				<?php require UAPPT_PLUGIN_DIR . 'includes/views/partials/report-bars.php'; ?>
			<?php endif; ?>
		</div>

		<?php
		// 表格用共用的 partial：換掉 $report 再換回來，排序連結也要收掉
		// （理由同抽成試算，見 report-commission.php）。
		$uappt_nc_backup      = $report;
		$uappt_nc_sort_backup = isset( $uappt_sort_url ) ? $uappt_sort_url : null;
		$report              = $staff_no_charge;
		$uappt_sort_url       = '';
		require UAPPT_PLUGIN_DIR . 'includes/views/partials/report-table.php';
		$report              = $uappt_nc_backup;
		if ( null !== $uappt_nc_sort_backup ) {
			$uappt_sort_url = $uappt_nc_sort_backup;
		}
		?>

		<div class="uappt-panel-body">
			<p class="description">
				<?php esc_html_e( '⚠️ 這幾筆**仍然計入**操作筆數、時段利用率與材料成本——服務確實做了、材料確實用了，把它們從報表裡拿掉會讓成本憑空消失。這張表的作用是讓那一塊被拉低的毛利有歸屬，不是把它藏起來。', 'ultimate-appointments' ); ?>
			</p>
			<p class="description">
				<?php esc_html_e( '「重做／補做」如果佔比偏高，那是品質或溝通的問題，跟「招待熟客」完全是兩回事——混在同一個數字裡看不出來，所以原因要分開記。', 'ultimate-appointments' ); ?>
			</p>
		</div>
	<?php endif; ?>
</div>
