<?php
/**
 * View：前台會員中心「本月明細」分頁——月曆 ＋ 選定那一天的預約明細。
 *
 * v2.23.0 從「一整個月的逐日清單」改成月曆式。原本 30 天一路往下捲，看不出
 * 哪天忙哪天空，而員工每天真正要處理的只有一天份。現在月曆負責「一眼看出
 * 整個月的形狀」，下方只展開選定的那一天。
 *
 * **客人姓名與操作按鈕刻意不放進月曆格子**：一個格子在手機上只有幾十像素寬，
 * 塞得下的只有「幾點、共幾筆」這種一眼資訊，塞客人姓名一定爆版。
 *
 * v2.26.0 再修三件實際用起來才發現的事（詳見 partials/month-grid.php 的
 * 「兩種模式」與 frontend.css 的配色說明）：
 * 1. 格子裡改印**預約時間**，不再印上班時間——那是「我的班表」要回答的問題
 * 2. **整格都可以點**，不是只有日期數字（手機上那是個很難瞄準的目標）
 * 3. 底色改成紫色濃淡階梯（越深＝當天預約越多），跟班表的綠／灰完全分開
 *
 * 傳入變數：
 * - $staff               目前登入者對應的人員資料
 * - $calendar            UAPPT_Staff_Portal::get_schedule_month() 的回傳值
 * - $selected_date       目前展開明細的日期 (Y-m-d)，一定落在 $calendar['days'] 裡
 * - $can_manage_bookings 是否可以標記完成／未到／還原／寫備註（uappt_manage_own_bookings）
 * - $show_amount         是否顯示金額（設定頁的「讓員工看到自己的業績金額」）
 *
 * @package Ultimate_Appointments
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$uappt_endpoint_url = wc_get_account_endpoint_url( UAPPT_Staff_Portal::ENDPOINT_MONTHLY_DETAIL );
$uappt_month_url    = add_query_arg( 'uappt_month', $calendar['month'], $uappt_endpoint_url );
$uappt_prev_url     = add_query_arg( 'uappt_month', $calendar['prev_month'], $uappt_endpoint_url );
$uappt_next_url     = add_query_arg( 'uappt_month', $calendar['next_month'], $uappt_endpoint_url );
$uappt_month_dt     = date_create( $calendar['month'] . '-01', wp_timezone() );
$uappt_month_label  = $uappt_month_dt ? wp_date( 'Y 年 n 月', $uappt_month_dt->getTimestamp() ) : $calendar['month'];

// 每日業績小計。只算真的有金額的預約列（時段佔用沒有金額、amount 是 NULL 的
// 也沒有），統計口徑跟後台報表一致：confirmed／completed／no_show 都算——
// 不管客人有沒有出現，錢通常都已經收了。
$uappt_day_amounts = array();
$uappt_month_total = 0.0;
if ( $show_amount ) {
	foreach ( $calendar['days'] as $uappt_amt_date => $uappt_amt_day ) {
		$uappt_sum = 0.0;
		foreach ( $uappt_amt_day['bookings'] as $uappt_amt_booking ) {
			if ( UAPPT_Booking::KIND_BOOKING !== $uappt_amt_booking['kind'] || null === $uappt_amt_booking['amount'] ) {
				continue;
			}
			if ( ! in_array( $uappt_amt_booking['status'], array( UAPPT_Booking::STATUS_CONFIRMED, UAPPT_Booking::STATUS_COMPLETED, UAPPT_Booking::STATUS_NO_SHOW ), true ) ) {
				continue;
			}
			$uappt_sum += (float) $uappt_amt_booking['amount'];
		}
		if ( $uappt_sum > 0 ) {
			$uappt_day_amounts[ $uappt_amt_date ] = $uappt_sum;
			// 月合計只算當月的日子，月曆前後補齊的那幾格是別的月份的。
			if ( $uappt_amt_day['is_in_month'] ) {
				$uappt_month_total += $uappt_sum;
			}
		}
	}
}

$uappt_day        = isset( $calendar['days'][ $selected_date ] ) ? $calendar['days'][ $selected_date ] : null;
$uappt_day_dt     = date_create( $selected_date . ' 00:00:00', wp_timezone() );
$uappt_day_label  = $uappt_day_dt ? wp_date( 'n 月 j 日（D）', $uappt_day_dt->getTimestamp() ) : $selected_date;
?>
<h2><?php esc_html_e( '本月明細', 'ultimate-appointments' ); ?></h2>
<p class="description">
	<?php esc_html_e( '月曆上點任何一天（整格都可以點），下面就會展開那天的預約，可以標記完成／未到，或寫內部備註。', 'ultimate-appointments' ); ?>
</p>

<div class="uappt-cal-nav">
	<a class="button" href="<?php echo esc_url( $uappt_prev_url ); ?>">&laquo; <?php esc_html_e( '上個月', 'ultimate-appointments' ); ?></a>
	<strong class="uappt-cal-nav-label">
		<?php echo esc_html( $uappt_month_label ); ?>
		<?php if ( $show_amount && $uappt_month_total > 0 ) : ?>
			<span class="uappt-cal-month-total"><?php echo wp_kses_post( wc_price( $uappt_month_total ) ); ?></span>
		<?php endif; ?>
	</strong>
	<a class="button" href="<?php echo esc_url( $uappt_next_url ); ?>"><?php esc_html_e( '下個月', 'ultimate-appointments' ); ?> &raquo;</a>
</div>

<?php
// 明細頁的月曆是純檢視：不勾選、**整格都可以點**、每一個當月的日子都點得進去
// （包含沒有預約的——「這天沒有預約」本身也是一個答案，比「有些格子可以點、
// 有些不行」好猜）。
$grid_mode          = 'detail';
$grid_selectable    = false;
$grid_day_url       = $uappt_month_url;
$grid_linkable      = null;
$grid_selected_date = $selected_date;
$grid_amounts       = $uappt_day_amounts;
$grid_editable      = false;
// 前台一律不塗：排班是主管的事，員工端只能送申請。
$grid_paintable     = false;
require UAPPT_PLUGIN_DIR . 'includes/views/partials/month-grid.php';
?>

<p class="uappt-cal-legend">
	<span class="uappt-cal-legend-scale" aria-hidden="true">
		<span class="uappt-cal-legend-swatch is-busy-0"></span>
		<span class="uappt-cal-legend-swatch is-busy-1"></span>
		<span class="uappt-cal-legend-swatch is-busy-2"></span>
		<span class="uappt-cal-legend-swatch is-busy-3"></span>
	</span>
	<span><?php esc_html_e( '顏色越深代表當天的預約越多', 'ultimate-appointments' ); ?></span>
	<span class="uappt-cal-legend-item">
		<span class="uappt-cal-legend-swatch is-closed" aria-hidden="true"></span>
		<?php esc_html_e( '休假', 'ultimate-appointments' ); ?>
	</span>
</p>

<div class="uappt-schedule-detail" id="uappt-day-detail">
	<div class="uappt-schedule-day">
		<div class="uappt-schedule-day-head">
			<strong><?php echo esc_html( $uappt_day_label ); ?></strong>
			<?php if ( $uappt_day && $uappt_day['override'] && ! empty( $uappt_day['override']['is_closed'] ) ) : ?>
				<span class="uappt-badge uappt-badge-muted">
					<?php echo esc_html( UAPPT_Staff::closed_reason_label( isset( $uappt_day['override']['closed_reason'] ) ? $uappt_day['override']['closed_reason'] : '' ) ); ?>
				</span>
			<?php elseif ( $uappt_day && ! empty( $uappt_day['ranges'] ) ) : ?>
				<span class="uappt-schedule-hours">
					<?php
					$uappt_range_labels = array();
					foreach ( $uappt_day['ranges'] as $uappt_range ) {
						$uappt_range_labels[] = $uappt_range[0] . '–' . $uappt_range[1];
					}
					echo esc_html( implode( '、', $uappt_range_labels ) );
					?>
				</span>
			<?php else : ?>
				<span class="uappt-badge uappt-badge-muted"><?php esc_html_e( '未排班', 'ultimate-appointments' ); ?></span>
			<?php endif; ?>
			<?php if ( $show_amount && isset( $uappt_day_amounts[ $selected_date ] ) ) : ?>
				<span class="uappt-schedule-day-total"><?php echo wp_kses_post( wc_price( $uappt_day_amounts[ $selected_date ] ) ); ?></span>
			<?php endif; ?>
		</div>

		<?php if ( $uappt_day && ! empty( $uappt_day['pending'] ) ) : ?>
			<p class="description">
				<?php
				foreach ( $uappt_day['pending'] as $uappt_pending ) {
					echo esc_html(
						UAPPT_Admin::shift_request_type_label( $uappt_pending['type'] ) . '：' . UAPPT_Admin::shift_request_detail_label( $uappt_pending ) . '（' . __( '待審核', 'ultimate-appointments' ) . '）'
					);
					echo '<br />';
				}
				?>
			</p>
		<?php endif; ?>

		<?php if ( ! $uappt_day || empty( $uappt_day['bookings'] ) ) : ?>
			<p class="uappt-schedule-empty"><?php esc_html_e( '這天沒有預約。', 'ultimate-appointments' ); ?></p>
		<?php else : ?>
			<ul class="uappt-schedule-bookings">
				<?php foreach ( $uappt_day['bookings'] as $uappt_booking ) : ?>
					<?php
					$uappt_is_block         = ( 'block' === $uappt_booking['kind'] );
					$uappt_service_start_dt = date_create( $uappt_booking['service_start'], wp_timezone() );
					$uappt_service_started   = $uappt_service_start_dt && $uappt_service_start_dt->getTimestamp() <= time();
					$uappt_is_terminal       = in_array( $uappt_booking['status'], array( UAPPT_Booking::STATUS_COMPLETED, UAPPT_Booking::STATUS_NO_SHOW ), true );
					?>
					<li class="uappt-schedule-booking">
						<div class="uappt-schedule-booking-head">
							<span class="uappt-schedule-booking-time">
								<?php
								$uappt_service_end_dt = date_create( $uappt_booking['service_end'], wp_timezone() );
								echo esc_html( ( $uappt_service_start_dt ? $uappt_service_start_dt->format( 'H:i' ) : '' ) . '–' . ( $uappt_service_end_dt ? $uappt_service_end_dt->format( 'H:i' ) : '' ) );
								?>
							</span>
							<?php if ( $uappt_is_block ) : ?>
								<span class="uappt-badge uappt-badge-info"><?php esc_html_e( '時段佔用', 'ultimate-appointments' ); ?></span>
								<span><?php echo esc_html( $uappt_booking['note'] ); ?></span>
							<?php else : ?>
								<span class="uappt-badge uappt-badge-<?php echo esc_attr( UAPPT_Admin::status_class( $uappt_booking['status'] ) ); ?>">
									<?php echo esc_html( UAPPT_Admin::status_label( $uappt_booking['status'] ) ); ?>
								</span>
								<span><?php echo esc_html( UAPPT_Booking::get_booking_display_name( $uappt_booking ) ); ?></span>
								<span class="uappt-schedule-customer">
									<?php echo esc_html( trim( (string) $uappt_booking['customer_name'] ) ); ?>
									<?php if ( trim( (string) $uappt_booking['customer_phone'] ) ) : ?>
										／<?php echo esc_html( $uappt_booking['customer_phone'] ); ?>
									<?php endif; ?>
								</span>
								<?php if ( $show_amount && null !== $uappt_booking['amount'] ) : ?>
									<span class="uappt-schedule-booking-amount"><?php echo wp_kses_post( wc_price( (float) $uappt_booking['amount'] ) ); ?></span>
								<?php endif; ?>
							<?php endif; ?>
						</div>

						<?php if ( ! $uappt_is_block && $can_manage_bookings ) : ?>
							<div class="uappt-schedule-booking-actions">
								<?php if ( UAPPT_Booking::STATUS_CONFIRMED === $uappt_booking['status'] ) : ?>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="uappt-schedule-inline-form">
										<input type="hidden" name="action" value="uappt_staff_complete_booking" />
										<input type="hidden" name="booking_id" value="<?php echo esc_attr( $uappt_booking['id'] ); ?>" />
										<?php wp_nonce_field( 'uappt_staff_booking_' . $uappt_booking['id'] ); ?>
										<button type="submit" class="button button-primary"><?php esc_html_e( '標記完成', 'ultimate-appointments' ); ?></button>
									</form>
									<?php if ( $uappt_service_started ) : ?>
										<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="uappt-schedule-inline-form">
											<input type="hidden" name="action" value="uappt_staff_no_show_booking" />
											<input type="hidden" name="booking_id" value="<?php echo esc_attr( $uappt_booking['id'] ); ?>" />
											<?php wp_nonce_field( 'uappt_staff_booking_' . $uappt_booking['id'] ); ?>
											<button type="submit" class="button"><?php esc_html_e( '標記未到', 'ultimate-appointments' ); ?></button>
										</form>
									<?php endif; ?>
								<?php elseif ( $uappt_is_terminal ) : ?>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="uappt-schedule-inline-form" onsubmit="return confirm('<?php echo esc_js( __( '確定要還原成「已確認」嗎？', 'ultimate-appointments' ) ); ?>');">
										<input type="hidden" name="action" value="uappt_staff_revert_booking" />
										<input type="hidden" name="booking_id" value="<?php echo esc_attr( $uappt_booking['id'] ); ?>" />
										<?php wp_nonce_field( 'uappt_staff_booking_' . $uappt_booking['id'] ); ?>
										<button type="submit" class="button"><?php esc_html_e( '還原為已確認', 'ultimate-appointments' ); ?></button>
									</form>
								<?php endif; ?>

								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="uappt-schedule-note-form">
									<input type="hidden" name="action" value="uappt_staff_update_note" />
									<input type="hidden" name="booking_id" value="<?php echo esc_attr( $uappt_booking['id'] ); ?>" />
									<?php wp_nonce_field( 'uappt_staff_booking_' . $uappt_booking['id'] ); ?>
									<input type="text" name="staff_note" class="uappt-schedule-note-input" placeholder="<?php esc_attr_e( '內部備註（只有你自己看得到，不會顯示給客人）', 'ultimate-appointments' ); ?>" value="<?php echo esc_attr( $uappt_booking['staff_note'] ); ?>" />
									<button type="submit" class="button"><?php esc_html_e( '儲存備註', 'ultimate-appointments' ); ?></button>
								</form>
							</div>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
	</div>
</div>
