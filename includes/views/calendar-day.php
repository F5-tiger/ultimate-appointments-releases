<?php
/**
 * View：日檢視日曆（按人員分欄）。
 *
 * 傳入變數：
 * - $columns_staff       要顯示欄位的人員清單
 * - $bookings_by_staff   staff_id => 該日預約清單（已排序，且帶 _lane / _lane_count）
 * - $offhours_by_staff   staff_id => 不在班的區段 [[start_min, end_min], …]
 * - $date_ymd            目前檢視的日期
 * - $day_start_ts / $day_end_ts  當天 00:00 與隔天 00:00 的時間戳
 * - $now_minutes         檢視當天時＝現在距離午夜幾分鐘；其他日期為 null
 * - $first_open_minutes  預設要捲到的位置（當天最早上班時間往前一小時）
 * - $prev_date / $next_date / $today
 * - $base_args           頁面導覽用的基底 query args（含 staff_ids[] 篩選、show_all、hide_idle）
 * - $show_all
 * - $staff_ids           目前篩選的人員 ID（陣列，空陣列＝顯示所有啟用中的人員）——
 *                        跟 render_calendar_day_view() 的參數同名，PHP require() 共用
 *                        呼叫端的變數作用域，不需要另外傳
 * - $hide_idle           是否隱藏當天完全沒有排班的人員欄位
 *
 * 座標系：**固定 00:00–24:00，位置一律用「距離午夜幾分鐘」表示**，再乘上 CSS 變數
 * --uappt-hour-h 換算成像素。時間軸與人員欄共用同一個單位，所以不可能像舊版那樣
 * 因為兩個容器的百分比基準不同而整條對不齊。
 *
 * 版位用預約的「服務時間」（不含前後緩衝）計算，緩衝不反映在方塊大小上——這裡是
 * 排班總覽用的視覺呈現，精確的佔用區間仍以預約編輯頁與實際控房邏輯為準。
 *
 * @package Ultimate_Appointments
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$date_dt    = date_create( $date_ymd . ' 00:00:00', wp_timezone() );
$date_label = $date_dt ? wp_date( 'Y-m-d (D)', $date_dt->getTimestamp() ) : $date_ymd;
$is_today   = ( $date_ymd === $today );


/**
 * 把「距離午夜的分鐘數」變成 CSS 用的長度。
 *
 * @param float $minutes 分鐘數。
 * @return string
 */
$uappt_offset = function ( $minutes ) {
	return 'calc(' . round( (float) $minutes / 60, 4 ) . ' * var(--uappt-hour-h))';
};
?>

	<?php
	// 日期不算「生效中的條件」：它是導覽（旁邊那排前一天／今天／後一天做的是
	// 同一件事），本來就永遠有值，算進去的話每一天進來都會顯示「1 項」。
	$uappt_active = 0;
	if ( $staff_ids ) {
		$uappt_active++;
	}
	if ( $hide_idle ) {
		$uappt_active++;
	}
	if ( $show_all ) {
		$uappt_active++;
	}

	UAPPT_Admin::filters_open(
		array(
			'section' => 'bookings',
			'hidden'  => array( 'view' => 'day' ),
			'active'  => $uappt_active,
		)
	);
	?>
		<?php
		// 日期欄位刻意保留自動送出：它是**導覽**而不是篩選（旁邊那排
		// 前一天／今天／後一天做的是同一件事），改日期就是要立刻跳過去。
		// 人員與底下兩個勾選框才是篩選，那些要按「套用篩選」一次生效——
		// 否則勾三個條件就要重新載入三次頁面。
		UAPPT_Admin::field_open( __( '日期', 'ultimate-appointments' ), 'uappt-filter-date' );
		?>
			<input type="date" id="uappt-filter-date" name="date" value="<?php echo esc_attr( $date_ymd ); ?>" onchange="this.form.submit()" />
		<?php UAPPT_Admin::field_close(); ?>

		<?php
		// 多選：人員一多（10 位以上），欄位橫向捲動雖然不會壓壞版面，但看不到
		// 全貌、也沒辦法只把想比較的幾位擺在一起看。讓管理者自己挑這次要看誰，
		// 比固定顯示全部或只能單選更貼近實際排班（輪班制通常同時上班的只是
		// 一部分人）。空白（沒勾選任何人）＝顯示所有啟用中的人員，維持原本
		// 「不篩選」的預設行為，不強迫每次都要手動全選。
		$active_staff_for_filter = array_values(
			array_filter(
				$staff_list,
				function ( $s ) {
					return 'active' === $s['status'];
				}
			)
		);
		?>
		<?php
		// 可搜尋的下拉多選，用 WooCommerce 內建的 `wc-enhanced-select`
		// （select2 的封裝），跟商品編輯頁「可服務的人員」同一套。
		//
		// ⚠️ v2.39.0 曾經改成一排核取方塊，因為當時看到的是一個又高又醜的
		// 原生 <select multiple>。那其實不是選單本身的問題——是 v2.38.0 把
		// 日曆併進 bookings 之後，enqueue_assets() 的條件還停在已經不存在的
		// `calendar` section，select2 整包沒載入。條件修好之後這裡就該用回
		// 下拉：人員一多（10 位以上），攤平的核取方塊會塞滿整條篩選列。
		//
		// 相依性是安全的：WooCommerce 的 register_scripts() 掛在 **admin_init**
		// （早於 admin_enqueue_scripts），而且原始碼註解寫明「registered early
		// to allow other plugins to take advantage of them by handle」。
		//
		// 空白（沒挑任何人）＝顯示所有啟用中的人員，維持原本「不篩選」的預設。
		?>
		<?php UAPPT_Admin::field_open( __( '人員', 'ultimate-appointments' ), '', 'uappt-field-wide' ); ?>
			<?php
			// ⚠️ width 留在 inline style 不是偷懶——select2 預設 `width: 'resolve'`，
			// 初始化時直接讀元素的 **style 屬性**來決定下拉容器寬度，搬到 CSS class
			// 就不一定 resolve 得到。這是功能相依不是裝飾，跟 manual-booking.php
			// 的會員搜尋同一條理由。
			?>
			<select
				name="staff_ids[]"
				multiple
				class="wc-enhanced-select"
				style="width: 260px;"
				data-placeholder="<?php esc_attr_e( '所有啟用中的人員', 'ultimate-appointments' ); ?>"
			>
				<?php foreach ( $active_staff_for_filter as $staff ) : ?>
					<option value="<?php echo esc_attr( $staff['id'] ); ?>" <?php selected( in_array( (int) $staff['id'], $staff_ids, true ) ); ?>>
						<?php echo esc_html( $staff['name'] ); ?>
					</option>
				<?php endforeach; ?>
			</select>
		<?php UAPPT_Admin::field_close(); ?>

		<?php UAPPT_Admin::field_open( '', '', 'uappt-field-check' ); ?>
			<label>
				<input type="checkbox" name="hide_idle" value="1" <?php checked( $hide_idle ); ?> />
				<?php esc_html_e( '只顯示今天有排班的人員', 'ultimate-appointments' ); ?>
			</label>
		<?php UAPPT_Admin::field_close(); ?>

		<?php UAPPT_Admin::field_open( '', '', 'uappt-field-check' ); ?>
			<label>
				<input type="checkbox" name="show_all" value="1" <?php checked( $show_all ); ?> />
				<?php esc_html_e( '顯示已取消/已逾時的預約', 'ultimate-appointments' ); ?>
			</label>
		<?php UAPPT_Admin::field_close(); ?>

		<?php UAPPT_Admin::field_open( '', '', 'uappt-field-actions' ); ?>
			<?php submit_button( __( '套用篩選', 'ultimate-appointments' ), 'secondary', '', false ); ?>
			<?php // 清掉人員與兩個勾選框，但**留著正在看的日期**——日期是導覽不是篩選。 ?>
			<a href="<?php echo esc_url( UAPPT_Admin::url( 'bookings', array( 'view' => 'day', 'date' => $date_ymd ) ) ); ?>" class="button-link">
				<?php esc_html_e( '清除篩選', 'ultimate-appointments' ); ?>
			</a>
		<?php UAPPT_Admin::field_close(); ?>
	<?php UAPPT_Admin::filters_close(); ?>

	<div class="uappt-calendar-nav">
		<strong class="uappt-calendar-month-label">
			<?php echo esc_html( $date_label ); ?>
			<?php if ( $is_today ) : ?>
				<span class="uappt-badge uappt-badge-info"><?php esc_html_e( '今天', 'ultimate-appointments' ); ?></span>
			<?php endif; ?>
		</strong>
		<span class="uappt-calendar-nav-buttons">
			<a class="button" href="<?php echo esc_url( add_query_arg( array_merge( $base_args, array( 'date' => $prev_date ) ), admin_url( 'admin.php' ) ) ); ?>">
				&laquo; <?php esc_html_e( '前一天', 'ultimate-appointments' ); ?>
			</a>
			<a class="button" href="<?php echo esc_url( add_query_arg( array_merge( $base_args, array( 'date' => $today ) ), admin_url( 'admin.php' ) ) ); ?>">
				<?php esc_html_e( '今天', 'ultimate-appointments' ); ?>
			</a>
			<a class="button" href="<?php echo esc_url( add_query_arg( array_merge( $base_args, array( 'date' => $next_date ) ), admin_url( 'admin.php' ) ) ); ?>">
				<?php esc_html_e( '後一天', 'ultimate-appointments' ); ?> &raquo;
			</a>
		</span>
	</div>

	<?php if ( empty( $columns_staff ) ) : ?>
		<p><?php esc_html_e( '沒有可顯示的人員（可能是篩選條件排除了所有人，或目前沒有啟用中的人員）。', 'ultimate-appointments' ); ?></p>
	<?php else : ?>
		<div class="uappt-day" data-scroll-to="<?php echo esc_attr( $first_open_minutes ); ?>">
			<div class="uappt-day-grid" style="--uappt-cols: <?php echo esc_attr( count( $columns_staff ) ); ?>;">

				<div class="uappt-day-corner"></div>
				<?php foreach ( $columns_staff as $staff ) : ?>
					<?php $day_count = isset( $bookings_by_staff[ $staff['id'] ] ) ? count( $bookings_by_staff[ $staff['id'] ] ) : 0; ?>
					<div class="uappt-day-colhead">
						<span class="uappt-day-colhead-name"><?php echo esc_html( $staff['name'] ); ?></span>
						<?php if ( $day_count ) : ?>
							<span class="uappt-day-colhead-count"><?php echo esc_html( $day_count ); ?></span>
						<?php endif; ?>
						<?php if ( (int) $staff['capacity'] > 1 ) : ?>
							<span class="uappt-day-colhead-cap" title="<?php esc_attr_e( '同時可服務人數', 'ultimate-appointments' ); ?>">
								<?php
								printf(
									/* translators: %d: 同時可服務人數 */
									esc_html__( '同時 %d 位', 'ultimate-appointments' ),
									(int) $staff['capacity']
								);
								?>
							</span>
						<?php endif; ?>
					</div>
				<?php endforeach; ?>

				<div class="uappt-day-axis">
					<?php for ( $hour = 0; $hour < 24; $hour++ ) : ?>
						<div class="uappt-day-axis-label" style="top: <?php echo esc_attr( $uappt_offset( $hour * 60 ) ); ?>;">
							<?php echo esc_html( sprintf( '%02d:00', $hour ) ); ?>
						</div>
					<?php endfor; ?>
				</div>

				<?php
				// 點空白處直接跳到「手動建立預約」，帶著這位人員與這一天：省去
				// 现場快速建單時「先找日期、再找人員」這兩步——管理者在日檢視上
				// 已經視覺化地看到某位人員有空檔了，不用再重找一次。時段沒有帶
				// 過去（那個頁面的時段清單一定要重新查詢才反映真正的即時空檔，
				// 猜一個點擊位置對應的時間反而可能誤導）。
				$quick_book_base = add_query_arg(
					array(
						'page'    => UAPPT_Admin::PAGE_SLUG,
						'section' => 'manual-booking',
					),
					admin_url( 'admin.php' )
				);
				?>
				<?php foreach ( $columns_staff as $staff ) : ?>
					<?php $bookings = isset( $bookings_by_staff[ $staff['id'] ] ) ? $bookings_by_staff[ $staff['id'] ] : array(); ?>
					<?php
					$quick_book_url = add_query_arg(
						array(
							'staff_id' => $staff['id'],
							'date_ymd' => $date_ymd,
						),
						$quick_book_base
					);
					?>
					<div class="uappt-day-col" data-quick-book-url="<?php echo esc_url( $quick_book_url ); ?>" title="<?php esc_attr_e( '點空白處可直接建立這位人員的預約', 'ultimate-appointments' ); ?>">

						<?php // 不在班的時段：灰底。一眼看出誰今天有班、班別怎麼排。 ?>
						<?php foreach ( (array) $offhours_by_staff[ $staff['id'] ] as $off ) : ?>
							<div class="uappt-day-off"
								style="top: <?php echo esc_attr( $uappt_offset( $off[0] ) ); ?>; height: <?php echo esc_attr( $uappt_offset( $off[1] - $off[0] ) ); ?>;"></div>
						<?php endforeach; ?>

						<?php for ( $hour = 0; $hour < 24; $hour++ ) : ?>
							<div class="uappt-day-line<?php echo 0 === $hour % 6 ? ' is-major' : ''; ?>"
								style="top: <?php echo esc_attr( $uappt_offset( $hour * 60 ) ); ?>;"></div>
						<?php endfor; ?>

						<?php foreach ( $bookings as $booking ) : ?>
							<?php
							$start_dt = date_create( $booking['service_start'], wp_timezone() );
							$end_dt   = date_create( $booking['service_end'], wp_timezone() );
							if ( ! $start_dt || ! $end_dt ) {
								continue;
							}

							// 裁切進當天：跨午夜的預約在這一天只畫落在今天的那一段。
							$start_ts = max( $day_start_ts, $start_dt->getTimestamp() );
							$end_ts   = min( $day_end_ts, $end_dt->getTimestamp() );
							if ( $end_ts <= $start_ts ) {
								continue;
							}

							$top_min    = ( $start_ts - $day_start_ts ) / 60;
							$height_min = max( 20, ( $end_ts - $start_ts ) / 60 ); // 至少 20 分鐘高，太短的預約文字才放得下。

							// _lane / _lane_count 由 UAPPT_Admin::assign_overlap_lanes() 算好：
							// 同時可服務人數 > 1 時，同一位人員可能有時間重疊的預約，並排畫。
							$lane_count = ! empty( $booking['_lane_count'] ) ? (int) $booking['_lane_count'] : 1;
							$lane       = isset( $booking['_lane'] ) ? (int) $booking['_lane'] : 0;
							$lane_pct   = 100 / $lane_count;
							$left_css   = "calc({$lane} * {$lane_pct}% + 4px)";
							$width_css  = "calc({$lane_pct}% - 8px)";

							$is_pending = isset( $booking['assignment_state'] ) && UAPPT_Booking::ASSIGNMENT_PENDING === $booking['assignment_state'];
							$is_block   = isset( $booking['kind'] ) && UAPPT_Booking::KIND_BLOCK === $booking['kind'];

							$time_label = wp_date( 'H:i', $start_dt->getTimestamp() ) . '–' . wp_date( 'H:i', $end_dt->getTimestamp() );
							$name_label = UAPPT_Booking::get_booking_display_name( $booking );

							$block_classes = 'uappt-day-block';
							if ( $is_block ) {
								// 時段佔用不是客人的預約，用自己的樣式，跟預約一眼分得出來。
								$block_classes .= ' is-block';
							} else {
								$block_classes .= ' uappt-tone-' . UAPPT_Admin::booking_status_class( $booking );
								if ( $is_pending ) {
									$block_classes .= ' is-pending';
								}
							}

							$style = 'top: ' . $uappt_offset( $top_min )
								. '; height: ' . $uappt_offset( $height_min )
								. '; left: ' . $left_css
								. '; width: ' . $width_css . ';';

							// 佔用沒有預約編輯頁可以去（它不是預約），所以畫成不可點的方塊；
							// 要刪除請到人力資源頁的「時段佔用」清單。
							$title = $is_block
								? $name_label . ' — ' . __( '時段佔用', 'ultimate-appointments' )
								: $name_label . ' — ' . UAPPT_Admin::booking_status_label( $booking );
							?>
							<?php if ( $is_block ) : ?>
								<div class="<?php echo esc_attr( $block_classes ); ?>"
									title="<?php echo esc_attr( $title ); ?>"
									style="<?php echo esc_attr( $style ); ?>">
									<span class="uappt-day-block-time"><?php echo esc_html( $time_label ); ?></span>
									<span class="uappt-day-block-name"><?php echo esc_html( $name_label ); ?></span>
									<?php if ( (int) $booking['occupied_units'] > 1 ) : ?>
										<span class="uappt-day-block-customer">
											<?php
											printf(
												/* translators: %d: 佔用的名額數 */
												esc_html__( '佔用 %d 個名額', 'ultimate-appointments' ),
												(int) $booking['occupied_units']
											);
											?>
										</span>
									<?php endif; ?>
								</div>
							<?php else : ?>
								<?php
								$edit_url = add_query_arg(
									array(
										'page'    => UAPPT_Admin::PAGE_SLUG,
										'section' => 'bookings',
										'action'     => 'edit',
										'booking_id' => $booking['id'],
									),
									admin_url( 'admin.php' )
								);
								?>
								<a class="<?php echo esc_attr( $block_classes ); ?>"
									href="<?php echo esc_url( $edit_url ); ?>"
									title="<?php echo esc_attr( $title ); ?>"
									style="<?php echo esc_attr( $style ); ?>">
									<span class="uappt-day-block-time">
										<?php echo esc_html( $time_label ); ?>
										<?php if ( $is_pending ) : ?>
											<span class="uappt-day-block-flag"><?php esc_html_e( '待分派', 'ultimate-appointments' ); ?></span>
										<?php endif; ?>
									</span>
									<span class="uappt-day-block-name"><?php echo esc_html( $name_label ); ?></span>
									<?php if ( trim( (string) $booking['customer_name'] ) ) : ?>
										<span class="uappt-day-block-customer"><?php echo esc_html( $booking['customer_name'] ); ?></span>
									<?php endif; ?>
								</a>
							<?php endif; ?>
						<?php endforeach; ?>

						<?php if ( null !== $now_minutes ) : ?>
							<div class="uappt-day-now" style="top: <?php echo esc_attr( $uappt_offset( $now_minutes ) ); ?>;"></div>
						<?php endif; ?>
					</div>
				<?php endforeach; ?>
			</div>
		</div>

		<p class="description uappt-day-legend">
			<span class="uappt-day-legend-item"><i class="uappt-swatch uappt-tone-success"></i><?php esc_html_e( '已確認', 'ultimate-appointments' ); ?></span>
			<span class="uappt-day-legend-item"><i class="uappt-swatch uappt-tone-warning"></i><?php esc_html_e( '暫留中／待付款', 'ultimate-appointments' ); ?></span>
			<span class="uappt-day-legend-item"><i class="uappt-swatch uappt-tone-info"></i><?php esc_html_e( '已完成', 'ultimate-appointments' ); ?></span>
			<span class="uappt-day-legend-item"><i class="uappt-swatch uappt-tone-error"></i><?php esc_html_e( '未到', 'ultimate-appointments' ); ?></span>
			<span class="uappt-day-legend-item"><i class="uappt-swatch uappt-tone-muted"></i><?php esc_html_e( '已取消／已逾時', 'ultimate-appointments' ); ?></span>
			<span class="uappt-day-legend-item"><i class="uappt-swatch uappt-swatch-block"></i><?php esc_html_e( '時段佔用', 'ultimate-appointments' ); ?></span>
			<span class="uappt-day-legend-item"><i class="uappt-swatch uappt-swatch-off"></i><?php esc_html_e( '不在班', 'ultimate-appointments' ); ?></span>
		</p>
	<?php endif; ?>
