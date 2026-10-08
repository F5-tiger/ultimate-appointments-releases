<?php
/**
 * View：預約列表頁。
 *
 * 傳入變數：$staff_list、$filters、$result（['items'=>[], 'total'=>int]）。
 *
 * @package Ultimate_Appointments
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$per_page    = $filters['per_page'];
$total_pages = max( 1, (int) ceil( $result['total'] / $per_page ) );

$staff_names = array();
foreach ( $staff_list as $staff ) {
	$staff_names[ (int) $staff['id'] ] = $staff['name'];
}

$view_tabs = array(
	'upcoming' => __( '即將到來', 'ultimate-appointments' ),
	'all'      => __( '全部', 'ultimate-appointments' ),
	'past'     => __( '已過期', 'ultimate-appointments' ),
);

$view_base_args = array(
	'page'    => UAPPT_Admin::PAGE_SLUG,
	'section' => 'bookings',
);
if ( ! empty( $filters['staff_id'] ) ) {
	$view_base_args['staff_id'] = $filters['staff_id'];
}
if ( ! empty( $filters['status'] ) ) {
	$view_base_args['status'] = $filters['status'];
}
if ( ! empty( $filters['assignment_state'] ) ) {
	$view_base_args['assignment_state'] = $filters['assignment_state'];
}

/*
 * 狀態快捷連結。預設的「即將到來」檢視會把 date_from 設成今天，而「未到」
 * 的預約必定在過去——標記完之後那一列就從畫面上消失了，很容易被誤會成狀態
 * 沒有寫進去。這排帶筆數的連結就是那條線索：直接說出未到有幾筆，點下去跳到
 * 「全部」檢視並套用該狀態。
 *
 * 筆數是在**忽略目前狀態篩選**的前提下算的（見 UAPPT_Booking::count_by_status()），
 * 所以不管現在停在哪一個狀態，這排數字都不會變動。
 */
$status_links = array(
	UAPPT_Booking::STATUS_CONFIRMED => UAPPT_Admin::status_label( UAPPT_Booking::STATUS_CONFIRMED ),
	UAPPT_Booking::STATUS_NO_SHOW   => UAPPT_Admin::status_label( UAPPT_Booking::STATUS_NO_SHOW ),
	UAPPT_Booking::STATUS_COMPLETED => UAPPT_Admin::status_label( UAPPT_Booking::STATUS_COMPLETED ),
	UAPPT_Booking::STATUS_CANCELLED => UAPPT_Admin::status_label( UAPPT_Booking::STATUS_CANCELLED ),
);

// 快捷連結一律導向 view=all，並帶上跟筆數完全相同的其他條件（人員、搜尋、
// 使用者自己填的日期），點進去看到的清單才會剛好是那個數字。
$status_link_args = array(
	'page'    => UAPPT_Admin::PAGE_SLUG,
	'section' => 'bookings',
	'view' => 'all',
);
if ( ! empty( $filters['staff_id'] ) ) {
	$status_link_args['staff_id'] = $filters['staff_id'];
}
if ( ! empty( $filters['search'] ) ) {
	$status_link_args['s'] = $filters['search'];
}
if ( '' !== $user_date_from ) {
	$status_link_args['date_from'] = $user_date_from;
}
if ( '' !== $user_date_to ) {
	$status_link_args['date_to'] = $user_date_to;
}
?>

	<?php
	// 期間（即將到來／已過期／全部）v2.38.0 從 subsubsub 改成分段按鈕。
	// 日曆併進來之後，「清單｜日｜月」的檢視切換已經佔掉 subsubsub 那一層，
	// 期間再一排 subsubsub 就是第三層長得跟第二層一樣。分段按鈕是報表頁
	// 早就在用的第三層元件（.uappt-period-presets），形狀跟前兩層都不同。
	?>
	<p class="uappt-period-presets">
		<?php foreach ( $view_tabs as $uappt_tab_key => $uappt_tab_label ) : ?>
			<a
				href="<?php echo esc_url( add_query_arg( array_merge( $view_base_args, array( 'view' => $uappt_tab_key ) ), admin_url( 'admin.php' ) ) ); ?>"
				class="button<?php echo $view === $uappt_tab_key ? ' button-primary' : ''; ?>"
			><?php echo esc_html( $uappt_tab_label ); ?></a>
		<?php endforeach; ?>
	</p>

	<p class="uappt-status-links">
		<?php foreach ( $status_links as $status_key => $status_text ) : ?>
			<?php
			$status_count = isset( $status_counts[ $status_key ] ) ? (int) $status_counts[ $status_key ] : 0;
			if ( ! $status_count ) {
				continue; // 一筆都沒有的狀態不佔版面。
			}
			$status_url = add_query_arg(
				array_merge( $status_link_args, array( 'status' => $status_key ) ),
				admin_url( 'admin.php' )
			);
			?>
			<a
				class="uappt-status-link <?php echo ( $filters['status'] === $status_key ) ? 'is-current' : ''; ?>"
				href="<?php echo esc_url( $status_url ); ?>"
			>
				<span class="uappt-badge uappt-badge-<?php echo esc_attr( UAPPT_Admin::status_class( $status_key ) ); ?>">
					<?php echo esc_html( $status_text ); ?>
				</span>
				<?php echo esc_html( $status_count ); ?>
			</a>
		<?php endforeach; ?>
		<?php if ( ! empty( $status_counts['awaiting_payment'] ) ) : ?>
			<?php
			$awaiting_url = add_query_arg(
				array_merge( $status_link_args, array( 'status' => UAPPT_Booking::STATUS_HELD ) ),
				admin_url( 'admin.php' )
			);
			?>
			<a class="uappt-status-link" href="<?php echo esc_url( $awaiting_url ); ?>">
				<span class="uappt-badge uappt-badge-warning"><?php esc_html_e( '待付款', 'ultimate-appointments' ); ?></span>
				<?php echo esc_html( (int) $status_counts['awaiting_payment'] ); ?>
			</a>
		<?php endif; ?>
	</p>

	<?php
	// 手機版這塊預設是收起來的，所以要先算「目前有幾項條件生效」——收起來
	// 之後那個數字是唯一的線索，沒有它使用者分不出「看到的是全部」還是
	// 「被上次篩過的一小部分」。
	//
	// ⚠️ 日期要用 $user_date_from／$user_date_to（使用者自己填的），不是
	// $filters['date_from']。檢視頁籤會自動補上日期窗（「即將到來」＝今天以後），
	// 那不是使用者篩的條件——用後者的話一進頁面就會顯示「1 項」。
	// 起訖是同一個概念，兩格都填也只算一項。
	$uappt_active = 0;
	if ( $filters['staff_id'] ) {
		$uappt_active++;
	}
	if ( '' !== $filters['status'] ) {
		$uappt_active++;
	}
	if ( '' !== $user_date_from || '' !== $user_date_to ) {
		$uappt_active++;
	}
	if ( '' !== (string) $filters['search'] ) {
		$uappt_active++;
	}
	if ( '' !== $filters['assignment_state'] ) {
		$uappt_active++;
	}

	UAPPT_Admin::filters_open(
		array(
			'section' => 'bookings',
			'hidden'  => array( 'view' => $view ),
			'active'  => $uappt_active,
		)
	);
	?>

		<?php UAPPT_Admin::field_open( __( '人員', 'ultimate-appointments' ), 'uappt-filter-staff' ); ?>
			<select id="uappt-filter-staff" name="staff_id">
				<option value=""><?php esc_html_e( '所有人員', 'ultimate-appointments' ); ?></option>
				<?php foreach ( $staff_list as $staff ) : ?>
					<option value="<?php echo esc_attr( $staff['id'] ); ?>" <?php selected( (int) $filters['staff_id'], (int) $staff['id'] ); ?>>
						<?php echo esc_html( $staff['name'] ); ?>
					</option>
				<?php endforeach; ?>
			</select>
		<?php UAPPT_Admin::field_close(); ?>

		<?php UAPPT_Admin::field_open( __( '狀態', 'ultimate-appointments' ), 'uappt-filter-status' ); ?>
			<select id="uappt-filter-status" name="status">
				<option value=""><?php esc_html_e( '所有狀態', 'ultimate-appointments' ); ?></option>
				<?php
				$statuses = array(
					UAPPT_Booking::STATUS_HELD,
					UAPPT_Booking::STATUS_CONFIRMED,
					UAPPT_Booking::STATUS_CANCELLED,
					UAPPT_Booking::STATUS_EXPIRED,
					UAPPT_Booking::STATUS_COMPLETED,
					UAPPT_Booking::STATUS_NO_SHOW,
				);
				foreach ( $statuses as $status ) :
					?>
					<option value="<?php echo esc_attr( $status ); ?>" <?php selected( $filters['status'], $status ); ?>>
						<?php echo esc_html( UAPPT_Admin::status_label( $status ) ); ?>
					</option>
				<?php endforeach; ?>
			</select>
		<?php UAPPT_Admin::field_close(); ?>

		<?php
		// 起訖是一格不是兩格：它們是同一個概念（服務日期落在哪段期間），
		// 拆成兩個獨立欄位時，版面換行會把它們拆到不同排。
		UAPPT_Admin::field_open( __( '服務日期', 'ultimate-appointments' ), '', 'uappt-field-range' );
		?>
			<span class="uappt-field-row">
				<input type="date" name="date_from" value="<?php echo esc_attr( $filters['date_from'] ); ?>" aria-label="<?php esc_attr_e( '服務日期（起）', 'ultimate-appointments' ); ?>" />
				<span class="uappt-field-sep" aria-hidden="true">～</span>
				<input type="date" name="date_to" value="<?php echo esc_attr( $filters['date_to'] ); ?>" aria-label="<?php esc_attr_e( '服務日期（迄）', 'ultimate-appointments' ); ?>" />
			</span>
		<?php UAPPT_Admin::field_close(); ?>

		<?php UAPPT_Admin::field_open( __( '關鍵字', 'ultimate-appointments' ), 'uappt-filter-search', 'uappt-field-wide' ); ?>
			<input type="search" id="uappt-filter-search" name="s" value="<?php echo esc_attr( isset( $filters['search'] ) ? $filters['search'] : '' ); ?>" placeholder="<?php esc_attr_e( '搜尋姓名、電話或訂單編號', 'ultimate-appointments' ); ?>" />
		<?php UAPPT_Admin::field_close(); ?>

		<?php UAPPT_Admin::field_open( '', '', 'uappt-field-check' ); ?>
			<label>
				<input type="checkbox" name="assignment_state" value="<?php echo esc_attr( UAPPT_Booking::ASSIGNMENT_PENDING ); ?>" <?php checked( $filters['assignment_state'], UAPPT_Booking::ASSIGNMENT_PENDING ); ?> />
				<?php esc_html_e( '只顯示待分派', 'ultimate-appointments' ); ?>
			</label>
		<?php UAPPT_Admin::field_close(); ?>

		<?php UAPPT_Admin::field_open( '', '', 'uappt-field-actions' ); ?>
			<?php submit_button( __( '篩選', 'ultimate-appointments' ), 'secondary', '', false ); ?>
			<a href="<?php echo esc_url( UAPPT_Admin::url( 'bookings' ) ); ?>" class="button-link">
				<?php esc_html_e( '清除篩選', 'ultimate-appointments' ); ?>
			</a>
		<?php UAPPT_Admin::field_close(); ?>

	<?php UAPPT_Admin::filters_close(); ?>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="uappt-bulk-form">
		<input type="hidden" name="action" value="uappt_bulk_booking_action" />
		<?php wp_nonce_field( 'uappt_bulk_booking_action' ); ?>

		<div class="uappt-bulk-toolbar">
			<select name="bulk_action">
				<option value=""><?php esc_html_e( '批次動作', 'ultimate-appointments' ); ?></option>
				<option value="cancel"><?php esc_html_e( '取消預約', 'ultimate-appointments' ); ?></option>
				<option value="complete"><?php esc_html_e( '標記完成', 'ultimate-appointments' ); ?></option>
				<option value="no_show"><?php esc_html_e( '標記未到', 'ultimate-appointments' ); ?></option>
			</select>
			<?php submit_button( __( '套用', 'ultimate-appointments' ), 'secondary', '', false, array( 'id' => 'uappt-bulk-apply' ) ); ?>
			<span class="description">
				<?php esc_html_e( '只勾選「暫留中／待付款」或「已確認」的預約才會有效果，其餘狀態會自動略過；「標記未到」只對服務時間已經過去的已確認預約有效果。', 'ultimate-appointments' ); ?>
			</span>
		</div>

		<table class="widefat striped uappt-table">
			<thead>
				<tr>
					<td class="check-column"><input type="checkbox" id="uappt-bulk-select-all" /></td>
					<th><?php esc_html_e( '#', 'ultimate-appointments' ); ?></th>
					<th><?php esc_html_e( '服務項目', 'ultimate-appointments' ); ?></th>
					<th><?php esc_html_e( '時段', 'ultimate-appointments' ); ?></th>
					<th><?php esc_html_e( '服務人員', 'ultimate-appointments' ); ?></th>
					<th><?php esc_html_e( '客人', 'ultimate-appointments' ); ?></th>
					<th><?php esc_html_e( '狀態', 'ultimate-appointments' ); ?></th>
					<th><?php esc_html_e( '訂單', 'ultimate-appointments' ); ?></th>
					<th><?php esc_html_e( '操作', 'ultimate-appointments' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $result['items'] ) ) : ?>
					<tr><td colspan="9"><?php esc_html_e( '沒有符合條件的預約。', 'ultimate-appointments' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $result['items'] as $booking ) : ?>
				<?php
				$start_dt = date_create( $booking['service_start'], wp_timezone() );
				$end_dt   = date_create( $booking['service_end'], wp_timezone() );
				$slot_label = $start_dt && $end_dt
					? wp_date( 'Y-m-d (D) H:i', $start_dt->getTimestamp() ) . '–' . wp_date( 'H:i', $end_dt->getTimestamp() )
					: '';

				$customer_label = trim( (string) $booking['customer_name'] );
				if ( ! $customer_label && $booking['customer_id'] ) {
					$user           = get_user_by( 'id', $booking['customer_id'] );
					$customer_label = $user ? $user->display_name : '';
				}
				if ( $booking['customer_phone'] ) {
					$customer_label .= ( $customer_label ? ' / ' : '' ) . $booking['customer_phone'];
				}
				?>
				<?php $bulk_selectable = in_array( $booking['status'], array( UAPPT_Booking::STATUS_HELD, UAPPT_Booking::STATUS_CONFIRMED ), true ); ?>
				<tr>
					<td class="check-column">
						<?php if ( $bulk_selectable ) : ?>
							<input type="checkbox" name="booking_ids[]" value="<?php echo esc_attr( $booking['id'] ); ?>" class="uappt-bulk-checkbox" />
						<?php endif; ?>
					</td>
					<td data-label="<?php esc_attr_e( '#', 'ultimate-appointments' ); ?>">#<?php echo esc_html( $booking['id'] ); ?></td>
					<td data-label="<?php esc_attr_e( '服務項目', 'ultimate-appointments' ); ?>">
						<?php
						echo esc_html(
							UAPPT_Booking::get_booking_display_name( $booking )
						);
						?>
					</td>
					<td data-label="<?php esc_attr_e( '時段', 'ultimate-appointments' ); ?>"><?php echo esc_html( $slot_label ); ?></td>
					<td data-label="<?php esc_attr_e( '服務人員', 'ultimate-appointments' ); ?>">
						<?php
						$staff_id = ! empty( $booking['staff_id'] ) ? (int) $booking['staff_id'] : 0;
						echo esc_html( $staff_id && isset( $staff_names[ $staff_id ] ) ? $staff_names[ $staff_id ] : '—' );
						if ( UAPPT_Booking::ASSIGNMENT_PENDING === $booking['assignment_state'] ) {
							echo ' <span class="uappt-badge uappt-badge-warning">' . esc_html__( '待分派', 'ultimate-appointments' ) . '</span>';
						}
						?>
					</td>
					<td data-label="<?php esc_attr_e( '客人', 'ultimate-appointments' ); ?>"><?php echo esc_html( $customer_label ?: '—' ); ?></td>
					<td data-label="<?php esc_attr_e( '狀態', 'ultimate-appointments' ); ?>">
						<span class="uappt-badge uappt-badge-<?php echo esc_attr( UAPPT_Admin::booking_status_class( $booking ) ); ?>">
							<?php echo esc_html( UAPPT_Admin::booking_status_label( $booking ) ); ?>
						</span>
					</td>
					<td data-label="<?php esc_attr_e( '訂單', 'ultimate-appointments' ); ?>">
						<?php $order_edit_url = $booking['order_id'] ? UAPPT_Admin::get_order_edit_url( $booking['order_id'] ) : ''; ?>
						<?php if ( $order_edit_url ) : ?>
							<a href="<?php echo esc_url( $order_edit_url ); ?>">
								#<?php echo esc_html( $booking['order_id'] ); ?>
							</a>
						<?php else : ?>
							—
						<?php endif; ?>
					</td>
					<td class="uappt-cell-block" data-label="<?php esc_attr_e( '操作', 'ultimate-appointments' ); ?>">
						<?php if ( in_array( $booking['status'], array( UAPPT_Booking::STATUS_HELD, UAPPT_Booking::STATUS_CONFIRMED ), true ) ) : ?>
							<a href="<?php echo esc_url( add_query_arg( array( 'page' => UAPPT_Admin::PAGE_SLUG, 'section' => 'bookings', 'action' => 'edit', 'booking_id' => $booking['id'] ), admin_url( 'admin.php' ) ) ); ?>">
								<?php esc_html_e( '編輯', 'ultimate-appointments' ); ?>
							</a>
							<?php if ( UAPPT_Booking::ASSIGNMENT_PENDING === $booking['assignment_state'] ) : ?>
								&nbsp;|&nbsp;
								<a href="<?php echo esc_url( add_query_arg( array( 'page' => UAPPT_Admin::PAGE_SLUG, 'section' => 'bookings', 'action' => 'edit', 'booking_id' => $booking['id'] ), admin_url( 'admin.php' ) ) . '#uappt-reassign-staff' ); ?>">
									<?php esc_html_e( '分派', 'ultimate-appointments' ); ?>
								</a>
							<?php endif; ?>
							&nbsp;|&nbsp;
							<?php
							$cancel_url = wp_nonce_url(
								add_query_arg(
									array(
										'action'     => 'uappt_cancel_booking',
										'booking_id' => $booking['id'],
									),
									admin_url( 'admin-post.php' )
								),
								'uappt_cancel_booking'
							);
							?>
							<a href="<?php echo esc_url( $cancel_url ); ?>" class="button-link-delete" onclick="return confirm('<?php echo esc_js( __( '確定要取消這筆預約嗎？時段將會釋放。', 'ultimate-appointments' ) ); ?>');">
								<?php esc_html_e( '取消', 'ultimate-appointments' ); ?>
							</a>
						<?php elseif ( in_array( $booking['status'], array( UAPPT_Booking::STATUS_COMPLETED, UAPPT_Booking::STATUS_NO_SHOW ), true ) ) : ?>
							<?php
							// 標錯了要有路可以回頭。用帶 nonce 的連結而不是表單，理由跟
							// 上面的「取消」一樣：整張表格被批次操作的 <form> 包住，
							// HTML 不允許表單巢狀（見 handle_cancel_booking() 的說明）。
							$revert_url = wp_nonce_url(
								add_query_arg(
									array(
										'action'      => 'uappt_revert_booking_status',
										'booking_id'  => $booking['id'],
										'uappt_return' => 'list',
									),
									admin_url( 'admin-post.php' )
								),
								'uappt_edit_booking_' . $booking['id']
							);
							?>
							<a href="<?php echo esc_url( $revert_url ); ?>" onclick="return confirm('<?php echo esc_js( __( '確定要還原成「已確認」嗎？時段本來就沒有釋放過，還原不會影響其他預約。', 'ultimate-appointments' ) ); ?>');">
								<?php esc_html_e( '還原為已確認', 'ultimate-appointments' ); ?>
							</a>
						<?php else : ?>
							—
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	</form>

	<?php if ( $total_pages > 1 ) : ?>
		<div class="tablenav">
			<div class="tablenav-pages">
				<?php
				echo wp_kses_post(
					paginate_links(
						array(
							'base'      => add_query_arg( 'paged', '%#%' ),
							'format'    => '',
							'current'   => max( 1, $filters['paged'] ),
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

	<script>
	( function () {
		var selectAll = document.getElementById( 'uappt-bulk-select-all' );
		var form = document.getElementById( 'uappt-bulk-form' );
		if ( ! selectAll || ! form ) {
			return;
		}

		selectAll.addEventListener( 'change', function () {
			var boxes = form.querySelectorAll( '.uappt-bulk-checkbox' );
			for ( var i = 0; i < boxes.length; i++ ) {
				boxes[ i ].checked = selectAll.checked;
			}
		} );

		form.addEventListener( 'submit', function ( event ) {
			var action = form.querySelector( '[name="bulk_action"]' ).value;
			var checked = form.querySelectorAll( '.uappt-bulk-checkbox:checked' );

			if ( ! action || 0 === checked.length ) {
				event.preventDefault();
				return;
			}

			var labels = {
				cancel: '<?php echo esc_js( __( '確定要取消勾選的這些預約嗎？時段將會釋放。', 'ultimate-appointments' ) ); ?>',
				complete: '<?php echo esc_js( __( '確定要把勾選的這些預約標記為已完成嗎？', 'ultimate-appointments' ) ); ?>',
				no_show: '<?php echo esc_js( __( '確定要把勾選的這些預約標記為未到嗎？', 'ultimate-appointments' ) ); ?>'
			};
			var label = labels[ action ] || '';

			if ( ! window.confirm( checked.length + ' ' + label ) ) {
				event.preventDefault();
			}
		} );
	} )();
	</script>
