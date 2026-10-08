<?php
/**
 * View：手動建立預約頁（電話/現場訂單用）。
 *
 * 傳入變數：$bookable_options（可預約項目，含可變商品的各變化款）、
 * $prefill_date（從日檢視「點空白處」或標題列按鈕帶過來的日期，Y-m-d 或空
 * 字串——當作日期欄位的初始值）。同一個連結帶的人員 ID 不經過這裡，直接交給
 * JS（見 assets/js/admin-booking.js 與 UAPPT_Admin::enqueue_assets()）。
 *
 * 版面：十一個欄位原本一路平鋪在同一張 form-table 裡，但它們其實是三種不同
 * 性質的東西——要訂什麼、訂給誰、收多少錢／要不要開訂單。拆成三個面板之後，
 * 客服照著由上而下填就是完整的流程，不用在一長串欄位裡自己分辨哪裡是一段的結束。
 *
 * 「金額」刻意跟訂單設定同一個面板，但**不是**訂單專屬的欄位：沒有勾「建立
 * 訂單」時，`bookings.amount` 就是報表唯一的營收來源，金額照樣要能填。
 *
 * ⚠️ **所有欄位的 id 與 name 一個都沒有動。** admin-booking.js 與
 * admin-manual-booking.js 全部靠 id 選取（#uappt-mb-product、#uappt-mb-date、
 * #uappt-mb-slot、#uappt-mb-staff、#uappt-mb-date-input、#uappt-mb-time-hm、
 * #uappt-mb-customer），而且沒有用到任何 DOM 走訪（closest／parent／siblings），
 * 所以搬動結構是安全的；但改 id 會直接讓時段選擇器整組失效。
 *
 * @package Ultimate_Appointments
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$horizon_days = max( 1, (int) get_option( 'uappt_booking_horizon_days', 30 ) );
$min_date     = current_time( 'Y-m-d' );
$max_date     = uappt_local_date( $min_date, "+{$horizon_days} days" );
?>
	<p class="uappt-page-desc">
		<?php esc_html_e( '用於電話訂購或現場臨櫃預約：選擇服務項目與時段後，系統會直接鎖定該時段為「已確認」，並自動建立一張對應的訂單（不需要客人自行結帳）。', 'ultimate-appointments' ); ?>
	</p>

	<?php if ( empty( $bookable_options ) ) : ?>
		<p><?php esc_html_e( '目前沒有任何開放預約的服務項目。請先到商品編輯頁的「預約設定」分頁勾選「需要預約時段」，並指定可服務人員。', 'ultimate-appointments' ); ?></p>
		<?php return; ?>
	<?php endif; ?>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="uappt-form" id="uappt-manual-booking-form">
		<input type="hidden" name="action" value="uappt_create_manual_booking" />
		<?php wp_nonce_field( 'uappt_create_manual_booking' ); ?>

		<div class="uappt-panel">
			<?php UAPPT_Admin::panel_head( 'calendar', __( '服務與時段', 'ultimate-appointments' ) ); ?>
			<table class="form-table">
				<tr>
					<th><label for="uappt-mb-product"><?php esc_html_e( '服務項目', 'ultimate-appointments' ); ?></label></th>
					<td>
						<select id="uappt-mb-product" name="bookable" required>
							<option value=""><?php esc_html_e( '請選擇…', 'ultimate-appointments' ); ?></option>
							<?php foreach ( $bookable_options as $option ) : ?>
								<option value="<?php echo esc_attr( $option['value'] ); ?>">
									<?php echo esc_html( $option['label'] ); ?>
								</option>
							<?php endforeach; ?>
						</select>
						<p class="description"><?php esc_html_e( '可變商品會列出各個方案（變化款），請直接選擇客人要的方案。', 'ultimate-appointments' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><label for="uappt-mb-date"><?php esc_html_e( '日期', 'ultimate-appointments' ); ?></label></th>
					<td>
						<?php $initial_date = ( $prefill_date && $prefill_date >= $min_date && $prefill_date <= $max_date ) ? $prefill_date : $min_date; ?>
						<input type="date" id="uappt-mb-date" min="<?php echo esc_attr( $min_date ); ?>" max="<?php echo esc_attr( $max_date ); ?>" value="<?php echo esc_attr( $initial_date ); ?>" required />
						<p class="description"><?php esc_html_e( '預設今天——門市現場建單最常見的情況。要幫客人排未來的日期再自行調整。', 'ultimate-appointments' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><label for="uappt-mb-staff"><?php esc_html_e( '指定服務人員', 'ultimate-appointments' ); ?></label></th>
					<td>
						<select id="uappt-mb-staff" name="staff_id">
							<option value="0"><?php esc_html_e( '不指定（系統自動安排）', 'ultimate-appointments' ); ?></option>
						</select>
						<p class="description"><?php esc_html_e( '請先選擇服務項目，才會列出這項服務的候選人員。若人員有設定指定加價，會反映在下方建立的訂單金額中。', 'ultimate-appointments' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><label for="uappt-mb-slot"><?php esc_html_e( '時段', 'ultimate-appointments' ); ?></label></th>
					<td>
						<select id="uappt-mb-slot" required>
							<option value=""><?php esc_html_e( '請先選擇服務項目與日期', 'ultimate-appointments' ); ?></option>
						</select>
						<input type="hidden" name="date_ymd" id="uappt-mb-date-input" value="" />
						<input type="hidden" name="time_hm" id="uappt-mb-time-hm" value="" />
					</td>
				</tr>
				<tr>
					<th><label for="uappt-mb-participants"><?php esc_html_e( '報名人數', 'ultimate-appointments' ); ?></label></th>
					<td>
						<input type="number" id="uappt-mb-participants" name="units" min="1" step="1" value="1" class="uappt-input-num" />
						<p class="description"><?php esc_html_e( '只有這項服務有開放「一次幫多人報名」時才能填 1 以上，超過剩餘名額會直接被拒絕，跟前台規則一致。一般服務留 1 即可。', 'ultimate-appointments' ); ?></p>
					</td>
				</tr>
			</table>
		</div>

		<div class="uappt-panel">
			<?php UAPPT_Admin::panel_head( 'user', __( '客人資料', 'ultimate-appointments' ) ); ?>
			<table class="form-table">
				<tr>
					<th><label for="uappt-mb-customer"><?php esc_html_e( '會員（選填）', 'ultimate-appointments' ); ?></label></th>
					<td>
						<?php
						// width 留在 inline style 不是偷懶：select2（WooCommerce 的
						// wc-enhanced-select）預設用 width:'resolve'，初始化時直接讀
						// 這個元素的 style 屬性來決定下拉容器寬度。搬到 CSS class 之後
						// 能不能被 resolve 到會依 select2 版本而異，這裡是功能相依而
						// 不是裝飾，維持現狀最保險。
						?>
						<select id="uappt-mb-customer" name="customer_id" class="wc-customer-search" style="width: 400px;" data-placeholder="<?php esc_attr_e( '輸入姓名、電話或 Email 搜尋會員…', 'ultimate-appointments' ); ?>" data-allow_clear="true"></select>
						<p class="description"><?php esc_html_e( '綁定會員後，這張訂單會出現在該會員的消費紀錄裡，也才會依會員制度累積點數。選定後下面的姓名/電話會自動帶入，仍可手動修改。客人還沒有帳號的話，這欄留空即可，跟現在一樣只用姓名電話建立。不會因此寄送訂單通知信給客人。', 'ultimate-appointments' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><label for="uappt-mb-name"><?php esc_html_e( '客人姓名', 'ultimate-appointments' ); ?></label></th>
					<td><input type="text" id="uappt-mb-name" name="customer_name" class="regular-text" /></td>
				</tr>
				<tr>
					<th><label for="uappt-mb-phone"><?php esc_html_e( '客人電話', 'ultimate-appointments' ); ?></label></th>
					<td><input type="text" id="uappt-mb-phone" name="customer_phone" class="regular-text" /></td>
				</tr>
			</table>
		</div>

		<div class="uappt-panel">
			<?php UAPPT_Admin::panel_head( 'clipboard-list', __( '金額與訂單', 'ultimate-appointments' ) ); ?>
			<table class="form-table">
				<tr>
					<th><?php esc_html_e( '金額', 'ultimate-appointments' ); ?></th>
					<td>
						<label>
							<input type="checkbox" id="uappt-amount-toggle" name="custom_amount_enabled" value="1" />
							<?php esc_html_e( '自訂這筆的金額', 'ultimate-appointments' ); ?>
						</label>
						<p class="description"><?php esc_html_e( '不勾就照「方案價 × 人數 + 人員指定加價」自動計算，跟前台完全一樣。現場的熟客折扣、湊整數、招待勾起來直接填實收金額即可。', 'ultimate-appointments' ); ?></p>
						<?php // hidden 屬性由 admin-amount.js 切換；沒有 JS 時欄位會直接顯示， ?>
						<?php // 表單仍然送得出去（後端只看 custom_amount_enabled 有沒有勾）。 ?>
						<p id="uappt-amount-field" hidden>
							<input type="number" id="uappt-amount-input" name="custom_amount" min="0" step="0.01" class="uappt-input-amount" />
							<span class="description"><?php esc_html_e( '這筆實收的總金額，已含人員指定加價與人數，不用再另外加。填 0 代表招待，送出前會再確認一次。', 'ultimate-appointments' ); ?></span>
						</p>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( '建立訂單', 'ultimate-appointments' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="create_order" value="1" checked="checked" />
							<?php esc_html_e( '一併建立 WooCommerce 訂單（計入營收、會員消費紀錄）', 'ultimate-appointments' ); ?>
						</label>
						<p class="description"><?php esc_html_e( '不勾就只建立預約、把時段鎖住，不產生訂單——適合收款走 POS、或當下還沒結帳的現場客人。時段的鎖定完全一樣，不會被重複預約。', 'ultimate-appointments' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><label for="uappt-mb-nocharge"><?php esc_html_e( '未收費原因', 'ultimate-appointments' ); ?></label></th>
					<td>
						<select id="uappt-mb-nocharge" name="no_charge_reason">
							<option value=""><?php esc_html_e( '—（正常收費）', 'ultimate-appointments' ); ?></option>
							<?php foreach ( UAPPT_Booking::no_charge_reasons() as $uappt_nc => $uappt_nc_label ) : ?>
								<option value="<?php echo esc_attr( $uappt_nc ); ?>"><?php echo esc_html( $uappt_nc_label ); ?></option>
							<?php endforeach; ?>
						</select>
						<input type="text" name="no_charge_note" class="regular-text" value="" placeholder="<?php esc_attr_e( '對象或說明，例如：店長的妹妹', 'ultimate-appointments' ); ?>" />
						<p class="description">
							<?php esc_html_e( '招待、員工親友、重做補償這類「做了但不收錢」的單，選一個原因。金額請在上面填 0（或實收的折扣價）——這一欄只負責記錄原因，不會自己改金額。', 'ultimate-appointments' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th><label for="uappt-mb-payment"><?php esc_html_e( '收款方式', 'ultimate-appointments' ); ?></label></th>
					<td>
						<select id="uappt-mb-payment" name="payment_method">
							<?php // 「未指定」放第一個而且是預設值：現場還沒結帳的客人很常見， ?>
							<?php // 預設挑一個具體方式會讓報表憑空長出一堆假的現金收入。 ?>
							<option value=""><?php esc_html_e( '未指定', 'ultimate-appointments' ); ?></option>
							<?php foreach ( UAPPT_Payment::methods() as $uappt_pm ) : ?>
								<option value="<?php echo esc_attr( $uappt_pm['slug'] ); ?>"><?php echo esc_html( $uappt_pm['label'] ); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="description">
							<?php
							printf(
								/* translators: %s: 指向設定頁收款設定頁籤的連結 */
								esc_html__( '報表會照這個分類統計現金／刷卡佔比，並依費率算手續費。清單與費率在 %s 調整。', 'ultimate-appointments' ),
								'<a href="' . esc_url( UAPPT_Admin::url( 'settings', array( 'tab' => 'payment' ) ) ) . '">' . esc_html__( '設定 ▸ 收款設定', 'ultimate-appointments' ) . '</a>'
							);
							?>
						</p>
						<p class="description"><?php esc_html_e( '沒有勾選上面的「建立訂單」時，這個設定不會生效。', 'ultimate-appointments' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><label for="uappt-mb-order-status"><?php esc_html_e( '建立的訂單狀態', 'ultimate-appointments' ); ?></label></th>
					<td>
						<select id="uappt-mb-order-status" name="order_status">
							<option value="processing"><?php esc_html_e( '處理中（尚未收款，例如現場付款）', 'ultimate-appointments' ); ?></option>
							<option value="completed"><?php esc_html_e( '已完成（已當場收款）', 'ultimate-appointments' ); ?></option>
						</select>
						<p class="description"><?php esc_html_e( '沒有勾選上面的「建立訂單」時，這個設定不會生效。', 'ultimate-appointments' ); ?></p>
					</td>
				</tr>
			</table>
		</div>

		<?php submit_button( __( '建立預約', 'ultimate-appointments' ) ); ?>
	</form>
