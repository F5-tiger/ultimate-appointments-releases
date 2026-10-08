<?php
/**
 * Partial：報表「預約」頁籤。
 *
 * 回答四件事：什麼時候滿（星期 × 時段熱度）、漏掉了多少（取消與未到）、
 * 客人多早訂（預約前置期）、還能多做多少（產能缺口）。
 *
 * 傳入變數：$report、$uappt_pct。
 *
 * @package Ultimate_Appointments
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$uappt_extra  = $report['extra'];
$uappt_matrix = $uappt_extra['matrix'];
$uappt_cancel = $uappt_extra['cancellation'];
$uappt_lead   = $uappt_extra['lead_time'];
$uappt_source = $uappt_extra['source'];
$uappt_cap    = $uappt_extra['capacity'];
$uappt_max    = max( 1, (int) $uappt_extra['max_count'] );

/**
 * 依「相對於最忙那一格」決定濃淡等級。
 *
 * ⚠️ 這裡用**相對比例**，跟「本月明細」的月曆刻意相反（那邊用固定門檻）：
 * 兩者問的問題不同。月曆問「這天忙不忙」，要能跨月比較，所以門檻必須固定；
 * 這張矩陣問「一週之內哪個時段最滿」，本來就是同一段期間內部的相對關係，
 * 用固定門檻的話冷門時段的店會整片空白、旺店會整片全滿，什麼都看不出來。
 *
 * @param int $count 這一格的筆數。
 * @param int $max   期間內最忙那一格的筆數。
 * @return int 0–4
 */
$uappt_heat_level = function ( $count, $max ) {
	if ( $count <= 0 ) {
		return 0;
	}
	$ratio = $count / $max;
	if ( $ratio <= 0.25 ) {
		return 1;
	}
	if ( $ratio <= 0.5 ) {
		return 2;
	}
	return $ratio <= 0.75 ? 3 : 4;
};
?>
<div class="uappt-panel">
	<?php UAPPT_Admin::panel_head( 'clock', __( '時段熱度', 'ultimate-appointments' ) ); ?>
	<div class="uappt-panel-body">
		<?php if ( 0 === (int) $uappt_extra['max_count'] ) : ?>
			<p class="description"><?php esc_html_e( '這段期間沒有符合條件的預約。', 'ultimate-appointments' ); ?></p>
		<?php else : ?>
			<div class="uappt-heat-wrap">
				<table class="uappt-heat">
					<thead>
						<tr>
							<th></th>
							<?php for ( $uappt_h = (int) $uappt_extra['min_hour']; $uappt_h <= (int) $uappt_extra['max_hour']; $uappt_h++ ) : ?>
								<th><?php echo esc_html( $uappt_h ); ?></th>
							<?php endfor; ?>
						</tr>
					</thead>
					<tbody>
						<?php // 星期一排在最上面（1=星期日，所以順序是 2,3,4,5,6,7,1）。 ?>
						<?php foreach ( array( 2, 3, 4, 5, 6, 7, 1 ) as $uappt_wd ) : ?>
							<tr>
								<th><?php echo esc_html( UAPPT_Admin::weekday_label( $uappt_wd ) ); ?></th>
								<?php for ( $uappt_h = (int) $uappt_extra['min_hour']; $uappt_h <= (int) $uappt_extra['max_hour']; $uappt_h++ ) : ?>
									<?php
									$uappt_cell  = isset( $uappt_matrix[ $uappt_wd ][ $uappt_h ] ) ? $uappt_matrix[ $uappt_wd ][ $uappt_h ] : null;
									$uappt_count = $uappt_cell ? (int) $uappt_cell['count'] : 0;
									$uappt_title = sprintf(
										/* translators: 1: 星期 2: 時段 3: 筆數 4: 業績 */
										__( '週%1$s %2$02d:00　%3$d 筆　%4$s', 'ultimate-appointments' ),
										UAPPT_Admin::weekday_label( $uappt_wd ),
										$uappt_h,
										$uappt_count,
										$uappt_cell ? wp_strip_all_tags( wc_price( $uappt_cell['revenue'] ) ) : wp_strip_all_tags( wc_price( 0 ) )
									);
									?>
									<td class="is-heat-<?php echo esc_attr( $uappt_heat_level( $uappt_count, $uappt_max ) ); ?>" title="<?php echo esc_attr( $uappt_title ); ?>">
										<?php echo $uappt_count > 0 ? esc_html( $uappt_count ) : ''; ?>
									</td>
								<?php endfor; ?>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>

			<p class="uappt-cal-legend">
				<span class="uappt-cal-legend-scale" aria-hidden="true">
					<span class="uappt-cal-legend-swatch is-heat-0"></span>
					<span class="uappt-cal-legend-swatch is-heat-1"></span>
					<span class="uappt-cal-legend-swatch is-heat-2"></span>
					<span class="uappt-cal-legend-swatch is-heat-3"></span>
					<span class="uappt-cal-legend-swatch is-heat-4"></span>
				</span>
				<span>
					<?php
					printf(
						/* translators: %d: 最忙那一格的筆數 */
						esc_html__( '顏色越深代表那個時段越滿（最忙的一格是 %d 筆）', 'ultimate-appointments' ),
						(int) $uappt_extra['max_count']
					);
					?>
				</span>
			</p>

			<p class="description">
				<?php esc_html_e( '這張表直接指導排班與離峰促銷：整排都淺的那幾個時段，不是把人排少一點，就是拿來推優惠。滑鼠移到格子上看確切筆數與業績。', 'ultimate-appointments' ); ?>
			</p>
			<p class="description">
				<?php esc_html_e( '⚠️ 濃淡是「相對於這段期間最忙的那一格」，不是固定門檻——換一個期間顏色就會重新分配。跨期間比較請看數字不要看顏色。另外，跨小時的服務只算在開始的那個小時。', 'ultimate-appointments' ); ?>
			</p>
		<?php endif; ?>
	</div>
</div>

<div class="uappt-panel">
	<?php UAPPT_Admin::panel_head( 'bell', __( '流失分析', 'ultimate-appointments' ) ); ?>
	<div class="uappt-panel-body">
		<div class="uappt-dash-cards">
			<div class="uappt-dash-card is-static <?php echo $uappt_cancel['cancelled'] > 0 ? 'is-warning' : ''; ?>">
				<span class="uappt-dash-card-value"><?php echo esc_html( $uappt_cancel['cancelled'] ); ?></span>
				<span class="uappt-dash-card-label">
					<?php
					printf(
						/* translators: %s: 取消率 */
						esc_html__( '取消（%s）', 'ultimate-appointments' ),
						esc_html( $uappt_pct( $uappt_cancel['cancel_rate'] ) )
					);
					?>
				</span>
			</div>
			<div class="uappt-dash-card is-static">
				<span class="uappt-dash-card-value"><?php echo wp_kses_post( wc_price( $uappt_cancel['cancelled_amount'] ) ); ?></span>
				<span class="uappt-dash-card-label"><?php esc_html_e( '取消的金額（原本可收）', 'ultimate-appointments' ); ?></span>
			</div>
			<div class="uappt-dash-card is-static <?php echo $uappt_cancel['no_show'] > 0 ? 'is-warning' : ''; ?>">
				<span class="uappt-dash-card-value"><?php echo esc_html( $uappt_cancel['no_show'] ); ?></span>
				<span class="uappt-dash-card-label">
					<?php
					printf(
						/* translators: %s: 未到率 */
						esc_html__( '未到（%s）', 'ultimate-appointments' ),
						esc_html( $uappt_pct( $uappt_cancel['no_show_rate'] ) )
					);
					?>
				</span>
			</div>
			<div class="uappt-dash-card is-static">
				<span class="uappt-dash-card-value"><?php echo esc_html( $uappt_cancel['expired'] ); ?></span>
				<span class="uappt-dash-card-label"><?php esc_html_e( '未完成結帳（不算取消）', 'ultimate-appointments' ); ?></span>
			</div>
		</div>

		<?php if ( $uappt_cancel['cancelled'] > 0 ) : ?>
			<h4 class="uappt-subhead"><?php esc_html_e( '提前多久取消', 'ultimate-appointments' ); ?></h4>
			<?php
			$uappt_bar_items = array(
				'over_7'   => __( '7 天以上', 'ultimate-appointments' ),
				'd3_7'     => __( '3–7 天前', 'ultimate-appointments' ),
				'd1_3'     => __( '1–3 天前', 'ultimate-appointments' ),
				'same_day' => __( '當天取消', 'ultimate-appointments' ),
				'after'    => __( '服務時間之後才處理', 'ultimate-appointments' ),
			);
			$uappt_bar_counts = $uappt_cancel['lead'];
			require UAPPT_PLUGIN_DIR . 'includes/views/partials/report-bars.php';
			?>
			<p class="description">
				<?php esc_html_e( '當天取消補不到位，三天前取消還救得回來——如果「當天取消」那一段特別長，值得考慮加上取消期限或訂金政策。', 'ultimate-appointments' ); ?>
			</p>
			<p class="description">
				<?php esc_html_e( '⚠️ 取消時間是用該筆預約「最後一次異動的時間」推算的（沒有專門記錄取消時刻的欄位）。取消之後如果又有人動過那筆資料，這個前置時間會失真。', 'ultimate-appointments' ); ?>
			</p>
		<?php endif; ?>

		<p class="description">
			<?php esc_html_e( '⚠️ 「未完成結帳」跟「取消」是兩件事，所以分開列：前者是客人把時段選好、卻沒有完成付款，時段自動被釋放——那是結帳流程的問題；後者才是客人真的訂了又不來。混在一起會讓取消率憑空多一倍，然後檢討到一個不存在的問題。', 'ultimate-appointments' ); ?>
		</p>
	</div>
</div>

<div class="uappt-panel">
	<?php UAPPT_Admin::panel_head( 'pie-chart', __( '產能缺口', 'ultimate-appointments' ) ); ?>
	<div class="uappt-panel-body">
		<div class="uappt-dash-cards">
			<div class="uappt-dash-card is-static">
				<span class="uappt-dash-card-value"><?php echo esc_html( $uappt_pct( $report['totals']['utilization'] ) ); ?></span>
				<span class="uappt-dash-card-label"><?php esc_html_e( '時段利用率', 'ultimate-appointments' ); ?></span>
			</div>
			<div class="uappt-dash-card is-static">
				<span class="uappt-dash-card-value"><?php echo esc_html( number_format( $uappt_cap['idle_slots'] ) ); ?></span>
				<span class="uappt-dash-card-label"><?php esc_html_e( '大約還能多做幾個', 'ultimate-appointments' ); ?></span>
			</div>
			<div class="uappt-dash-card is-static">
				<span class="uappt-dash-card-value"><?php echo wp_kses_post( wc_price( $uappt_cap['potential_revenue'] ) ); ?></span>
				<span class="uappt-dash-card-label"><?php esc_html_e( '換算潛在營收（估算）', 'ultimate-appointments' ); ?></span>
			</div>
			<div class="uappt-dash-card is-static">
				<span class="uappt-dash-card-value"><?php echo esc_html( number_format( $uappt_cap['avg_service_len'] ) ); ?></span>
				<span class="uappt-dash-card-label"><?php esc_html_e( '平均服務時長（分鐘）', 'ultimate-appointments' ); ?></span>
			</div>
		</div>
		<p class="description">
			<?php esc_html_e( '把利用率換算成錢：空著的人力時間除以平均服務時長，等於「還能多接幾個客人」，再乘平均客單價。「利用率 62%」很抽象，「這個月空了 120 個時段、約等於少賺 NT$84,000」才有感。', 'ultimate-appointments' ); ?>
		</p>
		<p class="description">
			<?php esc_html_e( '⚠️ 這是推估不是事實，不能拿去當預算數字。它假設空著的時間都能塞滿、而且每個客人都付平均客單價——實際上時段有冷熱、人力也需要休息。把它當成「上限的參考」，不是「應該達到的目標」。', 'ultimate-appointments' ); ?>
		</p>
	</div>
</div>

<div class="uappt-panel">
	<?php UAPPT_Admin::panel_head( 'calendar', __( '預約行為', 'ultimate-appointments' ) ); ?>
	<div class="uappt-panel-body">
		<div class="uappt-dash-cards">
			<div class="uappt-dash-card is-static">
				<span class="uappt-dash-card-value"><?php echo esc_html( $uappt_lead['median'] ); ?></span>
				<span class="uappt-dash-card-label"><?php esc_html_e( '提前幾天訂（中位數）', 'ultimate-appointments' ); ?></span>
			</div>
			<div class="uappt-dash-card is-static">
				<span class="uappt-dash-card-value"><?php echo esc_html( $uappt_pct( $uappt_source['online_rate'] ) ); ?></span>
				<span class="uappt-dash-card-label">
					<?php
					printf(
						/* translators: 1: 線上筆數 2: 後台筆數 */
						esc_html__( '線上自助（%1$d 線上／%2$d 櫃檯）', 'ultimate-appointments' ),
						(int) $uappt_source['online'],
						(int) $uappt_source['manual']
					);
					?>
				</span>
			</div>
		</div>

		<?php if ( $uappt_lead['total'] > 0 ) : ?>
			<h4 class="uappt-subhead"><?php esc_html_e( '提前多久訂', 'ultimate-appointments' ); ?></h4>
			<?php
			$uappt_bar_items = array(
				'same_day' => __( '當天訂', 'ultimate-appointments' ),
				'd1_3'     => __( '1–3 天前', 'ultimate-appointments' ),
				'd4_7'     => __( '4–7 天前', 'ultimate-appointments' ),
				'd8_14'    => __( '8–14 天前', 'ultimate-appointments' ),
				'over_14'  => __( '15 天以上', 'ultimate-appointments' ),
			);
			$uappt_bar_counts = $uappt_lead['buckets'];
			require UAPPT_PLUGIN_DIR . 'includes/views/partials/report-bars.php';
			?>
			<p class="description">
				<?php
				printf(
					/* translators: %d: 目前設定的開放預約天數 */
					esc_html__( '這個數字直接指導「開放預約天數」該設多少（目前設定 %d 天）：如果九成的人都在一週內訂，開放太長只是讓月曆變長；反過來如果有人會提前一個月訂，設太短就會擋掉生意。', 'ultimate-appointments' ),
					(int) get_option( 'uappt_booking_horizon_days', 30 )
				);
				?>
			</p>
		<?php endif; ?>

		<p class="description">
			<?php esc_html_e( '「線上自助」是客人自己在前台訂的，其餘是櫃檯或電話由後台建的。前者比例越高，表示線上預約流程越能自己運作。', 'ultimate-appointments' ); ?>
		</p>
	</div>
</div>
