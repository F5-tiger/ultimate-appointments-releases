<?php
/**
 * Partial：人員 × 項目（每位人員一個可折疊區塊）。
 *
 * **畫面用分組明細、CSV 用逐列**（見 docs/reports-v2-plan.md 的 D4）。不做
 * 矩陣（人員為列、項目為欄）：美業的項目數通常 10–30 個，矩陣一定要橫向捲動、
 * 手機幾乎不能看；真要跨人員比較，匯出的 CSV 丟進 Excel 樞紐分析更好用。
 *
 * `$staff_services['rows']` 是**逐列**的（CSV 直接用同一份），這裡才依
 * `staff_id` 分組——兩邊同一份資料，不各自查一次。
 *
 * 傳入變數：$staff_services、$uappt_pct。
 *
 * @package Ultimate_Appointments
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$uappt_grouped = array();
foreach ( $staff_services['rows'] as $uappt_ss_row ) {
	$uappt_grouped[ $uappt_ss_row['staff_id'] ]['name']    = $uappt_ss_row['staff_name'];
	$uappt_grouped[ $uappt_ss_row['staff_id'] ]['rows'][]  = $uappt_ss_row;
}
?>
<div class="uappt-panel">
	<?php UAPPT_Admin::panel_head( 'clipboard-list', __( '人員 × 服務項目', 'ultimate-appointments' ) ); ?>
	<div class="uappt-panel-body">
		<p class="description">
			<?php esc_html_e( '每位人員實際操作了哪些項目。「佔該人員業績」的分母是他自己的業績，不是全店的——這一欄回答的是「他主要在做什麼」，而不是「他佔全店多少」。', 'ultimate-appointments' ); ?>
		</p>
		<?php // 人員一多時，全部展開會讓整頁爆炸（15 位就是 15 張表疊在一起）。 ?>
		<?php // 收合狀態下每人只佔一行，而那一行本來就帶了項目數／筆數／業績， ?>
		<?php // 不損失任何資訊。 ?>
		<p>
			<button type="button" class="button" id="uappt-toggle-staff-services" data-expand="<?php esc_attr_e( '全部展開', 'ultimate-appointments' ); ?>" data-collapse="<?php esc_attr_e( '全部收合', 'ultimate-appointments' ); ?>">
				<?php esc_html_e( '全部展開', 'ultimate-appointments' ); ?>
			</button>
		</p>
	</div>

	<?php if ( empty( $uappt_grouped ) ) : ?>
		<div class="uappt-panel-body">
			<p class="description"><?php esc_html_e( '這段期間沒有符合條件的資料。', 'ultimate-appointments' ); ?></p>
		</div>
	<?php endif; ?>

	<?php foreach ( $uappt_grouped as $uappt_sid => $uappt_group ) : ?>
		<?php
		$uappt_group_revenue = 0.0;
		$uappt_group_ops     = 0;
		foreach ( $uappt_group['rows'] as $uappt_r ) {
			$uappt_group_revenue += $uappt_r['revenue'];
			$uappt_group_ops     += $uappt_r['op_count'];
		}
		?>
		<?php // **預設收合**：summary 那一行已經帶了「N 個項目・M 筆・業績」， ?>
		<?php // 收起來完全不損失資訊，而人員一多（15 位以上）全部展開整頁會爆炸。 ?>
		<details class="uappt-staff-services">
			<summary>
				<strong><?php echo esc_html( $uappt_group['name'] ); ?></strong>
				<span class="uappt-staff-services-summary">
					<?php
					printf(
						/* translators: 1: 項目數 2: 操作筆數 3: 業績 */
						esc_html__( '%1$d 個項目・%2$d 筆・%3$s', 'ultimate-appointments' ),
						count( $uappt_group['rows'] ),
						(int) $uappt_group_ops,
						wp_strip_all_tags( wc_price( $uappt_group_revenue ) )
					);
					?>
				</span>
			</summary>

			<div class="uappt-table-scroll">
<table class="widefat striped uappt-table uappt-table--matrix">
				<thead>
					<tr>
						<th><?php esc_html_e( '服務項目', 'ultimate-appointments' ); ?></th>
						<th><?php esc_html_e( '操作筆數', 'ultimate-appointments' ); ?></th>
						<th><?php esc_html_e( '服務人次', 'ultimate-appointments' ); ?></th>
						<th><?php esc_html_e( '業績', 'ultimate-appointments' ); ?></th>
						<th><?php esc_html_e( '佔該人員業績', 'ultimate-appointments' ); ?></th>
						<th><?php esc_html_e( '平均客單價', 'ultimate-appointments' ); ?></th>
						<th><?php esc_html_e( '材料成本', 'ultimate-appointments' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $uappt_group['rows'] as $uappt_r ) : ?>
						<tr>
							<td data-label="<?php esc_attr_e( '服務項目', 'ultimate-appointments' ); ?>"><?php echo esc_html( $uappt_r['service_name'] ); ?></td>
							<td data-label="<?php esc_attr_e( '操作筆數', 'ultimate-appointments' ); ?>"><?php echo esc_html( $uappt_r['op_count'] ); ?></td>
							<td data-label="<?php esc_attr_e( '服務人次', 'ultimate-appointments' ); ?>"><?php echo esc_html( $uappt_r['person_count'] ); ?></td>
							<td data-label="<?php esc_attr_e( '業績', 'ultimate-appointments' ); ?>"><?php echo wp_kses_post( wc_price( $uappt_r['revenue'] ) ); ?></td>
							<td data-label="<?php esc_attr_e( '佔該人員業績', 'ultimate-appointments' ); ?>">
								<?php // 長條讓「他主要在做什麼」不用讀數字就看得出來。 ?>
								<span class="uappt-share">
									<span class="uappt-share-bar" style="width: <?php echo esc_attr( round( $uappt_r['staff_share'] * 100, 1 ) ); ?>%;"></span>
									<span class="uappt-share-text"><?php echo esc_html( $uappt_pct( $uappt_r['staff_share'] ) ); ?></span>
								</span>
							</td>
							<td data-label="<?php esc_attr_e( '平均客單價', 'ultimate-appointments' ); ?>"><?php echo wp_kses_post( wc_price( $uappt_r['avg_price'] ) ); ?></td>
							<td data-label="<?php esc_attr_e( '材料成本', 'ultimate-appointments' ); ?>"><?php echo wp_kses_post( wc_price( $uappt_r['material_cost'] ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
</div>
		</details>
	<?php endforeach; ?>
</div>
