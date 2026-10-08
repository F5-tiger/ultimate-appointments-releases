<?php
/**
 * View：預約編輯頁（含改期）。
 *
 * 傳入變數：$booking（該筆預約資料）。
 *
 * @package Ultimate_Appointments
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$horizon_days = max( 1, (int) get_option( 'uappt_booking_horizon_days', 30 ) );
$min_date     = current_time( 'Y-m-d' );
$max_date     = uappt_local_date( $min_date, "+{$horizon_days} days" );

$start_dt       = date_create( $booking['service_start'], wp_timezone() );
$end_dt         = date_create( $booking['service_end'], wp_timezone() );
$current_label  = ( $start_dt && $end_dt )
	? wp_date( 'Y-m-d (D) H:i', $start_dt->getTimestamp() ) . '–' . wp_date( 'H:i', $end_dt->getTimestamp() )
	: '';

$can_edit    = in_array( $booking['status'], array( UAPPT_Booking::STATUS_HELD, UAPPT_Booking::STATUS_CONFIRMED ), true );
$nonce_action = 'uappt_edit_booking_' . $booking['id'];

$staff_row = ! empty( $booking['staff_id'] ) ? UAPPT_Staff::get( (int) $booking['staff_id'] ) : null;

$is_pending = isset( $booking['assignment_state'] ) && UAPPT_Booking::ASSIGNMENT_PENDING === $booking['assignment_state'];

// 金額的可編輯範圍**刻意比 $can_edit（改期）寬**：已完成／未到的預約還是會
// 需要更正金額（客人事後才談的折扣、當初打錯），而那兩個狀態都已經不能改期。
// 已取消／已逾時釋放的就不給改——那些預約的時段早就還給別人了，金額也已經
// 不在報表的統計範圍內（報表只算 confirmed／completed／no_show），改了沒有意義。
$amount_editable = in_array(
	$booking['status'],
	array(
		UAPPT_Booking::STATUS_HELD,
		UAPPT_Booking::STATUS_CONFIRMED,
		UAPPT_Booking::STATUS_COMPLETED,
		UAPPT_Booking::STATUS_NO_SHOW,
	),
	true
);

$has_order_item = ! empty( $booking['order_id'] ) && ! empty( $booking['order_item_id'] );

// 候選人員清單：跟這項服務目前的候選名單一致（get_booking_settings()），只列在職的。
// 目前指派的人員若不在這份名單裡（例如後來被移出候選名單、或整個停職），還是要讓他
// 出現在下拉選單並附註說明——不然管理者連「現在是誰」都選不到，等於被迫立刻換人。
$candidate_staff = UAPPT_Admin::reassign_candidates( $booking );
$current_in_list = false;

foreach ( $candidate_staff as $s ) {
	if ( (int) $s['id'] === (int) $booking['staff_id'] ) {
		$current_in_list = true;
		break;
	}
}

if ( $staff_row && ! $current_in_list ) {
	array_unshift( $candidate_staff, $staff_row );
}
?>

	<?php // 這一頁原本是「裸 h2 ＋ 裸 form-table」一路平鋪，是全站唯一沒有跟上 ?>
	<?php // 面板化的頁面：每一段各自成為一張卡片，跟設定頁／人員編輯頁一致。 ?>
	<div class="uappt-panel">
		<?php UAPPT_Admin::panel_head( 'clipboard-list', __( '預約資訊', 'ultimate-appointments' ) ); ?>
	<table class="form-table">
		<tr>
			<th><?php esc_html_e( '服務項目', 'ultimate-appointments' ); ?></th>
			<td>
				<?php
				echo esc_html(
					UAPPT_Booking::get_booking_display_name( $booking )
				);
				?>
			</td>
		</tr>
		<tr>
			<th><?php esc_html_e( '目前時段', 'ultimate-appointments' ); ?></th>
			<td><?php echo esc_html( $current_label ); ?></td>
		</tr>
		<tr>
			<th><?php esc_html_e( '服務人員', 'ultimate-appointments' ); ?></th>
			<td>
				<?php echo esc_html( $staff_row ? $staff_row['name'] : __( '—（尚未分配）', 'ultimate-appointments' ) ); ?>
				<?php if ( $staff_row ) : ?>
					<span class="uappt-badge uappt-badge-<?php echo ! empty( $booking['staff_requested'] ) ? 'info' : 'muted'; ?>">
						<?php echo ! empty( $booking['staff_requested'] ) ? esc_html__( '客人指定', 'ultimate-appointments' ) : esc_html__( '系統安排', 'ultimate-appointments' ); ?>
					</span>
				<?php endif; ?>
				<?php if ( $is_pending ) : ?>
					<span class="uappt-badge uappt-badge-warning"><?php esc_html_e( '待分派', 'ultimate-appointments' ); ?></span>
				<?php endif; ?>
				<?php // DECIMAL 欄位從資料庫回來是字串 "0.00"，empty() 對它是 false，沒有加價也會印出 (+NT$0)。 ?>
				<?php if ( (float) $booking['staff_price_adjustment'] > 0 ) : ?>
					<?php echo wp_kses_post( ' (+' . wc_price( (float) $booking['staff_price_adjustment'] ) . ')' ); ?>
				<?php endif; ?>
				<?php if ( $is_pending ) : ?>
					<p class="description"><?php esc_html_e( '這項服務開啟了「由管理者手動安排」，客人沒有指定人員。系統已經暫時把時段鎖在上面這位人員身上（不會超賣），但人選還沒定案，請在下方確認或改派。', 'ultimate-appointments' ); ?></p>
				<?php elseif ( $staff_row && empty( $booking['staff_requested'] ) ) : ?>
					<p class="description"><?php esc_html_e( '客人當初選擇「不指定」，系統自動安排了這位人員。改期時如果這位人員沒空，可能會換成其他候選人員。', 'ultimate-appointments' ); ?></p>
				<?php elseif ( $staff_row ) : ?>
					<p class="description"><?php esc_html_e( '客人指定了這位人員。改期時只會嘗試同一位人員，若沒空會直接提示失敗，不會偷偷換成別人。', 'ultimate-appointments' ); ?></p>
				<?php endif; ?>
			</td>
		</tr>
		<tr>
			<th><?php esc_html_e( '狀態', 'ultimate-appointments' ); ?></th>
			<td>
				<span class="uappt-badge uappt-badge-<?php echo esc_attr( UAPPT_Admin::booking_status_class( $booking ) ); ?>">
					<?php echo esc_html( UAPPT_Admin::booking_status_label( $booking ) ); ?>
				</span>
			</td>
		</tr>
		<tr>
			<th><?php esc_html_e( '訂單', 'ultimate-appointments' ); ?></th>
			<td>
				<?php $order_edit_url = $booking['order_id'] ? UAPPT_Admin::get_order_edit_url( $booking['order_id'] ) : ''; ?>
				<?php if ( $order_edit_url ) : ?>
					<a href="<?php echo esc_url( $order_edit_url ); ?>">
						#<?php echo esc_html( $booking['order_id'] ); ?>
					</a>
				<?php else : ?>
					—
				<?php endif; ?>
			</td>
		</tr>
		<tr>
			<th><?php esc_html_e( '預約提醒', 'ultimate-appointments' ); ?></th>
			<td>
				<div class="uappt-field-inline">
					<span>
						<?php
						$reminder_at = ! empty( $booking['reminder_sent_at'] ) ? date_create( $booking['reminder_sent_at'], wp_timezone() ) : null;
						if ( $reminder_at ) {
							printf(
								/* translators: %s: 發送時間 */
								esc_html__( '已於 %s 發送', 'ultimate-appointments' ),
								esc_html( wp_date( 'Y-m-d H:i', $reminder_at->getTimestamp() ) )
							);
						} else {
							esc_html_e( '尚未發送', 'ultimate-appointments' );
						}
						?>
					</span>

					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="uappt_resend_reminder" />
						<input type="hidden" name="booking_id" value="<?php echo esc_attr( $booking['id'] ); ?>" />
						<?php wp_nonce_field( $nonce_action ); ?>
						<button type="submit" class="button button-secondary"><?php esc_html_e( '立即發送提醒', 'ultimate-appointments' ); ?></button>
					</form>
				</div>

				<p class="description"><?php esc_html_e( '客人反映沒收到時可以手動補送。優先用 LINE，客人未綁定或推播失敗會自動改寄 Email。', 'ultimate-appointments' ); ?></p>
			</td>
		</tr>
		<tr>
			<th><?php esc_html_e( '加入行事曆', 'ultimate-appointments' ); ?></th>
			<td>
				<?php echo wp_kses_post( UAPPT_Calendar::render_links( $booking, 'buttons' ) ); ?>
				<p class="description"><?php esc_html_e( '這兩個連結與客人收到的相同，可以複製給客人。', 'ultimate-appointments' ); ?></p>
			</td>
		</tr>
	</table>
	</div>

	<?php if ( $can_edit && ! empty( $candidate_staff ) ) : ?>
		<?php // 表單留在最外層並保留 .uappt-form：頁面限寬 960px 是靠 ?>
		<?php // `.uappt-wrap:has(> .uappt-form)` 認出來的，面板包在裡面。 ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="uappt-form">
			<input type="hidden" name="action" value="uappt_reassign_staff" />
			<input type="hidden" name="booking_id" value="<?php echo esc_attr( $booking['id'] ); ?>" />
			<?php wp_nonce_field( $nonce_action ); ?>

			<div class="uappt-panel">
			<?php UAPPT_Admin::panel_head( 'users', __( '更換服務人員', 'ultimate-appointments' ) ); ?>
			<div class="uappt-panel-body">
				<p class="description">
					<?php
					if ( $is_pending ) {
						esc_html_e( '確認上面暫定的人選、或直接改派給別人。選同一位人員只是確認，不會動到時段；換成別人時系統會先確認新人員這個時段有空並鎖住，成功後才釋放原本人員的時段——跟改期一樣不會出現「舊的放掉了、新的沒搶到」的空窗，失敗也完全不影響現在的預約。', 'ultimate-appointments' );
					} else {
						esc_html_e( '適合用在人員臨時請假、或想把預約分派給別人的情況。系統會先確認新人員這個時段有空並鎖住，成功後才釋放原本人員的時段，失敗完全不影響現在的預約。', 'ultimate-appointments' );
					}
					?>
				</p>
			</div>

			<table class="form-table">
				<tr>
					<th><label for="uappt-reassign-staff"><?php esc_html_e( '服務人員', 'ultimate-appointments' ); ?></label></th>
					<td>
						<select id="uappt-reassign-staff" name="staff_id">
							<?php foreach ( $candidate_staff as $s ) : ?>
								<option value="<?php echo esc_attr( $s['id'] ); ?>" <?php selected( (int) $s['id'], (int) $booking['staff_id'] ); ?>>
									<?php
									echo esc_html( $s['name'] );
									if ( ! empty( $s['price_adjustment'] ) ) {
										// 跟 UAPPT_Ajax::get_staff_options() 給前台的格式一致：純數字，
										// 不額外套用貨幣格式化（那支給的也是原始 float）。
										echo ' (+' . esc_html( (float) $s['price_adjustment'] ) . ')';
									}
									if ( 'active' !== $s['status'] ) {
										esc_html_e( '（已離職，僅供顯示目前指派）', 'ultimate-appointments' );
									}
									?>
								</option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( '通知客人', 'ultimate-appointments' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="notify_customer" value="1" />
							<?php esc_html_e( '真的換成別人時發送通知（LINE 優先，沒綁定或失敗改寄 Email）。只是確認暫定人選不會發送，因為客人體驗上沒有變化。', 'ultimate-appointments' ); ?>
						</label>
					</td>
				</tr>
			</table>

			<?php submit_button( __( '更新服務人員', 'ultimate-appointments' ), 'secondary' ); ?>
			</div>
		</form>
	<?php endif; ?>

	<?php if ( $can_edit ) : ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="uappt-form">
			<input type="hidden" name="action" value="uappt_reschedule_booking" />
			<input type="hidden" name="booking_id" value="<?php echo esc_attr( $booking['id'] ); ?>" />
			<?php wp_nonce_field( $nonce_action ); ?>

			<?php // admin-booking.js 全靠 id 選取、沒有任何 DOM 走訪，這個標記放在 ?>
			<?php // 表單裡的哪個位置都可以，但 id 不能改。 ?>
			<div id="uappt-mb-fixed-target" data-product-id="<?php echo esc_attr( $booking['product_id'] ); ?>" data-plan-key="<?php echo esc_attr( isset( $booking['plan_key'] ) ? $booking['plan_key'] : '' ); ?>"></div>

			<div class="uappt-panel">
			<?php UAPPT_Admin::panel_head( 'calendar', __( '改期', 'ultimate-appointments' ) ); ?>
			<div class="uappt-panel-body">
				<p class="description">
					<?php esc_html_e( '選擇新的日期與時段後送出。系統會先確認新時段還有名額並鎖定，成功後才會釋放原本的時段；如果新時段剛好被別人搶走，原本的時段完全不受影響，可以重新選擇。', 'ultimate-appointments' ); ?>
				</p>
			</div>

			<table class="form-table">
				<tr>
					<th><label for="uappt-mb-date"><?php esc_html_e( '新日期', 'ultimate-appointments' ); ?></label></th>
					<td>
						<input type="date" id="uappt-mb-date" min="<?php echo esc_attr( $min_date ); ?>" max="<?php echo esc_attr( $max_date ); ?>" />
					</td>
				</tr>
				<tr>
					<th><label for="uappt-mb-slot"><?php esc_html_e( '新時段', 'ultimate-appointments' ); ?></label></th>
					<td>
						<select id="uappt-mb-slot">
							<option value=""><?php esc_html_e( '請先選擇新日期', 'ultimate-appointments' ); ?></option>
						</select>
						<input type="hidden" name="date_ymd" id="uappt-mb-date-input" value="" />
						<input type="hidden" name="time_hm" id="uappt-mb-time-hm" value="" />
					</td>
				</tr>
			</table>

			<?php submit_button( __( '確認改期', 'ultimate-appointments' ) ); ?>
			</div>
		</form>
	<?php else : ?>
		<div class="uappt-panel">
			<?php UAPPT_Admin::panel_head( 'calendar', __( '改期', 'ultimate-appointments' ) ); ?>
			<div class="uappt-panel-body">
				<p class="description"><?php esc_html_e( '此預約目前狀態已無法改期。', 'ultimate-appointments' ); ?></p>
			</div>
		</div>
	<?php endif; ?>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="uappt-form">
		<input type="hidden" name="action" value="uappt_update_booking_details" />
		<input type="hidden" name="booking_id" value="<?php echo esc_attr( $booking['id'] ); ?>" />
		<?php wp_nonce_field( $nonce_action ); ?>

		<div class="uappt-panel">
		<?php UAPPT_Admin::panel_head( 'pencil', __( '聯絡資訊與備註', 'ultimate-appointments' ) ); ?>
		<table class="form-table">
			<tr>
				<th><label for="uappt-edit-name"><?php esc_html_e( '客人姓名', 'ultimate-appointments' ); ?></label></th>
				<td><input type="text" id="uappt-edit-name" name="customer_name" class="regular-text" value="<?php echo esc_attr( $booking['customer_name'] ); ?>" /></td>
			</tr>
			<tr>
				<th><label for="uappt-edit-phone"><?php esc_html_e( '客人電話', 'ultimate-appointments' ); ?></label></th>
				<td><input type="text" id="uappt-edit-phone" name="customer_phone" class="regular-text" value="<?php echo esc_attr( $booking['customer_phone'] ); ?>" /></td>
			</tr>
			<tr>
				<th><label for="uappt-edit-note"><?php esc_html_e( '內部備註', 'ultimate-appointments' ); ?></label></th>
				<td>
					<textarea id="uappt-edit-note" name="note" class="large-text" rows="3"><?php echo esc_textarea( isset( $booking['note'] ) ? $booking['note'] : '' ); ?></textarea>
					<p class="description"><?php esc_html_e( '只有後台看得到，客人不會收到。例如客人要求換療程師、改期原因等。', 'ultimate-appointments' ); ?></p>
				</td>
			</tr>
		</table>

		<?php submit_button( __( '儲存變更', 'ultimate-appointments' ) ); ?>
		</div>
	</form>

	<?php if ( $amount_editable ) : ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="uappt-form">
			<input type="hidden" name="action" value="uappt_update_booking_amount" />
			<input type="hidden" name="booking_id" value="<?php echo esc_attr( $booking['id'] ); ?>" />
			<?php wp_nonce_field( $nonce_action ); ?>

			<div class="uappt-panel">
			<?php UAPPT_Admin::panel_head( 'tag', __( '金額與收款', 'ultimate-appointments' ) ); ?>
			<table class="form-table">
				<tr>
					<th><?php esc_html_e( '目前金額', 'ultimate-appointments' ); ?></th>
					<td>
						<?php if ( null === $booking['amount'] ) : ?>
							<?php esc_html_e( '—（沒有金額紀錄，報表不會計入這筆的業績）', 'ultimate-appointments' ); ?>
						<?php else : ?>
							<?php echo wp_kses_post( wc_price( (float) $booking['amount'] ) ); ?>
						<?php endif; ?>
						<?php if ( $has_order_item ) : ?>
							<p class="description"><?php esc_html_e( '這筆有對應的訂單，金額以訂單項目為準。在這裡改會同時更新訂單項目與報表數字，並在訂單備註留下紀錄。', 'ultimate-appointments' ); ?></p>
						<?php else : ?>
							<p class="description"><?php esc_html_e( '這筆沒有對應的訂單（手動建單時沒有勾「建立訂單」），這個數字就是報表唯一的營收來源。', 'ultimate-appointments' ); ?></p>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th><label for="uappt-amount-input"><?php esc_html_e( '改成', 'ultimate-appointments' ); ?></label></th>
					<td>
						<input type="number" id="uappt-amount-input" name="custom_amount" min="0" step="0.01" class="uappt-input-amount" value="<?php echo esc_attr( null === $booking['amount'] ? '' : wc_format_decimal( $booking['amount'] ) ); ?>" required />
						<p class="description">
							<?php esc_html_e( '實收的總金額，已含人員指定加價與人數。適合用在客人建完單之後才談的折扣、或當初金額打錯了。每次調整都會累積寫進下方的內部備註，不會覆蓋掉前一次的紀錄。', 'ultimate-appointments' ); ?>
						</p>
						<?php if ( $has_order_item ) : ?>
							<p class="description">
								<?php esc_html_e( '⚠️ 已經付過款的訂單，改金額只會更正帳面數字，不會自動退差額或補收款——金流要自己在綠界／POS 那邊處理。', 'ultimate-appointments' ); ?>
							</p>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th><label for="uappt-edit-payment"><?php esc_html_e( '收款方式', 'ultimate-appointments' ); ?></label></th>
					<td>
						<select id="uappt-edit-payment" name="payment_method">
							<option value=""><?php esc_html_e( '未指定', 'ultimate-appointments' ); ?></option>
							<?php foreach ( UAPPT_Payment::methods() as $uappt_pm ) : ?>
								<option value="<?php echo esc_attr( $uappt_pm['slug'] ); ?>" <?php selected( isset( $booking['payment_method'] ) ? $booking['payment_method'] : '', $uappt_pm['slug'] ); ?>><?php echo esc_html( $uappt_pm['label'] ); ?></option>
							<?php endforeach; ?>
							<?php // 設定裡已經刪掉、但這筆預約還留著的 slug：照樣列出來並選中， ?>
							<?php // 否則一按「更新金額」就會把歷史分類連帶洗成「未指定」。 ?>
							<?php if ( ! empty( $booking['payment_method'] ) && ! UAPPT_Payment::get( $booking['payment_method'] ) ) : ?>
								<option value="<?php echo esc_attr( $booking['payment_method'] ); ?>" selected="selected"><?php echo esc_html( UAPPT_Payment::label( $booking['payment_method'] ) ); ?></option>
							<?php endif; ?>
						</select>
						<p class="description">
							<?php esc_html_e( '改成新的收款方式時，會一併套用該方式「目前」的手續費率。', 'ultimate-appointments' ); ?>
							<?php if ( $has_order_item ) : ?>
								<?php esc_html_e( '這筆有對應的訂單，同一張訂單裡的其他預約也會一起改——一次收款不會一半刷卡一半付現。', 'ultimate-appointments' ); ?>
							<?php endif; ?>
						</p>
					</td>
				</tr>
				<tr>
					<th><label for="uappt-edit-nocharge"><?php esc_html_e( '未收費原因', 'ultimate-appointments' ); ?></label></th>
					<td>
						<select id="uappt-edit-nocharge" name="no_charge_reason">
							<option value=""><?php esc_html_e( '—（正常收費）', 'ultimate-appointments' ); ?></option>
							<?php foreach ( UAPPT_Booking::no_charge_reasons() as $uappt_nc => $uappt_nc_label ) : ?>
								<option value="<?php echo esc_attr( $uappt_nc ); ?>" <?php selected( isset( $booking['no_charge_reason'] ) ? $booking['no_charge_reason'] : '', $uappt_nc ); ?>><?php echo esc_html( $uappt_nc_label ); ?></option>
							<?php endforeach; ?>
						</select>
						<input type="text" name="no_charge_note" class="regular-text" value="<?php echo esc_attr( isset( $booking['no_charge_note'] ) ? $booking['no_charge_note'] : '' ); ?>" placeholder="<?php esc_attr_e( '對象或說明，例如：店長的妹妹', 'ultimate-appointments' ); ?>" />
						<p class="description">
							<?php esc_html_e( '做了服務但沒有收錢時標記原因。⚠️ 這個選項**不會**把金額改成 0——改金額請用上面那一欄，兩件事分開才不會有人選一個原因就把已付款的訂單靜靜歸零。', 'ultimate-appointments' ); ?>
						</p>
						<p class="description">
							<?php esc_html_e( '標記之後，這一筆會出現在「報表 ▸ 人員 ▸ 未收費／招待」的明細裡，那位人員被拉低的毛利就有了歸屬——招待的單材料照扣、業績卻是 0，不標記的話看報表的人只會覺得這個人成本特別高。', 'ultimate-appointments' ); ?>
						</p>
					</td>
				</tr>
			</table>

			<?php submit_button( __( '更新金額與收款方式', 'ultimate-appointments' ), 'secondary' ); ?>
			</div>
		</form>
	<?php endif; ?>

	<?php if ( UAPPT_Booking::STATUS_CONFIRMED === $booking['status'] ) : ?>
		<div class="uappt-panel">
			<?php UAPPT_Admin::panel_head( 'user-check', __( '完成狀態', 'ultimate-appointments' ) ); ?>
			<div class="uappt-panel-body">
				<p class="description">
					<?php esc_html_e( '客人已實際到場完成療程時標記，方便區分「還沒到」跟「已服務完」。標記後不會釋放時段。', 'ultimate-appointments' ); ?>
				</p>
				<?php // 兩顆按鈕用 .uappt-field-inline 排在同一行，不再靠 inline style 的 ?>
				<?php // display:inline-block 硬推——那樣兩個表單各自的基線對不齊。 ?>
				<div class="uappt-field-inline">
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php echo esc_js( __( '確定要標記為已完成嗎？', 'ultimate-appointments' ) ); ?>');">
						<input type="hidden" name="action" value="uappt_complete_booking" />
						<input type="hidden" name="booking_id" value="<?php echo esc_attr( $booking['id'] ); ?>" />
						<?php wp_nonce_field( $nonce_action ); ?>
						<?php submit_button( __( '標記為已完成', 'ultimate-appointments' ), 'secondary', 'submit', false ); ?>
					</form>
					<?php if ( $start_dt && $start_dt->getTimestamp() <= time() ) : ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php echo esc_js( __( '確定要標記客人未到嗎？時段不會釋放。', 'ultimate-appointments' ) ); ?>');">
							<input type="hidden" name="action" value="uappt_mark_no_show" />
							<input type="hidden" name="booking_id" value="<?php echo esc_attr( $booking['id'] ); ?>" />
							<?php wp_nonce_field( $nonce_action ); ?>
							<?php submit_button( __( '標記客人未到', 'ultimate-appointments' ), 'secondary', 'submit', false ); ?>
						</form>
					<?php endif; ?>
				</div>
				<?php if ( ! ( $start_dt && $start_dt->getTimestamp() <= time() ) ) : ?>
					<p class="description">
						<?php esc_html_e( '服務時間還沒到，還不能標記未到。', 'ultimate-appointments' ); ?>
					</p>
				<?php endif; ?>
			</div>
		</div>
	<?php elseif ( in_array( $booking['status'], array( UAPPT_Booking::STATUS_COMPLETED, UAPPT_Booking::STATUS_NO_SHOW ), true ) ) : ?>
		<?php // 標錯了要有路可以回頭。只有這兩個終止狀態能還原——它們都沒有釋放過 ?>
		<?php // 時間格，所以改回已確認不會跟任何人搶名額（見 revert_to_confirmed()）。 ?>
		<div class="uappt-panel">
			<?php UAPPT_Admin::panel_head( 'user-check', __( '完成狀態', 'ultimate-appointments' ) ); ?>
			<div class="uappt-panel-body">
				<p class="description">
					<?php
					printf(
						/* translators: %s: 目前的狀態名稱，例如「未到」 */
						esc_html__( '這筆預約目前是「%s」。如果是標錯了，可以還原成「已確認」再重新標記——這兩個狀態都沒有釋放時段，還原不會影響其他預約。', 'ultimate-appointments' ),
						esc_html( UAPPT_Admin::status_label( $booking['status'] ) )
					);
					?>
				</p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php echo esc_js( __( '確定要還原成「已確認」嗎？', 'ultimate-appointments' ) ); ?>');">
					<input type="hidden" name="action" value="uappt_revert_booking_status" />
					<input type="hidden" name="booking_id" value="<?php echo esc_attr( $booking['id'] ); ?>" />
					<?php wp_nonce_field( $nonce_action ); ?>
					<?php submit_button( __( '還原為已確認', 'ultimate-appointments' ), 'secondary', 'submit', false ); ?>
				</form>
			</div>
		</div>
	<?php endif; ?>
