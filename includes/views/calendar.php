<?php
/**
 * View：月檢視日曆。
 *
 * 傳入變數：$staff_list、$staff_id（0 代表所有人員）、$days（月曆格子涵蓋的所有日期，
 * 含前後月補白）、$by_day、$month_param、$prev_month_param、$next_month_param、
 * $this_month_param、$show_all。
 *
 * @package Ultimate_Appointments
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$weekday_headers = array(
	__( '一', 'ultimate-appointments' ),
	__( '二', 'ultimate-appointments' ),
	__( '三', 'ultimate-appointments' ),
	__( '四', 'ultimate-appointments' ),
	__( '五', 'ultimate-appointments' ),
	__( '六', 'ultimate-appointments' ),
	__( '日', 'ultimate-appointments' ),
);

$staff_names = array();
foreach ( $staff_list as $staff ) {
	$staff_names[ (int) $staff['id'] ] = $staff['name'];
}

$month_dt    = date_create( $month_param . '-01 00:00:00', wp_timezone() );
$month_label = $month_dt ? wp_date( 'Y年n月', $month_dt->getTimestamp() ) : $month_param;
$today       = current_time( 'Y-m-d' );
$max_per_day = 4;

// ⚠️ view 一定要帶。v2.38.0 日曆併進「預約」之後，section=bookings 不帶 view
// 的預設是**清單**——少了這一行，上／本／下個月與篩選按鈕全部會跳回清單頁。
$nav_base_args = array(
	'page'     => UAPPT_Admin::PAGE_SLUG,
	'section'  => 'bookings',
	'view'     => 'month',
	'staff_id' => $staff_id,
);
if ( $show_all ) {
	$nav_base_args['show_all'] = 1;
}

?>

	<?php
	$uappt_active = 0;
	if ( (int) $staff_id ) {
		$uappt_active++;
	}
	if ( $show_all ) {
		$uappt_active++;
	}

	// 月檢視的月份是導覽（底下那排上個月／本月／下個月），不是篩選條件，
	// 所以 month 只是帶著走的 hidden，不算進生效中的條件。
	UAPPT_Admin::filters_open(
		array(
			'section' => 'bookings',
			'hidden'  => array(
				'view'  => 'month',
				'month' => $month_param,
			),
			'active'  => $uappt_active,
		)
	);
	?>
		<?php UAPPT_Admin::field_open( __( '人員', 'ultimate-appointments' ), 'uappt-filter-staff' ); ?>
			<select id="uappt-filter-staff" name="staff_id">
				<option value="0" <?php selected( 0 === (int) $staff_id ); ?>><?php esc_html_e( '所有人員', 'ultimate-appointments' ); ?></option>
				<?php foreach ( $staff_list as $staff ) : ?>
					<option value="<?php echo esc_attr( $staff['id'] ); ?>" <?php selected( (int) $staff_id, (int) $staff['id'] ); ?>>
						<?php echo esc_html( $staff['name'] ); ?>
					</option>
				<?php endforeach; ?>
			</select>
		<?php UAPPT_Admin::field_close(); ?>

		<?php UAPPT_Admin::field_open( '', '', 'uappt-field-check' ); ?>
			<label>
				<input type="checkbox" name="show_all" value="1" <?php checked( $show_all ); ?> />
				<?php esc_html_e( '顯示已取消/已逾時的預約', 'ultimate-appointments' ); ?>
			</label>
		<?php UAPPT_Admin::field_close(); ?>

		<?php UAPPT_Admin::field_open( '', '', 'uappt-field-actions' ); ?>
			<?php // 明確的套用鈕，跟日檢視、報表、預約列表一致。 ?>
			<?php submit_button( __( '套用篩選', 'ultimate-appointments' ), 'secondary', '', false ); ?>
			<a href="<?php echo esc_url( UAPPT_Admin::url( 'bookings', array( 'view' => 'month', 'month' => $month_param ) ) ); ?>" class="button-link">
				<?php esc_html_e( '清除篩選', 'ultimate-appointments' ); ?>
			</a>
		<?php UAPPT_Admin::field_close(); ?>
	<?php UAPPT_Admin::filters_close(); ?>

	<div class="uappt-calendar-nav">
		<strong class="uappt-calendar-month-label"><?php echo esc_html( $month_label ); ?></strong>
		<span class="uappt-calendar-nav-buttons">
			<a class="button" href="<?php echo esc_url( add_query_arg( array_merge( $nav_base_args, array( 'month' => $prev_month_param ) ), admin_url( 'admin.php' ) ) ); ?>">
				&laquo; <?php esc_html_e( '上個月', 'ultimate-appointments' ); ?>
			</a>
			<a class="button" href="<?php echo esc_url( add_query_arg( array_merge( $nav_base_args, array( 'month' => $this_month_param ) ), admin_url( 'admin.php' ) ) ); ?>">
				<?php esc_html_e( '本月', 'ultimate-appointments' ); ?>
			</a>
			<a class="button" href="<?php echo esc_url( add_query_arg( array_merge( $nav_base_args, array( 'month' => $next_month_param ) ), admin_url( 'admin.php' ) ) ); ?>">
				<?php esc_html_e( '下個月', 'ultimate-appointments' ); ?> &raquo;
			</a>
		</span>
	</div>

	<div class="uappt-month-grid">
		<?php foreach ( $weekday_headers as $wd ) : ?>
			<div class="uappt-month-weekday"><?php echo esc_html( $wd ); ?></div>
		<?php endforeach; ?>

		<?php foreach ( $days as $day ) : ?>
			<?php
			$day_dt        = date_create( $day . ' 00:00:00', wp_timezone() );
			$in_month      = 0 === strpos( $day, $month_param );
			$is_today      = ( $day === $today );
			$items         = isset( $by_day[ $day ] ) ? $by_day[ $day ] : array();
			$visible_items = array_slice( $items, 0, $max_per_day );
			$more_count    = max( 0, count( $items ) - $max_per_day );

			$day_list_url = add_query_arg(
				array(
					'page'    => UAPPT_Admin::PAGE_SLUG,
					'section' => 'bookings',
					'view'      => 'all',
					'staff_id'  => $staff_id,
					'date_from' => $day,
					'date_to'   => $day,
				),
				admin_url( 'admin.php' )
			);

			$cell_classes = 'uappt-month-cell';
			if ( ! $in_month ) {
				$cell_classes .= ' is-outside';
			}
			if ( $is_today ) {
				$cell_classes .= ' is-today';
			}
			// 週末淡色底：排班表最常被問的就是「這個週末誰有班」，先讓它一眼看得出來。
			$weekday_w = uappt_local_weekday_w( $day );
			if ( 0 === $weekday_w || 6 === $weekday_w ) {
				$cell_classes .= ' is-weekend';
			}
			?>
			<div class="<?php echo esc_attr( $cell_classes ); ?>">
				<div class="uappt-month-cell-date">
					<a href="<?php echo esc_url( $day_list_url ); ?>"><?php echo esc_html( $day_dt ? wp_date( 'j', $day_dt->getTimestamp() ) : '' ); ?></a>
					<?php if ( count( $items ) ) : ?>
						<span class="uappt-month-cell-count" title="<?php esc_attr_e( '這天的筆數', 'ultimate-appointments' ); ?>"><?php echo esc_html( count( $items ) ); ?></span>
					<?php endif; ?>
				</div>
				<?php foreach ( $visible_items as $booking ) : ?>
					<?php
					$start_dt   = date_create( $booking['service_start'], wp_timezone() );
					$time_label = $start_dt ? wp_date( 'H:i', $start_dt->getTimestamp() ) : '';
					$edit_url   = add_query_arg(
						array(
							'page'    => UAPPT_Admin::PAGE_SLUG,
							'section' => 'bookings',
							'action'     => 'edit',
							'booking_id' => $booking['id'],
						),
						admin_url( 'admin.php' )
					);
					$item_staff_id   = ! empty( $booking['staff_id'] ) ? (int) $booking['staff_id'] : 0;
					$item_staff_name = $item_staff_id && isset( $staff_names[ $item_staff_id ] ) ? $staff_names[ $item_staff_id ] : '';
					$item_is_block   = isset( $booking['kind'] ) && UAPPT_Booking::KIND_BLOCK === $booking['kind'];
					$item_classes    = 'uappt-month-item ' . ( $item_is_block ? 'is-block' : 'uappt-tone-' . UAPPT_Admin::booking_status_class( $booking ) );
					?>
					<?php if ( $item_is_block ) : ?>
						<?php // 時段佔用不是預約，沒有編輯頁可以去（刪除在人力資源頁）。 ?>
						<span class="<?php echo esc_attr( $item_classes ); ?>">
							<span class="uappt-month-item-time"><?php echo esc_html( $time_label ); ?></span>
							<span class="uappt-month-item-name">
								<?php echo esc_html( UAPPT_Booking::get_booking_display_name( $booking ) ); ?>
								<?php if ( $item_staff_name && ! $staff_id ) : ?>
									<span class="uappt-month-item-staff">· <?php echo esc_html( $item_staff_name ); ?></span>
								<?php endif; ?>
							</span>
						</span>
					<?php else : ?>
						<a class="<?php echo esc_attr( $item_classes ); ?>" href="<?php echo esc_url( $edit_url ); ?>">
							<span class="uappt-month-item-time"><?php echo esc_html( $time_label ); ?></span>
							<span class="uappt-month-item-name">
								<?php echo esc_html( UAPPT_Booking::get_booking_display_name( $booking ) ); ?>
								<?php if ( $item_staff_name && ! $staff_id ) : ?>
									<span class="uappt-month-item-staff">· <?php echo esc_html( $item_staff_name ); ?></span>
								<?php endif; ?>
							</span>
						</a>
					<?php endif; ?>
				<?php endforeach; ?>
				<?php if ( $more_count > 0 ) : ?>
					<a class="uappt-month-more" href="<?php echo esc_url( $day_list_url ); ?>">
						<?php
						printf(
							/* translators: %d: 還有幾筆預約 */
							esc_html__( '+%d 筆', 'ultimate-appointments' ),
							$more_count
						);
						?>
					</a>
				<?php endif; ?>
			</div>
		<?php endforeach; ?>
	</div>
