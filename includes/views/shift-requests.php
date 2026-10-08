<?php
/**
 * View：排班申請審核——依批次分組，一列＝一次員工送出的申請（可能只有一天，
 * 也可能是一整個月），展開才看得到逐日明細與單筆操作。
 *
 * 傳入變數：
 * - $status           目前選取的狀態頁籤（pending/approved/rejected/withdrawn/all）
 * - $batches          這一頁的批次列表，每筆：
 *                      ['key','is_single','staff_id','type','staff_note','created_at',
 *                       'rows','dates','date_from','date_to','status_counts','hard_dates']
 *                      （'hard_dates' 只有待審批次才會計算，見 render_shift_requests_page()）
 * - $conflicts_by_id  待審申請（單筆）的衝突檢查結果，key 是 request id
 *                      （['hard'=>[], 'soft_bookings'=>[], 'soft_requests'=>[]]）
 * - $paged            目前頁碼
 * - $total_pages      總頁數（以「批次」為單位分頁，不是原始列）
 *
 * @package Ultimate_Appointments
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$uappt_tabs = array(
	UAPPT_Shift_Request::STATUS_PENDING   => __( '待審核', 'ultimate-appointments' ),
	UAPPT_Shift_Request::STATUS_APPROVED  => __( '已核准', 'ultimate-appointments' ),
	UAPPT_Shift_Request::STATUS_REJECTED  => __( '已駁回', 'ultimate-appointments' ),
	UAPPT_Shift_Request::STATUS_WITHDRAWN => __( '已撤回', 'ultimate-appointments' ),
	'all'                                 => __( '全部', 'ultimate-appointments' ),
);
?>
	<p class="uappt-page-desc">
		<?php esc_html_e( '員工在前台會員中心送出的排班／請假／時段佔用申請，核准之後才會反映到日曆上的實際可預約時段；核准前完全不影響任何預約。一次送出多天會顯示成一批，一鍵核准即可，展開可以看到逐日明細或單獨處理某一天。', 'ultimate-appointments' ); ?>
	</p>

	<?php
	// 狀態從「頁籤」改成「篩選列的下拉」（v2.36.0）。排班申請併進「人員」
	// 之後，區塊導覽（nav-tab）與人員的頁籤（subsubsub）已經佔掉兩層，
	// 狀態再來一排 subsubsub 就是第三層長得跟第二層一樣的東西。
	// 而且它本來就是「同一份清單的篩選」，用外掛裡其他六頁共用的 .uappt-filters
	// 才是對的位置。
	?>
	<?php
	// 只有一格，不收合：收起來要一行、攤開也是一行，收合只會多一次點擊。
	UAPPT_Admin::filters_open(
		array(
			'section'     => 'staff',
			'hidden'      => array( 'tab' => 'requests' ),
			'collapsible' => false,
		)
	);
	?>
		<?php UAPPT_Admin::field_open( __( '狀態', 'ultimate-appointments' ), 'uappt-filter-status' ); ?>
			<select id="uappt-filter-status" name="status">
				<?php foreach ( $uappt_tabs as $tab_key => $tab_label ) : ?>
					<option value="<?php echo esc_attr( $tab_key ); ?>" <?php selected( $status, $tab_key ); ?>>
						<?php echo esc_html( $tab_label ); ?>
					</option>
				<?php endforeach; ?>
			</select>
		<?php UAPPT_Admin::field_close(); ?>

		<?php UAPPT_Admin::field_open( '', '', 'uappt-field-actions' ); ?>
			<button type="submit" class="button"><?php esc_html_e( '套用', 'ultimate-appointments' ); ?></button>
		<?php UAPPT_Admin::field_close(); ?>
	<?php UAPPT_Admin::filters_close(); ?>

	<?php if ( empty( $batches ) ) : ?>
		<p class="description"><?php esc_html_e( '目前沒有符合條件的申請。', 'ultimate-appointments' ); ?></p>
	<?php else : ?>
		<table class="widefat striped uappt-table uappt-shift-batches">
			<thead>
				<tr>
					<th><?php esc_html_e( '人員', 'ultimate-appointments' ); ?></th>
					<th><?php esc_html_e( '日期範圍', 'ultimate-appointments' ); ?></th>
					<th><?php esc_html_e( '類型', 'ultimate-appointments' ); ?></th>
					<th><?php esc_html_e( '狀態', 'ultimate-appointments' ); ?></th>
					<th><?php esc_html_e( '操作／審核紀錄', 'ultimate-appointments' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $batches as $uappt_batch ) : ?>
					<?php
					$uappt_day_count    = count( $uappt_batch['dates'] );
					$uappt_hard_count   = isset( $uappt_batch['hard_dates'] ) ? count( $uappt_batch['hard_dates'] ) : 0;
					$uappt_pending_count = isset( $uappt_batch['status_counts'][ UAPPT_Shift_Request::STATUS_PENDING ] )
						? $uappt_batch['status_counts'][ UAPPT_Shift_Request::STATUS_PENDING ]
						: 0;
					?>
					<tr>
						<td data-label="<?php esc_attr_e( '人員', 'ultimate-appointments' ); ?>"><?php echo esc_html( UAPPT_Admin::staff_label( $uappt_batch['staff_id'] ) ); ?></td>
						<td data-label="<?php esc_attr_e( '日期範圍', 'ultimate-appointments' ); ?>">
							<?php
							if ( $uappt_day_count > 1 ) {
								printf(
									/* translators: 1: 起始日期 2: 結束日期 3: 天數 */
									esc_html__( '%1$s ～ %2$s（共 %3$d 天）', 'ultimate-appointments' ),
									esc_html( $uappt_batch['date_from'] ),
									esc_html( $uappt_batch['date_to'] ),
									$uappt_day_count
								);
							} else {
								echo esc_html( $uappt_batch['date_from'] );
							}
							?>
						</td>
						<td data-label="<?php esc_attr_e( '類型', 'ultimate-appointments' ); ?>"><?php echo esc_html( UAPPT_Admin::shift_request_type_label( $uappt_batch['type'] ) ); ?></td>
						<td data-label="<?php esc_attr_e( '狀態', 'ultimate-appointments' ); ?>">
							<?php foreach ( $uappt_batch['status_counts'] as $uappt_status_key => $uappt_status_count ) : ?>
								<span class="uappt-badge uappt-badge-<?php echo esc_attr( UAPPT_Admin::shift_request_status_class( $uappt_status_key ) ); ?>">
									<?php echo esc_html( UAPPT_Admin::shift_request_status_label( $uappt_status_key ) . ' ' . $uappt_status_count ); ?>
								</span>
							<?php endforeach; ?>
						</td>
						<td class="uappt-cell-block" data-label="<?php esc_attr_e( '操作／審核紀錄', 'ultimate-appointments' ); ?>">
							<?php if ( $uappt_pending_count > 0 ) : ?>
								<?php if ( $uappt_hard_count > 0 ) : ?>
									<p class="uappt-shift-conflict uappt-shift-conflict--hard">
										<?php
										printf(
											/* translators: %d: 有硬衝突的天數 */
											esc_html__( '⚠ 其中 %d 天核准後會影響到已確認／暫留中的預約，請展開查看明細。', 'ultimate-appointments' ),
											$uappt_hard_count
										);
										?>
									</p>
								<?php endif; ?>

								<?php
								UAPPT_Admin::shift_review_forms(
									array(
										'approve_action' => 'uappt_approve_shift_batch',
										'reject_action'  => 'uappt_reject_shift_batch',
										'id_field'       => 'batch_key',
										'id_value'       => $uappt_batch['key'],
										'nonce_action'   => 'uappt_review_shift_batch_' . $uappt_batch['key'],
										'with_note'      => true,
										'approve_label'  => ( $uappt_hard_count > 0 && $uappt_hard_count < $uappt_pending_count )
											? __( '核准可以核准的天數', 'ultimate-appointments' )
											: __( '一鍵核准', 'ultimate-appointments' ),
										'reject_label'   => __( '整批駁回', 'ultimate-appointments' ),
									)
								);
								?>
							<?php elseif ( ! empty( $uappt_batch['rows'][0]['reviewed_at'] ) ) : ?>
								<p class="description">
									<?php
									printf(
										/* translators: %s: 審核時間 */
										esc_html__( '審核於 %s', 'ultimate-appointments' ),
										esc_html( $uappt_batch['rows'][0]['reviewed_at'] )
									);
									?>
								</p>
							<?php endif; ?>

							<?php if ( $uappt_batch['staff_note'] ) : ?>
								<p class="description">
									<?php
									printf(
										/* translators: %s: 員工備註 */
										esc_html__( '員工備註：%s', 'ultimate-appointments' ),
										esc_html( $uappt_batch['staff_note'] )
									);
									?>
								</p>
							<?php endif; ?>

							<?php if ( 0 === $uappt_pending_count && empty( $uappt_batch['rows'][0]['reviewed_at'] ) && ! $uappt_batch['staff_note'] ) : ?>
								—
							<?php endif; ?>
						</td>
					</tr>
					<?php if ( $uappt_day_count > 1 || $uappt_pending_count > 0 ) : ?>
						<?php
						// 逐日明細獨立成一整列（colspan 涵蓋全部五欄）。原本它塞在最後
						// 一格裡，那一格只是五欄之一——實測內層那張四欄表格只拿得到
						// 442px（外層 883px 的一半），衝突清單與審核按鈕全被擠在一起。
						// 搬成整列之後寬度直接翻倍。
						?>
						<tr class="uappt-shift-detail-row">
							<td colspan="5">
								<details class="uappt-shift-batch-detail">
									<summary><?php esc_html_e( '展開逐日明細', 'ultimate-appointments' ); ?></summary>
									<table class="widefat striped uappt-table">
										<thead>
											<tr>
												<th><?php esc_html_e( '日期', 'ultimate-appointments' ); ?></th>
												<th><?php esc_html_e( '內容', 'ultimate-appointments' ); ?></th>
												<th><?php esc_html_e( '狀態', 'ultimate-appointments' ); ?></th>
												<th><?php esc_html_e( '操作／審核紀錄', 'ultimate-appointments' ); ?></th>
											</tr>
										</thead>
										<tbody>
											<?php foreach ( $uappt_batch['rows'] as $uappt_row ) : ?>
												<?php $uappt_row_conflicts = isset( $conflicts_by_id[ $uappt_row['id'] ] ) ? $conflicts_by_id[ $uappt_row['id'] ] : null; ?>
												<tr>
													<td data-label="<?php esc_attr_e( '日期', 'ultimate-appointments' ); ?>"><?php echo esc_html( $uappt_row['request_date'] ); ?></td>
													<td data-label="<?php esc_attr_e( '內容', 'ultimate-appointments' ); ?>"><?php echo esc_html( UAPPT_Admin::shift_request_detail_label( $uappt_row ) ); ?></td>
													<td data-label="<?php esc_attr_e( '狀態', 'ultimate-appointments' ); ?>">
														<span class="uappt-badge uappt-badge-<?php echo esc_attr( UAPPT_Admin::shift_request_status_class( $uappt_row['status'] ) ); ?>">
															<?php echo esc_html( UAPPT_Admin::shift_request_status_label( $uappt_row['status'] ) ); ?>
														</span>
													</td>
													<td class="uappt-cell-block" data-label="<?php esc_attr_e( '操作／審核紀錄', 'ultimate-appointments' ); ?>">
														<?php if ( UAPPT_Shift_Request::STATUS_PENDING === $uappt_row['status'] && $uappt_row_conflicts ) : ?>
															<?php if ( ! empty( $uappt_row_conflicts['hard'] ) ) : ?>
																<p class="uappt-shift-conflict uappt-shift-conflict--hard">
																	<?php
																	printf(
																		/* translators: %d: 受影響的預約筆數 */
																		esc_html__( '⚠ 核准後會有 %d 筆已確認／暫留中的預約落在營業時間之外，無法核准。', 'ultimate-appointments' ),
																		count( $uappt_row_conflicts['hard'] )
																	);
																	?>
																</p>
																<ul class="uappt-shift-conflict-list">
																	<?php foreach ( $uappt_row_conflicts['hard'] as $uappt_conflict_booking ) : ?>
																		<li>
																			<a href="<?php echo esc_url( add_query_arg( array( 'page' => UAPPT_Admin::PAGE_SLUG, 'section' => 'bookings', 'action' => 'edit', 'booking_id' => $uappt_conflict_booking['id'] ), admin_url( 'admin.php' ) ) ); ?>">
																				#<?php echo esc_html( $uappt_conflict_booking['id'] ); ?>
																				<?php echo esc_html( $uappt_conflict_booking['service_start'] ); ?>
																				<?php echo esc_html( trim( (string) $uappt_conflict_booking['customer_name'] ) ); ?>
																			</a>
																		</li>
																	<?php endforeach; ?>
																</ul>
															<?php endif; ?>

															<?php if ( ! empty( $uappt_row_conflicts['soft_bookings'] ) ) : ?>
																<p class="uappt-shift-conflict uappt-shift-conflict--soft">
																	<?php
																	printf(
																		/* translators: %d: 受影響的紀錄筆數 */
																		esc_html__( '提醒：這天另外還有 %d 筆時段佔用／已結束的紀錄落在營業時間之外，不影響核准，僅供參考。', 'ultimate-appointments' ),
																		count( $uappt_row_conflicts['soft_bookings'] )
																	);
																	?>
																</p>
															<?php endif; ?>

															<?php
															UAPPT_Admin::shift_review_forms(
																array(
																	'approve_action'   => 'uappt_approve_shift_request',
																	'reject_action'    => 'uappt_reject_shift_request',
																	'id_field'         => 'request_id',
																	'id_value'         => $uappt_row['id'],
																	'nonce_action'     => 'uappt_review_shift_request_' . $uappt_row['id'],
																	'approve_label'    => __( '核准這天', 'ultimate-appointments' ),
																	'reject_label'     => __( '駁回這天', 'ultimate-appointments' ),
																	'approve_disabled' => ! empty( $uappt_row_conflicts['hard'] ),
																)
															);
															?>
														<?php elseif ( in_array( $uappt_row['status'], array( UAPPT_Shift_Request::STATUS_APPROVED, UAPPT_Shift_Request::STATUS_REJECTED ), true ) ) : ?>
															<p class="description">
																<?php
																printf(
																	/* translators: %s: 審核時間 */
																	esc_html__( '審核於 %s', 'ultimate-appointments' ),
																	esc_html( $uappt_row['reviewed_at'] )
																);
																?>
																<?php if ( trim( (string) $uappt_row['review_note'] ) ) : ?>
																	<br /><?php echo esc_html( $uappt_row['review_note'] ); ?>
																<?php endif; ?>
															</p>
														<?php else : ?>
															<?php // 兩個分支都不符合（例如已撤回）時，桌面版只是留一格空白，
																// 手機版的直式版面卻會留下一個沒有內容的欄位標題。補一個破折號
																// 讓「這一格確實沒有東西」是明講的，跟預約列表的操作欄一致。 ?>
															—
														<?php endif; ?>
													</td>
												</tr>
											<?php endforeach; ?>
										</tbody>
									</table>
								</details>
							</td>
						</tr>
					<?php endif; ?>
				<?php endforeach; ?>
			</tbody>
		</table>

		<?php if ( $total_pages > 1 ) : ?>
			<div class="tablenav">
				<div class="tablenav-pages">
					<?php
					echo wp_kses_post(
						paginate_links(
							array(
								'base'      => add_query_arg( 'paged', '%#%' ),
								'format'    => '',
								'current'   => $paged,
								'total'     => $total_pages,
								'prev_text' => __( '&laquo; 上一頁', 'ultimate-appointments' ),
								'next_text' => __( '下一頁 &raquo;', 'ultimate-appointments' ),
							)
						)
					);
					?>
				</div>
			</div>
		<?php endif; ?>
	<?php endif; ?>
