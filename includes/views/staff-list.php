<?php
/**
 * View：人力資源列表頁。
 *
 * 傳入變數：$staff_list（全部人員）。
 *
 * @package Ultimate_Appointments
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$new_url = add_query_arg(
	array(
		'page'    => UAPPT_Admin::PAGE_SLUG,
		'section' => 'staff',
		'action' => 'new',
	),
	admin_url( 'admin.php' )
);
?>

	<p class="uappt-page-desc">
		<?php esc_html_e( '每一位人員都是可以獨立排班、請假的個體。在商品編輯頁的「預約設定」分頁勾選這位人員能提供哪些服務，客人下單時就可以指定這位人員，或選擇「不指定」讓系統自動安排一位當天已排班較少、且有空的人員。', 'ultimate-appointments' ); ?>
	</p>

	<table class="widefat striped uappt-table">
		<thead>
			<tr>
				<th><?php esc_html_e( '姓名', 'ultimate-appointments' ); ?></th>
				<?php
				// ⚠️ **「已排到」緊接在姓名後面，不是排在最後。** 這張表原本有
				// 24 小時／顆粒／加價，卻沒有「這個人有沒有班」——而那是這一頁
				// 唯一一個「不看就會流失生意」的欄位（班表排完了，前台只會安靜地
				// 沒有時段，不會有任何錯誤訊息）。
				?>
				<th><?php esc_html_e( '班表排到', 'ultimate-appointments' ); ?></th>
				<th><?php esc_html_e( '排班方式', 'ultimate-appointments' ); ?></th>
				<th><?php esc_html_e( '同時可服務人數', 'ultimate-appointments' ); ?></th>
				<th><?php esc_html_e( '時間格顆粒', 'ultimate-appointments' ); ?></th>
				<th><?php esc_html_e( '指定加價', 'ultimate-appointments' ); ?></th>
				<th><?php esc_html_e( '狀態', 'ultimate-appointments' ); ?></th>
				<th><?php esc_html_e( '操作', 'ultimate-appointments' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php if ( empty( $staff_list ) ) : ?>
				<tr>
					<td colspan="8">
						<?php esc_html_e( '尚未建立任何人員。', 'ultimate-appointments' ); ?>
						<a href="<?php echo esc_url( $new_url ); ?>"><?php esc_html_e( '立即新增一位', 'ultimate-appointments' ); ?></a>
					</td>
				</tr>
			<?php endif; ?>
			<?php foreach ( $staff_list as $staff ) : ?>
				<?php
				$edit_url = add_query_arg(
					array(
						'page'    => UAPPT_Admin::PAGE_SLUG,
						'section' => 'staff',
						'action'   => 'edit',
						'staff_id' => $staff['id'],
					),
					admin_url( 'admin.php' )
				);
				?>
				<tr>
					<td data-label="<?php esc_attr_e( '姓名', 'ultimate-appointments' ); ?>">
						<strong><a href="<?php echo esc_url( $edit_url ); ?>"><?php echo esc_html( $staff['name'] ); ?></a></strong>
					</td>
					<td data-label="<?php esc_attr_e( '班表排到', 'ultimate-appointments' ); ?>">
						<?php
						// 停用的人員不算缺口：他們本來就不會被排進任何預約，報出來
						// 只會變成一排永遠消不掉的紅字。
						if ( 'active' !== $staff['status'] ) {
							echo '—';
						} else {
							$uappt_cov = UAPPT_Staff::coverage_for( $staff );
							if ( null === $uappt_cov['last_covered'] ) {
								printf(
									'<span class="uappt-badge uappt-badge-error">%s</span>',
									esc_html__( '完全沒排', 'ultimate-appointments' )
								);
							} elseif ( $uappt_cov['short'] ) {
								printf(
									'<span class="uappt-badge uappt-badge-error">%1$s</span> <span class="description">%2$s</span>',
									esc_html( wp_date( 'n/j', strtotime( $uappt_cov['last_covered'] . ' 12:00:00' ) ) ),
									esc_html(
										sprintf(
											/* translators: %d: 還有幾天沒排 */
											__( '還有 %d 天沒排', 'ultimate-appointments' ),
											(int) $uappt_cov['uncovered']
										)
									)
								);
							} else {
								echo esc_html( wp_date( 'n/j', strtotime( $uappt_cov['last_covered'] . ' 12:00:00' ) ) );
							}
						}
						?>
					</td>
					<?php
					// v2.93.0 取代原本的「24 小時營業 是／否」：同一個問題（這個人怎麼
					// 排班）的完整答案，而且彈性班的「班表排到」不標紅，要看這一欄才
					// 知道為什麼。
					?>
					<td data-label="<?php esc_attr_e( '排班方式', 'ultimate-appointments' ); ?>"><?php echo esc_html( UAPPT_Staff::schedule_kind_label( $staff ) ); ?></td>
					<td data-label="<?php esc_attr_e( '同時可服務人數', 'ultimate-appointments' ); ?>"><?php echo esc_html( (int) $staff['capacity'] ); ?></td>
					<td data-label="<?php esc_attr_e( '時間格顆粒', 'ultimate-appointments' ); ?>">
						<?php
						if ( ! empty( $staff['slot_interval'] ) ) {
							printf(
								/* translators: %d: 分鐘數 */
								esc_html__( '%d 分鐘', 'ultimate-appointments' ),
								(int) $staff['slot_interval']
							);
						} else {
							printf(
								/* translators: %d: 全站預設分鐘數 */
								esc_html__( '沿用預設（%d 分鐘）', 'ultimate-appointments' ),
								(int) get_option( 'uappt_slot_interval_minutes', 15 )
							);
						}
						?>
					</td>
					<td data-label="<?php esc_attr_e( '指定加價', 'ultimate-appointments' ); ?>">
						<?php
						// ⚠️ 資料庫的 DECIMAL 回來是字串 "0.00"，! empty() 會把它當成有值，
						// 沒加價的人就印出「NT$0」而不是「—」。要比數值。
						if ( (float) $staff['price_adjustment'] > 0 ) {
							echo wp_kses_post( wc_price( (float) $staff['price_adjustment'] ) );
						} else {
							echo '—';
						}
						?>
					</td>
					<td data-label="<?php esc_attr_e( '狀態', 'ultimate-appointments' ); ?>">
						<span class="uappt-badge uappt-badge-<?php echo 'active' === $staff['status'] ? 'success' : 'muted'; ?>">
							<?php echo 'active' === $staff['status'] ? esc_html__( '啟用中', 'ultimate-appointments' ) : esc_html__( '已停用', 'ultimate-appointments' ); ?>
						</span>
					</td>
					<td class="uappt-cell-block" data-label="<?php esc_attr_e( '操作', 'ultimate-appointments' ); ?>">
						<a href="<?php echo esc_url( $edit_url ); ?>"><?php esc_html_e( '編輯', 'ultimate-appointments' ); ?></a>
						&nbsp;|&nbsp;
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
							<input type="hidden" name="action" value="uappt_toggle_staff_status" />
							<input type="hidden" name="staff_id" value="<?php echo esc_attr( $staff['id'] ); ?>" />
							<?php wp_nonce_field( 'uappt_toggle_staff_status' ); ?>
							<?php if ( 'active' === $staff['status'] ) : ?>
								<button type="submit" class="button-link"><?php esc_html_e( '停用', 'ultimate-appointments' ); ?></button>
							<?php else : ?>
								<button type="submit" class="button-link"><?php esc_html_e( '啟用', 'ultimate-appointments' ); ?></button>
							<?php endif; ?>
						</form>
						&nbsp;|&nbsp;
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline" onsubmit="return confirm('<?php echo esc_js( __( '確定要刪除這位人員嗎？此動作無法復原，且會一併刪除其請假紀錄。離職或請假通常建議改用左邊的「停用」，既有的班表與紀錄可以保留。', 'ultimate-appointments' ) ); ?>');">
							<input type="hidden" name="action" value="uappt_delete_staff" />
							<input type="hidden" name="staff_id" value="<?php echo esc_attr( $staff['id'] ); ?>" />
							<?php wp_nonce_field( 'uappt_delete_staff' ); ?>
							<button type="submit" class="button-link-delete"><?php esc_html_e( '刪除', 'ultimate-appointments' ); ?></button>
						</form>
					</td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
