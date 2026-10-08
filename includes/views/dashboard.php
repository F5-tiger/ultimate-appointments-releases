<?php
/**
 * View：今日營運（後台首頁）。
 *
 * 傳入變數：
 * - $today                目前日期 (Y-m-d)
 * - $today_bookings       今天的客人預約（已確認／已完成／待付款）
 * - $today_count          上面那份清單的筆數
 * - $next_booking         今天接下來最近一筆（可能為 null）
 * - $pending_result       待分派查詢結果（['items'=>[], 'total'=>int]），最多 5 筆
 * - $awaiting_result      待付款查詢結果，同上
 * - $working_today        今天有班的人員，每個元素 ['staff','start_minutes','booking_count']
 * - $on_leave             今天請假的人員（本來要上班，臨時不上）
 * - $scheduled_off        今天排休的人員（例休，早就排定不上班）
 * - $not_scheduled        今天沒有任何班表的人員（三者之外的剩下那些）
 * - $can_approve_shift_requests 目前登入者是否有審核排班申請的權限
 * - $pending_shift_batches      待審排班申請，依批次分組，最多 5 批（$can_approve_shift_requests 為 false 時是空陣列）
 * - $pending_shift_batch_total  待審排班申請的批次總數（不是原始列數——排一整個月只算一件事）
 *
 * @package Ultimate_Appointments
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 把「距離午夜幾分鐘」換成 H:i 顯示字串。純數字運算，不牽涉時區轉換
 * （這些分鐘數本來就是從本地時間的營業區間算出來的，見 day_open_minutes()）。
 *
 * @param int $minutes 分鐘數。
 * @return string
 */
$uappt_hm = function ( $minutes ) {
	$minutes = max( 0, (int) $minutes );
	return sprintf( '%02d:%02d', intdiv( $minutes, 60 ) % 24, $minutes % 60 );
};

// 明確以 wp_timezone() 解析（設計紀律 #1）：裸 strtotime() 會受 PHP 預設時區
// 影響，該時區不一定等於網站在「設定 → 一般」設定的時區，兩者不一致時
// 這行日期標籤可能會早或晚一天。
$today_dt    = date_create( $today . ' 00:00:00', wp_timezone() );
$today_label = $today_dt ? wp_date( 'Y-m-d (D)', $today_dt->getTimestamp() ) : $today;

$bookings_today_url = add_query_arg(
	array(
		'page'    => UAPPT_Admin::PAGE_SLUG,
		'section' => 'bookings',
		'view'      => 'all',
		'date_from' => $today,
		'date_to'   => $today,
	),
	admin_url( 'admin.php' )
);
$pending_url = add_query_arg(
	array(
		'page'    => UAPPT_Admin::PAGE_SLUG,
		'section' => 'bookings',
		'view'             => 'all',
		'assignment_state' => UAPPT_Booking::ASSIGNMENT_PENDING,
	),
	admin_url( 'admin.php' )
);
$awaiting_url = add_query_arg(
	array(
		'page'    => UAPPT_Admin::PAGE_SLUG,
		'section' => 'bookings',
		'view'   => 'all',
		'status' => UAPPT_Booking::STATUS_HELD,
	),
	admin_url( 'admin.php' )
);
$shift_requests_url = add_query_arg(
	array( 'page' => UAPPT_Admin::PAGE_SLUG, 'section' => 'staff', 'tab' => 'requests' ),
	admin_url( 'admin.php' )
);
?>
	<p class="uappt-page-desc"><?php echo esc_html( $today_label ); ?></p>

	<?php
	// 排班缺口。只在真的有缺口時出現——沒有缺口就什麼都不畫，這一頁不需要
	// 一個永遠寫著「一切正常」的框。
	if ( ! $coverage['has_active_staff'] ) :
		?>
		<div class="notice notice-warning inline">
			<p>
				<strong><?php esc_html_e( '目前沒有任何啟用中的人員，前台完全約不到。', 'ultimate-appointments' ); ?></strong>
				<a href="<?php echo esc_url( UAPPT_Admin::url( 'staff' ) ); ?>"><?php esc_html_e( '去人員管理 →', 'ultimate-appointments' ); ?></a>
			</p>
		</div>
	<?php elseif ( $coverage['short'] ) : ?>
		<div class="notice notice-warning inline">
			<p>
				<strong>
					<?php
					if ( null === $coverage['last_covered'] ) {
						printf(
							/* translators: %d: 天數 */
							esc_html__( '接下來 %d 天完全沒有人排班。', 'ultimate-appointments' ),
							(int) $coverage['days']
						);
					} else {
						printf(
							/* translators: 1: 日期 2: 天數 */
							esc_html__( '班表只排到 %1$s，之後還有 %2$d 天沒排。', 'ultimate-appointments' ),
							esc_html( wp_date( 'n/j（D）', strtotime( $coverage['last_covered'] . ' 12:00:00' ) ) ),
							(int) $coverage['uncovered']
						);
					}
					?>
				</strong>
				<?php esc_html_e( '這些日子客人完全約不到，而且前台不會有任何錯誤訊息。', 'ultimate-appointments' ); ?>
				<a href="<?php echo esc_url( UAPPT_Admin::url( 'staff' ) ); ?>"><?php esc_html_e( '去排班 →', 'ultimate-appointments' ); ?></a>
			</p>
		</div>
	<?php endif; ?>

	<?php
	// 逐人的缺口（v2.87.0）。
	//
	// ⚠️ 這張**跟上面那張是不同的問題**，不能互相取代：上面問「整間店有沒有完全
	// 約不到的日子」，這裡問「有沒有某個人約不到」。輪班制的店最常見的失效正好
	// 落在兩者之間——小明下個月一天都沒排，但小美排滿了，全店口徑看起來一切正常，
	// 直到客人指名小明卻看不到任何時段。
	//
	// 所以上面那張沒出現時，這張仍然可能要出現；兩張都出現時也不重複，因為講的
	// 不是同一件事。
	if ( $coverage['has_active_staff'] && ! empty( $coverage['gaps'] ) ) :
		?>
		<div class="notice notice-warning inline">
			<p>
				<strong><?php esc_html_e( '有人的班表排完了：', 'ultimate-appointments' ); ?></strong>
				<?php
				$uappt_gap_labels = array();
				foreach ( $coverage['gaps'] as $uappt_gap ) {
					$uappt_gap_labels[] = null === $uappt_gap['last_covered']
						? sprintf(
							/* translators: %s: 人員姓名 */
							__( '%s（完全沒排）', 'ultimate-appointments' ),
							$uappt_gap['staff']['name']
						)
						: sprintf(
							/* translators: 1: 人員姓名 2: 日期 */
							__( '%1$s（排到 %2$s）', 'ultimate-appointments' ),
							$uappt_gap['staff']['name'],
							wp_date( 'n/j', strtotime( $uappt_gap['last_covered'] . ' 12:00:00' ) )
						);
				}
				echo esc_html( implode( '、', $uappt_gap_labels ) );
				?>
				<?php
				printf(
					/* translators: %d: 開放預約天數 */
					esc_html__( '——指名這幾位的客人，在開放預約的 %d 天內會約不到。', 'ultimate-appointments' ),
					(int) $coverage['days']
				);
				?>
			</p>
			<p>
				<?php
				// 有兩位以上要排時，主要的那顆是「到月排班表」：一次排完所有人，不用一個
				// 一個點進編輯頁（v2.95.0）。月份跳到**最早**斷班的那個月——那是最急的。
				// 只有一位時照舊直接進他的頁面，少一層。
				$uappt_gap_months = array_map(
					static function ( $gap ) {
						return substr( $gap['next_gap'], 0, 7 );
					},
					$coverage['gaps']
				);
				sort( $uappt_gap_months );
				?>
				<?php if ( count( $coverage['gaps'] ) > 1 && current_user_can( UAPPT_Admin::CAP ) ) : ?>
					<a class="button button-small button-primary" href="<?php echo esc_url( UAPPT_Admin::url( 'staff', array( 'tab' => 'roster', 'month' => $uappt_gap_months[0] ) ) ); ?>">
						<?php esc_html_e( '到月排班表一次排', 'ultimate-appointments' ); ?>
					</a>
				<?php endif; ?>
				<?php foreach ( $coverage['gaps'] as $uappt_gap ) : ?>
					<a class="button button-small" href="<?php
						echo esc_url(
							UAPPT_Admin::url(
								'staff',
								array(
									'action'   => 'edit',
									'staff_id' => $uappt_gap['staff']['id'],
									// 直接跳到第一個沒排的月份，不用自己翻月曆。
									'month'    => substr( $uappt_gap['next_gap'], 0, 7 ),
								)
							)
						);
					?>#uappt-month-schedule">
						<?php
						printf(
							/* translators: %s: 人員姓名 */
							esc_html__( '排 %s 的班', 'ultimate-appointments' ),
							esc_html( $uappt_gap['staff']['name'] )
						);
						?>
					</a>
				<?php endforeach; ?>
			</p>
		</div>
	<?php endif; ?>

	<?php
	// 快捷 3：客人快搜。這是四組快捷裡唯一「導覽型」的，之所以值得放——
	// 搜尋框本來就不在區塊導覽上，而「客人打電話來說要改時間」每天都在發生。
	// 送到預約列表的 s 參數，不另外做一套搜尋。
	?>
	<?php
	// 快搜不收合：它是這一頁的快捷之一，藏起來就失去「一進來就能找人」的意義。
	UAPPT_Admin::filters_open(
		array(
			'section'     => 'bookings',
			'hidden'      => array( 'view' => 'all' ),
			'collapsible' => false,
			'class'       => 'uappt-dash-search',
		)
	);
	?>
		<?php UAPPT_Admin::field_open( __( '搜尋客人', 'ultimate-appointments' ), 'uappt-dash-search-input', 'uappt-field-wide' ); ?>
			<input
				type="search"
				id="uappt-dash-search-input"
				name="s"
				class="regular-text"
				placeholder="<?php esc_attr_e( '用姓名或電話找客人的預約…', 'ultimate-appointments' ); ?>"
			/>
		<?php UAPPT_Admin::field_close(); ?>

		<?php UAPPT_Admin::field_open( '', '', 'uappt-field-actions' ); ?>
			<button type="submit" class="button"><?php esc_html_e( '搜尋', 'ultimate-appointments' ); ?></button>
		<?php UAPPT_Admin::field_close(); ?>
	<?php UAPPT_Admin::filters_close(); ?>

	<div class="uappt-dash-cards">
		<a class="uappt-dash-card" href="<?php echo esc_url( $bookings_today_url ); ?>">
			<span class="uappt-dash-card-value"><?php echo esc_html( $today_count ); ?></span>
			<span class="uappt-dash-card-label"><?php esc_html_e( '今天的預約', 'ultimate-appointments' ); ?></span>
		</a>
		<a class="uappt-dash-card <?php echo $pending_result['total'] > 0 ? 'is-warning' : ''; ?>" href="<?php echo esc_url( $pending_url ); ?>">
			<span class="uappt-dash-card-value"><?php echo esc_html( $pending_result['total'] ); ?></span>
			<span class="uappt-dash-card-label"><?php esc_html_e( '待分派', 'ultimate-appointments' ); ?></span>
		</a>
		<a class="uappt-dash-card <?php echo $awaiting_result['total'] > 0 ? 'is-warning' : ''; ?>" href="<?php echo esc_url( $awaiting_url ); ?>">
			<span class="uappt-dash-card-value"><?php echo esc_html( $awaiting_result['total'] ); ?></span>
			<span class="uappt-dash-card-label"><?php esc_html_e( '待付款', 'ultimate-appointments' ); ?></span>
		</a>
		<?php if ( $can_approve_shift_requests ) : ?>
			<a class="uappt-dash-card <?php echo $pending_shift_batch_total > 0 ? 'is-warning' : ''; ?>" href="<?php echo esc_url( $shift_requests_url ); ?>">
				<span class="uappt-dash-card-value"><?php echo esc_html( $pending_shift_batch_total ); ?></span>
				<span class="uappt-dash-card-label"><?php esc_html_e( '待審排班申請', 'ultimate-appointments' ); ?></span>
			</a>
		<?php endif; ?>
	</div>

	<?php
	// 快捷 1（最重要的一組）：今天的預約時間軸，每一列可以直接標記完成／未到。
	//
	// 「客人來了、標記完成」是櫃檯整天做最多次的動作，改版前卻是最遠的動線
	// ——預約 → 篩今天 → 找到那一列 → 編輯頁 → 完成，四次跳轉。現在是 0 次。
	//
	// 這一塊同時取代掉原本那張唯讀的「接下來最近一筆」卡片：同樣的資訊在
	// 時間軸上用「下一位」標出來，不必再單獨佔一格。
	?>
	<div class="uappt-panel uappt-dash-today">
		<?php
		UAPPT_Admin::panel_head(
			'clock',
			__( '今天的預約', 'ultimate-appointments' ),
			$today_count > 0 ? array( 'url' => $bookings_today_url, 'label' => __( '在預約列表打開 →', 'ultimate-appointments' ) ) : array()
		);
		?>
		<?php if ( empty( $today_bookings ) ) : ?>
			<p class="description"><?php esc_html_e( '今天沒有預約。', 'ultimate-appointments' ); ?></p>
		<?php else : ?>
			<table class="widefat striped uappt-table uappt-today-table">
				<tbody>
					<?php foreach ( $today_bookings as $uappt_row ) : ?>
						<?php
						$uappt_is_next   = $next_booking && (int) $next_booking['id'] === (int) $uappt_row['id'];
						$uappt_can_close = UAPPT_Booking::STATUS_CONFIRMED === $uappt_row['status'];
						$uappt_edit_url  = UAPPT_Admin::url( 'bookings', array( 'action' => 'edit', 'booking_id' => $uappt_row['id'] ) );
						?>
						<tr<?php echo $uappt_is_next ? ' class="is-next"' : ''; ?>>
							<td class="uappt-today-time" data-label="<?php esc_attr_e( '時間', 'ultimate-appointments' ); ?>">
								<strong><?php echo esc_html( wp_date( 'H:i', ( new DateTime( $uappt_row['service_start'], wp_timezone() ) )->getTimestamp() ) ); ?></strong>
								<?php if ( $uappt_is_next ) : ?>
									<span class="uappt-badge uappt-badge-info"><?php esc_html_e( '下一位', 'ultimate-appointments' ); ?></span>
								<?php endif; ?>
							</td>
							<td data-label="<?php esc_attr_e( '客人', 'ultimate-appointments' ); ?>">
								<a href="<?php echo esc_url( $uappt_edit_url ); ?>">
									<?php echo esc_html( trim( (string) $uappt_row['customer_name'] ) ?: __( '（未填姓名）', 'ultimate-appointments' ) ); ?>
								</a>
							</td>
							<td data-label="<?php esc_attr_e( '項目', 'ultimate-appointments' ); ?>">
								<?php echo esc_html( UAPPT_Booking::get_booking_display_name( $uappt_row ) ); ?>
							</td>
							<td class="uappt-today-actions" data-label="<?php esc_attr_e( '狀態', 'ultimate-appointments' ); ?>">
								<?php if ( ! $uappt_can_close ) : ?>
									<span class="uappt-badge uappt-badge-<?php echo esc_attr( UAPPT_Admin::status_class( $uappt_row['status'] ) ); ?>">
										<?php echo esc_html( UAPPT_Admin::status_label( $uappt_row['status'] ) ); ?>
									</span>
								<?php else : ?>
									<?php // 兩顆按鈕各自是一張表單：admin-post 的 action 不同，而且共用同一個 nonce。 ?>
									<?php foreach ( array(
										array( 'uappt_complete_booking', __( '完成', 'ultimate-appointments' ), 'button button-primary' ),
										array( 'uappt_mark_no_show', __( '未到', 'ultimate-appointments' ), 'button' ),
									) as $uappt_btn ) : ?>
										<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="uappt-inline-move">
											<input type="hidden" name="action" value="<?php echo esc_attr( $uappt_btn[0] ); ?>" />
											<input type="hidden" name="booking_id" value="<?php echo esc_attr( $uappt_row['id'] ); ?>" />
											<?php UAPPT_Admin::return_field( 'dashboard' ); ?>
											<?php wp_nonce_field( 'uappt_edit_booking_' . $uappt_row['id'] ); ?>
											<button type="submit" class="<?php echo esc_attr( $uappt_btn[2] ); ?>"><?php echo esc_html( $uappt_btn[1] ); ?></button>
										</form>
									<?php endforeach; ?>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</div>

	<div class="uappt-dash-columns">
		<div class="uappt-panel">
			<?php
			UAPPT_Admin::panel_head(
				'user-check',
				__( '待分派', 'ultimate-appointments' ),
				$pending_result['total'] > 5
					? array(
						'url'   => $pending_url,
						'label' => sprintf(
							/* translators: %d: 總筆數 */
							__( '查看全部 %d 筆 →', 'ultimate-appointments' ),
							(int) $pending_result['total']
						),
					)
					: array()
			);
			?>
			<?php if ( empty( $pending_result['items'] ) ) : ?>
				<p class="description"><?php esc_html_e( '目前沒有待分派的預約。', 'ultimate-appointments' ); ?></p>
			<?php else : ?>
				<ul class="uappt-dash-list">
					<?php foreach ( $pending_result['items'] as $booking ) : ?>
						<?php
						// 快捷 2：就地指派。名單跟編輯頁完全一樣（共用
						// UAPPT_Admin::reassign_candidates()），不然會出現
						// 「在這裡選得到、送出卻被打回」。
						// 名單是「這項服務能做的在職人員」，有沒有空由
						// reassign_staff() 在送出當下判斷——跟編輯頁一致。
						$uappt_candidates = UAPPT_Admin::reassign_candidates( $booking );
						$uappt_edit_url   = UAPPT_Admin::url( 'bookings', array( 'action' => 'edit', 'booking_id' => $booking['id'] ) ) . '#uappt-reassign-staff';
						?>
						<li>
							<a href="<?php echo esc_url( $uappt_edit_url ); ?>">
								<span class="uappt-dash-list-time"><?php echo esc_html( UAPPT_Admin::format_booking_label( $booking ) ); ?></span>
								<span class="uappt-dash-list-name"><?php echo esc_html( UAPPT_Booking::get_booking_display_name( $booking ) ); ?></span>
								<span class="uappt-dash-list-customer"><?php echo esc_html( trim( (string) $booking['customer_name'] ) ?: __( '（未填姓名）', 'ultimate-appointments' ) ); ?></span>
							</a>
							<?php if ( $uappt_candidates ) : ?>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="uappt-dash-assign">
									<input type="hidden" name="action" value="uappt_reassign_staff" />
									<input type="hidden" name="booking_id" value="<?php echo esc_attr( $booking['id'] ); ?>" />
									<?php UAPPT_Admin::return_field( 'dashboard' ); ?>
									<?php wp_nonce_field( 'uappt_edit_booking_' . $booking['id'] ); ?>
									<label class="screen-reader-text" for="uappt-assign-<?php echo esc_attr( $booking['id'] ); ?>"><?php esc_html_e( '指派人員', 'ultimate-appointments' ); ?></label>
									<select id="uappt-assign-<?php echo esc_attr( $booking['id'] ); ?>" name="staff_id" required>
										<option value=""><?php esc_html_e( '指派給…', 'ultimate-appointments' ); ?></option>
										<?php foreach ( $uappt_candidates as $uappt_cand ) : ?>
											<option value="<?php echo esc_attr( $uappt_cand['id'] ); ?>"><?php echo esc_html( $uappt_cand['name'] ); ?></option>
										<?php endforeach; ?>
									</select>
									<button type="submit" class="button"><?php esc_html_e( '指派', 'ultimate-appointments' ); ?></button>
								</form>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</div>

		<div class="uappt-panel">
			<?php
			UAPPT_Admin::panel_head(
				'clipboard-list',
				__( '待付款', 'ultimate-appointments' ),
				$awaiting_result['total'] > 5
					? array(
						'url'   => $awaiting_url,
						'label' => sprintf(
							/* translators: %d: 總筆數 */
							__( '查看全部 %d 筆 →', 'ultimate-appointments' ),
							(int) $awaiting_result['total']
						),
					)
					: array()
			);
			?>
			<?php if ( empty( $awaiting_result['items'] ) ) : ?>
				<p class="description"><?php esc_html_e( '目前沒有待付款的預約。', 'ultimate-appointments' ); ?></p>
			<?php else : ?>
				<ul class="uappt-dash-list">
					<?php foreach ( $awaiting_result['items'] as $booking ) : ?>
						<li>
							<a href="<?php echo esc_url( add_query_arg( array( 'page' => UAPPT_Admin::PAGE_SLUG, 'section' => 'bookings', 'action' => 'edit', 'booking_id' => $booking['id'] ), admin_url( 'admin.php' ) ) ); ?>">
								<span class="uappt-dash-list-time"><?php echo esc_html( UAPPT_Admin::format_booking_label( $booking ) ); ?></span>
								<span class="uappt-dash-list-name"><?php echo esc_html( UAPPT_Booking::get_booking_display_name( $booking ) ); ?></span>
								<span class="uappt-dash-list-customer">
									<?php if ( $booking['order_id'] ) : ?>
										#<?php echo esc_html( $booking['order_id'] ); ?>
									<?php endif; ?>
								</span>
							</a>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</div>

		<?php if ( $can_approve_shift_requests ) : ?>
			<div class="uappt-panel">
				<?php
				UAPPT_Admin::panel_head(
					'clock',
					__( '待審排班申請', 'ultimate-appointments' ),
					$pending_shift_batch_total > 5
						? array(
							'url'   => $shift_requests_url,
							'label' => sprintf(
								/* translators: %d: 總批次數 */
								__( '查看全部 %d 批 →', 'ultimate-appointments' ),
								(int) $pending_shift_batch_total
							),
						)
						: array()
				);
				?>
				<?php if ( empty( $pending_shift_batches ) ) : ?>
					<p class="description"><?php esc_html_e( '目前沒有待審核的排班申請。', 'ultimate-appointments' ); ?></p>
				<?php else : ?>
					<ul class="uappt-dash-list">
						<?php foreach ( $pending_shift_batches as $batch ) : ?>
							<li>
								<a href="<?php echo esc_url( $shift_requests_url ); ?>">
									<span class="uappt-dash-list-time">
										<?php
										echo esc_html(
											$batch['date_from'] === $batch['date_to']
												? $batch['date_from']
												: $batch['date_from'] . '–' . $batch['date_to']
										);
										?>
									</span>
									<span class="uappt-dash-list-name"><?php echo esc_html( UAPPT_Admin::staff_label( $batch['staff_id'] ) ); ?></span>
									<span class="uappt-dash-list-customer">
										<?php
										echo esc_html( UAPPT_Admin::shift_request_type_label( $batch['type'] ) );
										if ( count( $batch['dates'] ) > 1 ) {
											echo ' ';
											printf(
												/* translators: %d: 天數 */
												esc_html__( '（共 %d 天）', 'ultimate-appointments' ),
												count( $batch['dates'] )
											);
										}
										?>
									</span>
								</a>
								<?php
								// 快捷 4：就地核准／駁回。跟排班申請頁共用同一組表單元件，
								// 只是不顯示審核備註欄（首頁要短），並帶上 return_to 讓按完
								// 留在原地。核准的衝突檢查在 approve_batch() 裡會重跑一次，
								// 這裡少了那份「有幾天硬衝突」的提示，所以刻意不停用核准鈕
								// ——要細看的人點標題進排班申請頁。
								UAPPT_Admin::shift_review_forms(
									array(
										'approve_action' => 'uappt_approve_shift_batch',
										'reject_action'  => 'uappt_reject_shift_batch',
										'id_field'       => 'batch_key',
										// ⚠️ 欄位名是 key 不是 batch_key——query_batches() 分組
										// 之後的那一列用的是 key（單日申請沒有真正的 batch_key，
										// 分組時會自己補一個）。
										'id_value'       => $batch['key'],
										'nonce_action'   => 'uappt_review_shift_batch_' . $batch['key'],
										'with_note'      => false,
										'return_to'      => 'dashboard',
									)
								);
								?>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
			</div>
		<?php endif; ?>

		<div class="uappt-panel">
			<?php UAPPT_Admin::panel_head( 'users', __( '今天的人力', 'ultimate-appointments' ) ); ?>
			<?php if ( empty( $working_today ) ) : ?>
				<p class="description"><?php esc_html_e( '今天沒有任何啟用中的人員排班。', 'ultimate-appointments' ); ?></p>
			<?php else : ?>
				<ul class="uappt-dash-list uappt-dash-list--staff">
					<?php foreach ( $working_today as $row ) : ?>
						<li>
							<span class="uappt-dash-list-time"><?php echo esc_html( $uappt_hm( $row['start_minutes'] ) ); ?> ~</span>
							<span class="uappt-dash-list-name"><?php echo esc_html( $row['staff']['name'] ); ?></span>
							<span class="uappt-badge uappt-badge-info"><?php echo esc_html( $row['booking_count'] ); ?></span>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>

			<?php if ( ! empty( $on_leave ) ) : ?>
				<p class="uappt-dash-sublabel"><?php esc_html_e( '今天請假', 'ultimate-appointments' ); ?></p>
				<p class="uappt-dash-tags">
					<?php foreach ( $on_leave as $staff ) : ?>
						<span class="uappt-badge uappt-badge-muted"><?php echo esc_html( $staff['name'] ); ?></span>
					<?php endforeach; ?>
				</p>
			<?php endif; ?>

			<?php
			// 排休排在請假後面、沒排班前面：三者的「意外程度」由高到低。請假是
			// 今天才少一個人（最需要知道），排休是早就排定的，沒排班則是「這個人
			// 根本沒有班表」——之後護欄要報的就是最後這一類。
			?>
			<?php if ( ! empty( $scheduled_off ) ) : ?>
				<p class="uappt-dash-sublabel"><?php esc_html_e( '今天排休', 'ultimate-appointments' ); ?></p>
				<p class="uappt-dash-tags">
					<?php foreach ( $scheduled_off as $staff ) : ?>
						<span class="uappt-badge uappt-badge-muted"><?php echo esc_html( $staff['name'] ); ?></span>
					<?php endforeach; ?>
				</p>
			<?php endif; ?>

			<?php if ( ! empty( $not_scheduled ) ) : ?>
				<p class="uappt-dash-sublabel"><?php esc_html_e( '今天沒有排班', 'ultimate-appointments' ); ?></p>
				<p class="uappt-dash-tags">
					<?php foreach ( $not_scheduled as $staff ) : ?>
						<span class="uappt-badge uappt-badge-muted"><?php echo esc_html( $staff['name'] ); ?></span>
					<?php endforeach; ?>
				</p>
			<?php endif; ?>
		</div>
	</div>
