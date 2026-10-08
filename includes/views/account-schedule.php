<?php
/**
 * View：前台會員中心「我的班表」分頁——月曆檢視。
 *
 * 排班在真實情境裡是一週／一個月排一次，這裡刻意用月曆而不是逐日清單：
 * 月曆本身就是「本月我打算怎麼上班」的選取介面（勾選多天→一次送出申請），
 * 也是唯一能一眼看出整個月形狀的呈現方式。窄螢幕（手機）退回逐日清單，見
 * frontend.css 的 max-width: 600px 那段。
 *
 * 「本月明細」（已排定的預約清單，標記完成／未到／還原／備註）原本也在這一頁，
 * 現在拆成獨立頁籤，見 account-monthly-detail.php——這裡的日期數字只負責連過去
 * （$uappt_detail_url），不再自己渲染明細。
 *
 * 傳入變數：
 * - $staff               目前登入者對應的人員資料（已解碼 business_hours）
 * - $calendar            UAPPT_Staff_Portal::get_schedule_month() 的回傳值：
 *                         ['month','prev_month','next_month','weeks','days']
 *                         每一天：['date','is_in_month','is_today','is_past',
 *                                  'weekday','ranges','override','pending','bookings']
 * - $pending_batches     自己還在等待審核的申請，依批次分組（UAPPT_Shift_Request::query_batches()）
 * - $can_submit_requests 是否可以送出新的排班申請（uappt_submit_shift_requests 且人員為 active）
 *
 * @package Ultimate_Appointments
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$uappt_endpoint_url = wc_get_account_endpoint_url( UAPPT_Staff_Portal::ENDPOINT );
$uappt_prev_url     = add_query_arg( 'uappt_month', $calendar['prev_month'], $uappt_endpoint_url );
$uappt_next_url     = add_query_arg( 'uappt_month', $calendar['next_month'], $uappt_endpoint_url );

// 「本月明細」是獨立頁籤，這裡的日期數字要連過去（帶同一個月份）。
// v2.23.0 起明細頁也是月曆式，partial 會再把 uappt_date 接上去，點哪一天
// 就直接展開那一天，不再是捲到同頁錨點。
$uappt_detail_url = add_query_arg(
	'uappt_month',
	$calendar['month'],
	wc_get_account_endpoint_url( UAPPT_Staff_Portal::ENDPOINT_MONTHLY_DETAIL )
);
$uappt_month_dt     = date_create( $calendar['month'] . '-01', wp_timezone() );
$uappt_month_label  = $uappt_month_dt ? wp_date( 'Y 年 n 月', $uappt_month_dt->getTimestamp() ) : $calendar['month'];

// 有東西可看的日子（有預約、有逐日調整、或有待審申請）才會在下方展開明細，
// 空白的日子只在月曆格子上顯示班表，不用另外佔一段版面。
$uappt_days_with_detail = array();
foreach ( $calendar['days'] as $uappt_date => $uappt_day ) {
	if ( ! empty( $uappt_day['bookings'] ) || ! empty( $uappt_day['override'] ) || ! empty( $uappt_day['pending'] ) ) {
		$uappt_days_with_detail[ $uappt_date ] = $uappt_day;
	}
}
?>
<h2><?php esc_html_e( '我的班表', 'ultimate-appointments' ); ?></h2>
<p class="description">
	<?php esc_html_e( '灰色是尚未排班的日子，日期上的顏色代表當天的班表狀態；核准後的排班申請會直接反映在這裡。', 'ultimate-appointments' ); ?>
</p>

<?php if ( ! empty( $pending_batches ) ) : ?>
	<div class="uappt-schedule-pending">
		<h3><?php esc_html_e( '等待審核中的申請', 'ultimate-appointments' ); ?></h3>
		<p class="description">
			<?php esc_html_e( '整批送出的申請可以展開逐日明細，單獨撤回其中某一天，不必整批重送。', 'ultimate-appointments' ); ?>
		</p>
		<ul class="uappt-schedule-pending-list">
			<?php foreach ( $pending_batches as $uappt_batch ) : ?>
				<?php $uappt_batch_days = count( $uappt_batch['rows'] ); ?>
				<li>
					<div class="uappt-schedule-pending-head">
						<span class="uappt-schedule-pending-range">
							<?php
							if ( $uappt_batch_days > 1 ) {
								printf(
									/* translators: 1: 起始日期 2: 結束日期 3: 天數 */
									esc_html__( '%1$s ～ %2$s（共 %3$d 天）', 'ultimate-appointments' ),
									esc_html( $uappt_batch['date_from'] ),
									esc_html( $uappt_batch['date_to'] ),
									$uappt_batch_days
								);
							} else {
								echo esc_html( $uappt_batch['date_from'] );
							}
							?>
						</span>
						<span class="uappt-schedule-pending-type"><?php echo esc_html( UAPPT_Admin::shift_request_type_label( $uappt_batch['type'] ) ); ?></span>
						<?php if ( 1 === $uappt_batch_days ) : ?>
							<span class="uappt-schedule-pending-detail-text"><?php echo esc_html( UAPPT_Admin::shift_request_detail_label( $uappt_batch['rows'][0] ) ); ?></span>
						<?php endif; ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="uappt-schedule-inline-form">
							<input type="hidden" name="action" value="uappt_staff_withdraw_shift_request" />
							<input type="hidden" name="batch_key" value="<?php echo esc_attr( $uappt_batch['key'] ); ?>" />
							<?php wp_nonce_field( 'uappt_staff_withdraw_shift_request_' . $uappt_batch['key'] ); ?>
							<button type="submit" class="button">
								<?php echo $uappt_batch_days > 1 ? esc_html__( '撤回整批', 'ultimate-appointments' ) : esc_html__( '撤回', 'ultimate-appointments' ); ?>
							</button>
						</form>
					</div>

					<?php if ( $uappt_batch_days > 1 ) : ?>
						<details class="uappt-schedule-pending-days">
							<summary>
								<?php
								printf(
									/* translators: %d: 這批共幾天 */
									esc_html__( '逐日明細（%d 天）', 'ultimate-appointments' ),
									$uappt_batch_days
								);
								?>
							</summary>
							<ul>
								<?php foreach ( $uappt_batch['rows'] as $uappt_row ) : ?>
									<li>
										<span class="uappt-schedule-pending-day-date"><?php echo esc_html( $uappt_row['request_date'] ); ?></span>
										<span class="uappt-schedule-pending-detail-text"><?php echo esc_html( UAPPT_Admin::shift_request_detail_label( $uappt_row ) ); ?></span>
										<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="uappt-schedule-inline-form">
											<input type="hidden" name="action" value="uappt_staff_withdraw_shift_request_day" />
											<input type="hidden" name="request_id" value="<?php echo esc_attr( $uappt_row['id'] ); ?>" />
											<?php wp_nonce_field( 'uappt_staff_withdraw_shift_request_day_' . $uappt_row['id'] ); ?>
											<button type="submit" class="button"><?php esc_html_e( '撤回這天', 'ultimate-appointments' ); ?></button>
										</form>
									</li>
								<?php endforeach; ?>
							</ul>
						</details>
					<?php endif; ?>
				</li>
			<?php endforeach; ?>
		</ul>
	</div>
<?php endif; ?>

<div class="uappt-cal-nav">
	<a class="button" href="<?php echo esc_url( $uappt_prev_url ); ?>">&laquo; <?php esc_html_e( '上個月', 'ultimate-appointments' ); ?></a>
	<strong class="uappt-cal-nav-label"><?php echo esc_html( $uappt_month_label ); ?></strong>
	<a class="button" href="<?php echo esc_url( $uappt_next_url ); ?>"><?php esc_html_e( '下個月', 'ultimate-appointments' ); ?> &raquo;</a>
</div>

<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="uappt-shift-batch-form">
	<input type="hidden" name="action" value="uappt_staff_submit_shift_request" />
	<?php wp_nonce_field( 'uappt_staff_submit_shift_request' ); ?>

	<?php
	// 月曆格線與「本月明細」共用同一份 partial（見 partials/month-grid.php）。
	// 這一頁的月曆是「選取介面」：格子可以勾、日期數字連去明細頁的同一天。
	$grid_mode          = 'schedule';
	$grid_selectable    = $can_submit_requests;
	$grid_day_url       = $uappt_detail_url;
	$grid_linkable      = $uappt_days_with_detail;
	$grid_selected_date = '';
	$grid_amounts       = array();
	$grid_editable      = false;
	// 前台一律不塗：排班是主管的事，員工端只能送申請。
	$grid_paintable     = false;
require UAPPT_PLUGIN_DIR . 'includes/views/partials/month-grid.php';
	?>

	<?php if ( $can_submit_requests ) : ?>
		<div class="uappt-schedule-submit">
			<h3><?php esc_html_e( '送出新的排班申請', 'ultimate-appointments' ); ?></h3>
			<p class="description">
				<?php esc_html_e( '在上面的月曆勾選要申請的日子（可以跨週、跨整個月），選好類型與內容後一次送出；核准前不會影響你目前的可預約時段。', 'ultimate-appointments' ); ?>
			</p>

			<p class="uappt-cal-toolbar">
				<button type="button" class="button" id="uappt-select-all-month"><?php esc_html_e( '全選本月', 'ultimate-appointments' ); ?></button>
				<button type="button" class="button" id="uappt-select-none"><?php esc_html_e( '清除選取', 'ultimate-appointments' ); ?></button>
				<span id="uappt-selected-count"><?php esc_html_e( '已選 0 天', 'ultimate-appointments' ); ?></span>
			</p>

			<p>
				<label>
					<input type="radio" name="type" value="leave" checked="checked" class="uappt-request-type" />
					<?php esc_html_e( '請假（整天）', 'ultimate-appointments' ); ?>
				</label>
				<label>
					<input type="radio" name="type" value="hours" class="uappt-request-type" />
					<?php esc_html_e( '自訂上班時段', 'ultimate-appointments' ); ?>
				</label>
				<label>
					<input type="radio" name="type" value="block" class="uappt-request-type" />
					<?php esc_html_e( '登記時段佔用（開會／訓練等）', 'ultimate-appointments' ); ?>
				</label>
			</p>

			<div class="uappt-request-hours-row" style="display:none;">
				<label><?php esc_html_e( '上班時段', 'ultimate-appointments' ); ?></label>
				<p class="description">
					<?php esc_html_e( '最多可以填 3 段（例如中午休息、深夜加開一段），會套用到你勾選的每一天。', 'ultimate-appointments' ); ?>
				</p>
				<?php for ( $uappt_i = 0; $uappt_i < 3; $uappt_i++ ) : ?>
					<p>
						<input type="text" inputmode="numeric" autocomplete="off" name="hours[<?php echo esc_attr( $uappt_i ); ?>][start]" placeholder="09:00" />
						–
						<input type="text" inputmode="numeric" autocomplete="off" name="hours[<?php echo esc_attr( $uappt_i ); ?>][end]" placeholder="13:00" />
					</p>
				<?php endfor; ?>
				<?php
				// 彈性班（或樣板整張空白）沒有「平常的每週班表」可以套——按下去只會
				// 清空三個欄位，看起來像壞掉（v2.93.0）。
				if ( ! UAPPT_Staff::is_flex( $staff ) && UAPPT_Staff::template_has_ranges( $staff['business_hours'] ) ) :
					?>
				<p>
					<button type="button" class="button" id="uappt-apply-weekly-template">
						<?php esc_html_e( '套用每週預設班表', 'ultimate-appointments' ); ?>
					</button>
					<span class="description"><?php esc_html_e( '取你勾選的第一天套用平常的每週班表，再自行調整差異。', 'ultimate-appointments' ); ?></span>
				</p>
				<?php endif; ?>
			</div>

			<p class="uappt-request-block-row" style="display:none;">
				<label><?php esc_html_e( '佔用時段', 'ultimate-appointments' ); ?></label>
				<input type="text" inputmode="numeric" autocomplete="off" name="start_hm" placeholder="14:00" />
				–
				<input type="text" inputmode="numeric" autocomplete="off" name="end_hm" placeholder="15:00" />
			</p>

			<p>
				<label for="uappt-request-note"><?php esc_html_e( '備註', 'ultimate-appointments' ); ?></label>
				<input type="text" id="uappt-request-note" name="staff_note" class="regular-text" placeholder="<?php esc_attr_e( '（可留空）', 'ultimate-appointments' ); ?>" />
			</p>

			<p>
				<button type="submit" class="button"><?php esc_html_e( '送出申請', 'ultimate-appointments' ); ?></button>
			</p>
		</div>
	<?php elseif ( 'active' !== $staff['status'] ) : ?>
		<p class="description"><?php esc_html_e( '你目前是停用狀態，暫時無法送出新的排班申請，如有需要請聯繫店家。', 'ultimate-appointments' ); ?></p>
	<?php endif; ?>
</form>

<script>
( function () {
	// 類型切換（跟舊版一樣的做法，純 CSS/JS，不做 AJAX）。
	var typeRadios = document.querySelectorAll( '.uappt-request-type' );
	var hoursRow   = document.querySelector( '.uappt-request-hours-row' );
	var blockRow   = document.querySelector( '.uappt-request-block-row' );
	function syncType() {
		var checked = document.querySelector( '.uappt-request-type:checked' );
		var value   = checked ? checked.value : 'leave';
		if ( hoursRow ) {
			hoursRow.style.display = ( 'hours' === value ) ? '' : 'none';
		}
		if ( blockRow ) {
			blockRow.style.display = ( 'block' === value ) ? '' : 'none';
		}
	}
	typeRadios.forEach( function ( radio ) {
		radio.addEventListener( 'change', syncType );
	} );
	syncType();

	// 月曆多選：計數、全選本月、清除選取。
	var checkboxes = document.querySelectorAll( '.uappt-cal-checkbox' );
	var counter     = document.getElementById( 'uappt-selected-count' );
	var selectAll   = document.getElementById( 'uappt-select-all-month' );
	var selectNone  = document.getElementById( 'uappt-select-none' );

	function syncCount() {
		if ( ! counter ) {
			return;
		}
		var checkedCount = document.querySelectorAll( '.uappt-cal-checkbox:checked' ).length;
		counter.textContent = '<?php echo esc_js( __( '已選', 'ultimate-appointments' ) ); ?> ' + checkedCount + ' <?php echo esc_js( __( '天', 'ultimate-appointments' ) ); ?>';
	}
	checkboxes.forEach( function ( box ) {
		box.addEventListener( 'change', syncCount );
	} );
	if ( selectAll ) {
		selectAll.addEventListener( 'click', function () {
			checkboxes.forEach( function ( box ) {
				box.checked = true;
			} );
			syncCount();
		} );
	}
	if ( selectNone ) {
		selectNone.addEventListener( 'click', function () {
			checkboxes.forEach( function ( box ) {
				box.checked = false;
			} );
			syncCount();
		} );
	}
	syncCount();

	// 套用每週預設班表：取第一個勾選日期的星期幾，把當天的每週範本時段
	// 填進「自訂上班時段」的三個欄位，讓員工從範本出發只改差異——這是
	// 「每週可營業時間」跟排班申請整合後，介面上唯一看得見的接點。
	var applyBtn      = document.getElementById( 'uappt-apply-weekly-template' );
	var weeklyHours    = <?php echo wp_json_encode( $staff['business_hours'] ); ?>;
	if ( applyBtn ) {
		applyBtn.addEventListener( 'click', function () {
			var firstChecked = document.querySelector( '.uappt-cal-checkbox:checked' );
			if ( ! firstChecked ) {
				window.alert( '<?php echo esc_js( __( '請先在月曆上勾選至少一天。', 'ultimate-appointments' ) ); ?>' );
				return;
			}
			var weekday = firstChecked.getAttribute( 'data-weekday' );
			var ranges  = ( weeklyHours && weeklyHours[ weekday ] ) ? weeklyHours[ weekday ] : [];
			for ( var i = 0; i < 3; i++ ) {
				var startInput = document.querySelector( '[name="hours[' + i + '][start]"]' );
				var endInput   = document.querySelector( '[name="hours[' + i + '][end]"]' );
				var range      = ranges[ i ] || [ '', '' ];
				if ( startInput ) {
					startInput.value = range[0] || '';
				}
				if ( endInput ) {
					endInput.value = range[1] || '';
				}
			}
		} );
	}
} )();
</script>
