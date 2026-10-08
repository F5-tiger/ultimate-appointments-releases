<?php
/**
 * View：設定頁。
 *
 * 傳入變數：$hold_minutes、$slot_interval、$horizon_days、$min_lead_minutes、
 * $cancel_deadline、$pending_timeout、$show_remaining、$show_staff、
 * $product_page_mode、$wizard_after_add、$booking_page_id、$staff_portal_show_amount、
 * $reminder（提醒相關設定陣列）、$convertible_ids、$converted_ids、
 * $tabs、$current_tab、$shop_closure（公休日設定，UAPPT_Shop_Closure::get()）、
 *
 * 內容分成五個頁籤（見 UAPPT_Admin::settings_tabs()）。頁籤是純顯示層：五個
 * 頁籤的欄位全部都在 DOM 裡，只是用 CSS 藏起來——理由見下面 uappt_tab_pane()
 * 的說明。
 *
 * @package Ultimate_Appointments
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$line_ready       = UAPPT_Line::is_available();
$shop_targets     = UAPPT_Line::get_shop_targets();
$has_notify_addon = class_exists( 'WCLON_Settings' );

if ( ! function_exists( 'uappt_tab_pane' ) ) {
	/**
	 * 輸出頁籤面板容器的 class 與 data 屬性。
	 *
	 * 用法：`<div<?php uappt_tab_pane( 'rules', $current_tab ); ?>>`
	 *
	 * $owners 刻意收成「空白分隔的多值」而不是單一字串：「儲存設定」按鈕同時
	 * 屬於四個有欄位的頁籤（預約規則／收款設定／前台顯示／通知提醒），只有
	 * 「進階工具」沒有可儲存的欄位、那一頁不該出現儲存鈕。
	 *
	 * is-active 由 PHP 決定，不是等 JS 跑完才套上——不然整頁會先把五個頁籤的
	 * 內容全部閃出來再收起來。
	 *
	 * ⚠️ 所有頁籤的欄位都必須留在 DOM 裡（只是用 CSS 藏起來），不能改成「只印
	 * 出目前這一頁的欄位」：handle_save_settings() 是把每一個 option 都無條件
	 * 寫入、欄位沒送上來就寫入預設值，少印一個欄位＝按下儲存就把那個設定悄悄
	 * 重設掉。
	 *
	 * @param string $owners  這個面板屬於哪些頁籤（空白分隔）。
	 * @param string $current 目前的頁籤。
	 * @param string $classes 額外要加的 class。
	 */
	function uappt_tab_pane( $owners, $current, $classes = '' ) {
		$active = in_array( $current, preg_split( '/\s+/', trim( $owners ) ), true );

		printf(
			' class="%1$suappt-tab-pane%2$s" data-uappt-tab="%3$s"',
			$classes ? esc_attr( $classes ) . ' ' : '',
			$active ? ' is-active' : '',
			esc_attr( $owners )
		);
	}
}

if ( ! function_exists( 'uappt_payment_row' ) ) {
	/**
	 * 輸出收款方式設定的一列。
	 *
	 * 抽成函式的理由：已存在的列與「新增用的空白列」欄位完全一樣，抄兩遍
	 * 遲早會有一邊漏掉新欄位。JS 新增列時也是複製這一列的 DOM（見
	 * admin-payment.js），所以三個來源共用同一份結構。
	 *
	 * @param int   $index    陣列索引（表單欄位名稱用）。
	 * @param array $method   ['slug','label','rate','gateway']，空白列傳空陣列。
	 * @param array $gateways gateway id => 標題。
	 */
	function uappt_payment_row( $index, array $method, array $gateways ) {
		$slug    = isset( $method['slug'] ) ? $method['slug'] : '';
		$label   = isset( $method['label'] ) ? $method['label'] : '';
		$rate    = isset( $method['rate'] ) ? (float) $method['rate'] : 0.0;
		$gateway = isset( $method['gateway'] ) ? $method['gateway'] : '';
		$name    = 'payment_methods[' . (int) $index . ']';
		?>
		<tr class="uappt-pm-row">
			<td data-label="<?php esc_attr_e( '名稱', 'ultimate-appointments' ); ?>">
				<?php // slug 是歷史資料的鍵，不讓管理者改（改了等於把舊紀錄的歸類切斷）， ?>
				<?php // 但要跟著送回來，不然每次存檔都會依名稱重新產生一次。 ?>
				<input type="hidden" name="<?php echo esc_attr( $name ); ?>[slug]" value="<?php echo esc_attr( $slug ); ?>" />
				<input type="text" class="regular-text" name="<?php echo esc_attr( $name ); ?>[label]" value="<?php echo esc_attr( $label ); ?>" placeholder="<?php esc_attr_e( '例如：刷卡－分期 6 期', 'ultimate-appointments' ); ?>" />
			</td>
			<td data-label="<?php esc_attr_e( '手續費率', 'ultimate-appointments' ); ?>">
				<span class="uappt-pm-rate">
					<input type="number" class="uappt-input-rate" name="<?php echo esc_attr( $name ); ?>[rate]" value="<?php echo esc_attr( uappt_payment_rate_value( $rate ) ); ?>" min="0" max="100" step="0.001" inputmode="decimal" />
					<span aria-hidden="true">%</span>
				</span>
			</td>
			<td data-label="<?php esc_attr_e( '線上金流', 'ultimate-appointments' ); ?>">
				<select name="<?php echo esc_attr( $name ); ?>[gateway]">
					<option value=""><?php esc_html_e( '現場收款（不對應）', 'ultimate-appointments' ); ?></option>
					<?php foreach ( $gateways as $gateway_id => $gateway_title ) : ?>
						<option value="<?php echo esc_attr( $gateway_id ); ?>" <?php selected( $gateway, $gateway_id ); ?>><?php echo esc_html( $gateway_title ); ?></option>
					<?php endforeach; ?>
					<?php // 設定裡存著、但那個金流外掛已經停用／移除時，選項不在清單裡， ?>
					<?php // 下拉會默默跳回第一項「現場收款」，存檔就把對應關係弄丟了。 ?>
					<?php if ( '' !== $gateway && ! isset( $gateways[ $gateway ] ) ) : ?>
						<option value="<?php echo esc_attr( $gateway ); ?>" selected="selected">
							<?php
							printf(
								/* translators: %s: 金流代碼 */
								esc_html__( '%s（找不到這個金流）', 'ultimate-appointments' ),
								esc_html( $gateway )
							);
							?>
						</option>
					<?php endif; ?>
				</select>
			</td>
			<td class="uappt-pm-actions">
				<button type="button" class="button-link uappt-pm-remove"><?php esc_html_e( '移除', 'ultimate-appointments' ); ?></button>
			</td>
		</tr>
		<?php
	}
}

if ( ! function_exists( 'uappt_payment_rate_value' ) ) {
	/**
	 * 費率的顯示值。
	 *
	 * 0 要印成空字串而不是 "0"：欄位預設就是 0，印成 "0" 看起來像「已經設定過
	 * 而且確實是零」，印成空的才看得出「還沒填」。送出時空字串一樣被當成 0。
	 *
	 * @param float $rate 費率。
	 * @return string
	 */
	function uappt_payment_rate_value( $rate ) {
		if ( (float) $rate <= 0 ) {
			return '';
		}
		// rtrim 掉尾巴的零：2.0000 顯示成 2，2.7500 顯示成 2.75。
		return rtrim( rtrim( number_format( (float) $rate, 4, '.', '' ), '0' ), '.' );
	}
}
?>

	<?php UAPPT_Admin::render_tabs( $tabs, $current_tab, UAPPT_Admin::url( 'settings' ) ); ?>


	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="uappt-form">
		<input type="hidden" name="action" value="uappt_save_settings" />
		<?php wp_nonce_field( 'uappt_save_settings' ); ?>
		<?php // 目前在哪個頁籤。JS 會在切換頁籤時同步這個值，儲存後才轉址得回原來那一頁。 ?>
		<input type="hidden" name="uappt_tab" class="uappt-tab-field" value="<?php echo esc_attr( $current_tab ); ?>" />

		<div<?php uappt_tab_pane( 'rules', $current_tab ); ?>>
			<div class="uappt-panel">
				<?php UAPPT_Admin::panel_head( 'settings', __( '預約規則', 'ultimate-appointments' ) ); ?>
			<table class="form-table">
				<tr>
					<th><label for="uappt-hold-minutes"><?php esc_html_e( '暫留鎖定時間（分鐘）', 'ultimate-appointments' ); ?></label></th>
					<td>
						<input type="number" id="uappt-hold-minutes" name="hold_minutes" min="1" step="1" value="<?php echo esc_attr( $hold_minutes ); ?>" />
						<p class="description"><?php esc_html_e( '客人選好時段、加入購物車後，會暫時鎖定這個時段這麼久；若逾時仍未完成付款，時段會自動釋放給其他人。建議 15 分鐘，需配合結帳/金流流程所需時間調整。', 'ultimate-appointments' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><label for="uappt-slot-interval"><?php esc_html_e( '時間格顆粒（分鐘）', 'ultimate-appointments' ); ?></label></th>
					<td>
						<input type="number" id="uappt-slot-interval" name="slot_interval_minutes" min="5" step="5" value="<?php echo esc_attr( $slot_interval ); ?>" />
						<p class="description"><?php esc_html_e( '這是全站預設值：佔用計算的最小時間單位，也是客人選時段時可選擇的間隔。若某位人員有自己的顆粒設定（在「人力資源」編輯頁），會優先套用該設定，這裡只影響沒有個別設定的人員。建議 15 分鐘。修改後只影響之後新產生的時段，不會改變既有預約的紀錄。', 'ultimate-appointments' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><label for="uappt-horizon-days"><?php esc_html_e( '開放預約天數', 'ultimate-appointments' ); ?></label></th>
					<td>
						<input type="number" id="uappt-horizon-days" name="booking_horizon_days" min="1" step="1" value="<?php echo esc_attr( $horizon_days ); ?>" />
						<p class="description"><?php esc_html_e( '客人最多能預約幾天以內的時段（例如 30 代表只能預約未來 30 天內）。個別商品可在「預約設定」分頁覆寫。', 'ultimate-appointments' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><label for="uappt-min-lead"><?php esc_html_e( '最少提前預約時間（分鐘）', 'ultimate-appointments' ); ?></label></th>
					<td>
						<input type="number" id="uappt-min-lead" name="min_lead_minutes" min="0" step="5" value="<?php echo esc_attr( $min_lead_minutes ); ?>" />
						<p class="description"><?php esc_html_e( '客人最晚要在服務開始前多久完成預約。設 120 就是兩小時內的時段不再開放，讓店家有時間準備。0 代表不限制（只要時段還沒開始就能預約）。個別商品可在「預約設定」分頁覆寫。', 'ultimate-appointments' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><label for="uappt-cancel-deadline"><?php esc_html_e( '客人可自行取消的期限（小時）', 'ultimate-appointments' ); ?></label></th>
					<td>
						<input type="number" id="uappt-cancel-deadline" name="cancel_deadline_hours" min="0" step="1" value="<?php echo esc_attr( $cancel_deadline ); ?>" />
						<p class="description"><?php esc_html_e( '只適用於「待付款」（訂單還沒完成付款，例如客人選了 ATM 轉帳／超商代碼）的預約——這種訂單客人根本還沒付錢，自助取消沒有退款問題。已付款（已確認）的預約一律不開放自助取消，一定要透過客服辦理，避免時段放掉了、錢卻還沒退。距離服務開始不到這麼多小時就不再開放自助取消，需改為聯繫客服。0 代表隨時都能取消。', 'ultimate-appointments' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><label for="uappt-pending-timeout"><?php esc_html_e( '待付款訂單逾時自動取消（小時）', 'ultimate-appointments' ); ?></label></th>
					<td>
						<input type="number" id="uappt-pending-timeout" name="pending_order_timeout_hours" min="1" step="1" value="<?php echo esc_attr( $pending_timeout ); ?>" />
						<p class="description">
							<?php esc_html_e( '訂單成立後，時段就不再受「暫留鎖定時間」保護，改由訂單自己的狀態決定要不要釋放（見說明文件）。這是最後一道安全網：訂單建立超過這麼多小時，仍停在「保留」或「處理中但還沒付款」，系統會自動取消訂單並釋放時段。', 'ultimate-appointments' ); ?>
						</p>
						<p class="description">
							<?php esc_html_e( '只用信用卡即時付款的話，WooCommerce 自己就會在一小時內清掉放棄付款的訂單，這裡的設定幾乎用不到；如果有開放 ATM 轉帳或超商代碼，付款期間可能長達好幾天，請把這裡設得比實際付款期限長，避免客人還在期限內、時段就先被取消。', 'ultimate-appointments' ); ?>
						</p>
					</td>
				</tr>
			</table>

			</div>
		</div>

		<div<?php uappt_tab_pane( 'rules', $current_tab, 'uappt-panel' ); ?>>
			<?php UAPPT_Admin::panel_head( 'calendar-off', __( '公休日', 'ultimate-appointments' ) ); ?>
			<div class="uappt-panel-body">
				<p class="description">
					<?php esc_html_e( '全店不開放預約的日子。設定在這裡是因為公休是「店」的事，不是某一位人員的——放在人員編輯裡等於每位人員都要設一次。', 'ultimate-appointments' ); ?>
				</p>
				<p class="description uappt-hint">
					<?php esc_html_e( '⚠️ 公休只關掉「可預約時段」，完全不影響電商：零售商品照常販售、購物車與結帳都不受影響。這跟商品的「暫停接受預約」是兩回事——那個會讓該商品整個不可購買。', 'ultimate-appointments' ); ?>
				</p>
				<p class="description uappt-hint">
					<?php esc_html_e( '優先序是「人員的單日調整 ＞ 公休 ＞ 人員的每週班表」。所以過年留一位師傅值班時，幫他在人員編輯裡設一筆「單日自訂時段」就能破例開工，不必把整天的公休拿掉。', 'ultimate-appointments' ); ?>
				</p>
				<p class="description uappt-hint">
					<?php esc_html_e( '⚠️ 設定公休不會取消任何既有預約，也不會通知客人。已經約在那幾天的客人需要你自己聯繫改期。跨午夜的深夜班跟著「開店那一天」走：標週日公休，週六晚上延伸到週日凌晨的那段不會被切掉。', 'ultimate-appointments' ); ?>
				</p>
			</div>
			<table class="form-table">
				<tr>
					<th><?php esc_html_e( '每週固定公休', 'ultimate-appointments' ); ?></th>
					<td>
						<fieldset class="uappt-closure-weekdays">
							<?php foreach ( UAPPT_Staff::WEEKDAY_KEYS as $uappt_wk ) : ?>
								<label>
									<input type="checkbox" name="shop_closure_weekdays[]" value="<?php echo esc_attr( $uappt_wk ); ?>" <?php checked( in_array( $uappt_wk, $shop_closure['weekdays'], true ) ); ?> />
									<?php
									printf(
										/* translators: %s: 星期幾的單字 */
										esc_html__( '週%s', 'ultimate-appointments' ),
										esc_html( UAPPT_Staff::weekday_short_label( $uappt_wk ) )
									);
									?>
								</label>
							<?php endforeach; ?>
						</fieldset>
						<p class="description"><?php esc_html_e( '例如每週日公休。留空代表沒有固定公休日。', 'ultimate-appointments' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( '指定日期公休', 'ultimate-appointments' ); ?></th>
					<td>
						<table class="uappt-table uappt-closure-dates">
							<thead>
								<tr>
									<th scope="col"><?php esc_html_e( '起', 'ultimate-appointments' ); ?></th>
									<th scope="col"><?php esc_html_e( '迄', 'ultimate-appointments' ); ?></th>
									<th scope="col"><?php esc_html_e( '備註', 'ultimate-appointments' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php
								// 永遠多印一列空白供新增，跟收款方式、抽成級距同一個模式：
								// 沒有 JS 時那一列就是唯一的新增方式。
								$uappt_rows   = $shop_closure['dates'];
								$uappt_rows[] = array( 'from' => '', 'to' => '', 'note' => '' );
								?>
								<?php foreach ( $uappt_rows as $uappt_i => $uappt_row ) : ?>
									<tr>
										<td data-label="<?php esc_attr_e( '起', 'ultimate-appointments' ); ?>">
											<input type="date" name="shop_closure_dates[<?php echo (int) $uappt_i; ?>][from]" value="<?php echo esc_attr( $uappt_row['from'] ); ?>" />
										</td>
										<td data-label="<?php esc_attr_e( '迄', 'ultimate-appointments' ); ?>">
											<input type="date" name="shop_closure_dates[<?php echo (int) $uappt_i; ?>][to]" value="<?php echo esc_attr( $uappt_row['to'] ); ?>" />
										</td>
										<td data-label="<?php esc_attr_e( '備註', 'ultimate-appointments' ); ?>">
											<input type="text" class="regular-text" name="shop_closure_dates[<?php echo (int) $uappt_i; ?>][note]" value="<?php echo esc_attr( $uappt_row['note'] ); ?>" placeholder="<?php esc_attr_e( '例如：春節 / 員工旅遊', 'ultimate-appointments' ); ?>" />
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
						<p class="description"><?php esc_html_e( '只填「起」代表單日公休。兩個日期填反了會自動對調。整列留空就是沒填，儲存後那一列會消失。', 'ultimate-appointments' ); ?></p>
					</td>
				</tr>
				<?php if ( '' !== UAPPT_Shop_Closure::summary() ) : ?>
					<tr>
						<th><?php esc_html_e( '目前設定', 'ultimate-appointments' ); ?></th>
						<td><strong><?php echo esc_html( UAPPT_Shop_Closure::summary() ); ?></strong></td>
					</tr>
				<?php endif; ?>
			</table>
		</div>

		<?php
		// 班別搬到「人員管理 ▸ 班別設定」了（v2.98.0）——班別是排班排到一半才會想改的東西，
		// 跟排班放在一起。這裡留一塊指路牌：舊書籤、舊習慣點進來的人不會以為功能不見了。
		//
		// ⚠️ 這一塊**不能**再放任何 shift_presets 欄位：handle_save_settings() 已經不碰
		// 班別，放了也存不進去；反過來，要是哪天有人把那一行加回去，這裡又沒有欄位，
		// 存一次設定就會把所有班別清空。
		?>
		<div<?php uappt_tab_pane( 'rules', $current_tab, 'uappt-panel' ); ?>>
			<?php UAPPT_Admin::panel_head( 'clock', __( '班別', 'ultimate-appointments' ) ); ?>
			<div class="uappt-panel-body">
				<p>
					<?php esc_html_e( '班別已經搬到「人員管理 ▸ 班別設定」，跟排班放在一起。', 'ultimate-appointments' ); ?>
					<a class="button" href="<?php echo esc_url( UAPPT_Admin::url( 'staff', array( 'tab' => 'shifts' ) ) ); ?>"><?php esc_html_e( '前往班別設定', 'ultimate-appointments' ); ?></a>
				</p>
			</div>
		</div>

		<div<?php uappt_tab_pane( 'payment', $current_tab ); ?>>
			<div class="uappt-panel">
				<?php UAPPT_Admin::panel_head( 'credit-card', __( '收款方式與手續費', 'ultimate-appointments' ) ); ?>
				<div class="uappt-panel-body">
					<p class="description">
						<?php esc_html_e( '建立預約訂單時可以選擇收款方式，報表會照這裡的分類統計「現金／刷卡各佔多少」，並依費率算出手續費與實收淨額。', 'ultimate-appointments' ); ?>
					</p>
					<p class="description">
						<?php esc_html_e( '⚠️ 費率請照跟收單行或金流公司談定的合約填。分期跟一般刷卡的費率通常差很多，而且期數越長商店負擔越重——要分開統計就各自建立一列（例如「刷卡－一般」「刷卡－分期 6 期」），報表才會準。', 'ultimate-appointments' ); ?>
					</p>
					<table class="uappt-table uappt-payment-table">
						<thead>
							<tr>
								<th scope="col"><?php esc_html_e( '名稱', 'ultimate-appointments' ); ?></th>
								<th scope="col"><?php esc_html_e( '手續費率', 'ultimate-appointments' ); ?></th>
								<th scope="col"><?php esc_html_e( '線上金流', 'ultimate-appointments' ); ?></th>
								<th scope="col" class="uappt-pm-actions"><span class="screen-reader-text"><?php esc_html_e( '操作', 'ultimate-appointments' ); ?></span></th>
							</tr>
						</thead>
						<tbody class="uappt-pm-body">
							<?php foreach ( $payment_methods as $uappt_pm_index => $uappt_pm ) : ?>
								<?php uappt_payment_row( $uappt_pm_index, $uappt_pm, $payment_gateways ); ?>
							<?php endforeach; ?>
							<?php // 永遠多一列空白供直接新增，不用先按按鈕。沒有 JS 時這一列就是 ?>
							<?php // 唯一的新增方式，所以它由 PHP 印出來，不是靠 JS 補。 ?>
							<?php uappt_payment_row( count( $payment_methods ), array(), $payment_gateways ); ?>
						</tbody>
					</table>
					<p class="uappt-pm-add-wrap">
						<button type="button" class="button uappt-pm-add"><?php esc_html_e( '＋ 新增一列', 'ultimate-appointments' ); ?></button>
					</p>
					<p class="description">
						<?php esc_html_e( '名稱留空的列在儲存時會被丟掉。已經用過的收款方式如果被刪除，過去的預約仍然保留原本的分類，報表上會標示「已刪除」。', 'ultimate-appointments' ); ?>
					</p>

					<?php if ( $payment_unmapped ) : ?>
						<div class="notice notice-info inline">
							<p>
								<?php
								printf(
									/* translators: %s: 尚未對應的金流名稱清單 */
									esc_html__( '這個網站還有這些線上金流沒有對應到任何收款方式：%s。線上訂單會被歸到「未指定」、手續費算 0，要納入統計請在上面把它們指到對應的列（或新增一列）。', 'ultimate-appointments' ),
									esc_html( implode( '、', $payment_unmapped ) )
								);
								?>
							</p>
						</div>
					<?php endif; ?>
				</div>
			</div>

			<div class="uappt-panel">
				<?php UAPPT_Admin::panel_head( 'wallet', __( '手續費是怎麼算的', 'ultimate-appointments' ) ); ?>
				<div class="uappt-panel-body">
					<p class="description">
						<?php esc_html_e( '手續費＝該筆金額 × 費率。WooCommerce 本身不會保存金流公司實際抽走的金額，所以一律用合約費率自己算——特約商店的費率是談定的固定值，只要上面的分類對得上合約，算出來就是對帳單上的數字。', 'ultimate-appointments' ); ?>
					</p>
					<p class="description">
						<?php esc_html_e( '建立訂單時，系統會把「當下」的費率一起記在那筆預約上。之後調整費率只會影響新的預約，已經結束的月份不會跟著變動——避免上個月的報表在調完費率之後跟已經印出去的那份對不上。', 'ultimate-appointments' ); ?>
					</p>
					<p class="description">
						<?php esc_html_e( '機台月租、帳單費這類固定費用不隨金額變動，不屬於手續費，請不要換算成費率填進來。', 'ultimate-appointments' ); ?>
					</p>
				</div>
			</div>
		</div>

		<div<?php uappt_tab_pane( 'display', $current_tab ); ?>>
			<div class="uappt-panel">
				<?php UAPPT_Admin::panel_head( 'layout-dashboard', __( '前台顯示', 'ultimate-appointments' ) ); ?>
			<table class="form-table">
				<tr>
					<th><label for="uappt-show-remaining"><?php esc_html_e( '時段的「剩 X 位」', 'ultimate-appointments' ); ?></label></th>
					<td>
						<select id="uappt-show-remaining" name="show_remaining">
							<option value="auto" <?php selected( $show_remaining, 'auto' ); ?>><?php esc_html_e( '自動（有 2 位以上可服務人員時才顯示）', 'ultimate-appointments' ); ?></option>
							<option value="always" <?php selected( $show_remaining, 'always' ); ?>><?php esc_html_e( '一律顯示', 'ultimate-appointments' ); ?></option>
							<option value="never" <?php selected( $show_remaining, 'never' ); ?>><?php esc_html_e( '一律不顯示', 'ultimate-appointments' ); ?></option>
						</select>
						<p class="description"><?php esc_html_e( '「自動」是建議值：只有一位可服務人員時，每個時段都掛「剩 1 位」只是雜訊。想營造熱門感可以選「一律顯示」，不想讓客人看出人力規模就選「一律不顯示」。', 'ultimate-appointments' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( '服務人員選擇器', 'ultimate-appointments' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="show_staff_selector" value="1" <?php checked( $show_staff, true ); ?> />
							<?php esc_html_e( '讓客人在商品頁指定服務人員', 'ultimate-appointments' ); ?>
						</label>
						<p class="description"><?php esc_html_e( '關閉後，所有預約一律由系統自動安排人員，人員的「指定加價」也不會生效。', 'ultimate-appointments' ); ?></p>
					</td>
				</tr>
				<?php
				// ⚠️ 這一列跟著 staff_portal 模組走，但**欄位本身不能拿掉**——
				// handle_save_settings() 是「每個 option 無條件寫入」的，欄位沒送
				// 上來就會被寫成預設值。模組關著時把整列藏起來、欄位留在 DOM 裡，
				// 重新啟用時使用者原本的選擇還在（跟降級的總原則一致）。
				?>
				<tr<?php echo UAPPT_Modules::enabled( 'staff_portal' ) ? '' : ' hidden'; ?>>
					<th><?php esc_html_e( '員工中心的業績金額', 'ultimate-appointments' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="staff_portal_show_amount" value="1" <?php checked( $staff_portal_show_amount, true ); ?> />
							<?php esc_html_e( '讓員工在「本月明細」看到自己的業績金額', 'ultimate-appointments' ); ?>
						</label>
						<p class="description"><?php esc_html_e( '抽成制的店家通常需要打開（員工自己對得起來）；不想讓員工看到單價與每日業績就關掉，關掉後「本月明細」只顯示時間、客人與狀態。這個設定不影響後台報表。', 'ultimate-appointments' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><label for="uappt-wizard-after-add"><?php esc_html_e( '預約送出後前往', 'ultimate-appointments' ); ?></label></th>
					<td>
						<select id="uappt-wizard-after-add" name="wizard_after_add">
							<option value="cart" <?php selected( $wizard_after_add, 'cart' ); ?>><?php esc_html_e( '購物車頁（可折抵點數、用優惠券、加購）', 'ultimate-appointments' ); ?></option>
							<option value="checkout" <?php selected( $wizard_after_add, 'checkout' ); ?>><?php esc_html_e( '直接到結帳頁（少一個步驟）', 'ultimate-appointments' ); ?></option>
						</select>
						<p class="description">
							<?php esc_html_e( '「購物車頁」是建議值。點數折抵、優惠券、加購商品這些介面，絕大多數電商外掛都只掛在購物車頁——跳過那一頁，客人就完全用不到，結帳頁往往只剩「本次使用 N 點」這種唯讀顯示。', 'ultimate-appointments' ); ?>
							<br />
							<?php esc_html_e( '店裡沒有在用優惠券或點數，才建議選「直接到結帳頁」：那種情況下購物車頁確實只是多按一次。', 'ultimate-appointments' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th><label for="uappt-product-page-mode"><?php esc_html_e( '商品頁的預約介面', 'ultimate-appointments' ); ?></label></th>
					<td>
						<select id="uappt-product-page-mode" name="product_page_mode">
							<option value="classic" <?php selected( $product_page_mode, 'classic' ); ?>><?php esc_html_e( '傳統：商品頁內嵌的預約表單', 'ultimate-appointments' ); ?></option>
							<option value="wizard" <?php selected( $product_page_mode, 'wizard' ); ?>><?php esc_html_e( '精靈：商品頁改用步驟式預約介面', 'ultimate-appointments' ); ?></option>
							<option value="intro" <?php selected( $product_page_mode, 'intro' ); ?>><?php esc_html_e( '純介紹頁：商品頁不放預約，只留「立即預約」按鈕', 'ultimate-appointments' ); ?></option>
						</select>
						<p class="description">
							<?php esc_html_e( '「傳統」是既有行為。「精靈」會把商品頁的表單換成步驟式介面，並自動限定成這個商品；商品有多個服務方案時，客人仍然可以在精靈裡選方案。「純介紹頁」適合把商品頁當成服務說明，預約統一集中到預約頁。', 'ultimate-appointments' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th><label for="uappt-booking-page"><?php esc_html_e( '預約頁面', 'ultimate-appointments' ); ?></label></th>
					<td>
						<?php
						wp_dropdown_pages(
							array(
								'id'               => 'uappt-booking-page',
								'name'             => 'booking_page_id',
								'selected'         => (int) $booking_page_id,
								'show_option_none' => __( '自動偵測', 'ultimate-appointments' ),
								'option_none_value' => 0,
							)
						);
						?>
						<p class="description">
							<?php esc_html_e( '「純介紹頁」模式的按鈕要導去哪一頁。留在「自動偵測」時，系統會自動找站上第一個放了預約精靈的頁面。', 'ultimate-appointments' ); ?>
							<?php
							$uappt_detected = UAPPT_Product::booking_page_url();
							if ( '' !== $uappt_detected ) {
								echo '<br />';
								printf(
									/* translators: %s: 網址 */
									esc_html__( '目前會導向：%s', 'ultimate-appointments' ),
									'<code>' . esc_html( $uappt_detected ) . '</code>'
								);
							} else {
								echo '<br />';
								esc_html_e( '⚠️ 目前偵測不到任何放了預約精靈的頁面，「純介紹頁」模式不會顯示按鈕。', 'ultimate-appointments' );
							}
							?>
						</p>
					</td>
				</tr>
			</table>

			</div>

			<div class="uappt-panel">
				<?php UAPPT_Admin::panel_head( 'tag', __( '時段分類（前台篩選頁籤）', 'ultimate-appointments' ) ); ?>
				<div class="uappt-panel-body">
					<p class="description">
						<?php esc_html_e( '客人在商品頁選好日期後，會先看到這些分類頁籤，點選後才展開該分類底下的詳細時間，避免一次列出滿滿的時段按鈕。這只影響前台怎麼分組顯示，不會改變實際可預約的時段；某個分類當天沒有時段時，前台不會顯示該頁籤。', 'ultimate-appointments' ); ?>
					</p>
					<p class="description">
						<?php esc_html_e( '⚠️ 這是整間店共用一組（v2.55.0 從每位人員身上搬過來）。舊版每位人員各自存一份，但實際生效的只有系統碰巧排在第一位的那位——兩位人員的分界時間不一樣時，同一個 18:30 會因為排序而被標成「下午」或「晚間」。「上午／下午／晚間」是店對客人講話的用語，不是某位員工的屬性。', 'ultimate-appointments' ); ?>
					</p>
					<table class="uappt-segments-table">
						<thead>
							<tr>
								<th><?php esc_html_e( '分類名稱', 'ultimate-appointments' ); ?></th>
								<th><?php esc_html_e( '涵蓋範圍', 'ultimate-appointments' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<tr>
								<td>
									<input type="text" class="regular-text" name="segment_labels[0]" value="<?php echo esc_attr( $segment_labels[0] ); ?>" placeholder="<?php echo esc_attr( $segment_defaults['labels'][0] ); ?>" />
								</td>
								<td>
									<?php esc_html_e( '當天營業開始', 'ultimate-appointments' ); ?>
									&nbsp;～&nbsp;
									<input type="text" inputmode="numeric" autocomplete="off" class="uappt-time-input uappt-boundary-input" id="uappt-boundary-0" name="segment_boundaries[0]" value="<?php echo esc_attr( $segment_boundaries[0] ); ?>" placeholder="<?php echo esc_attr( $segment_defaults['boundaries'][0] ); ?>" />
								</td>
							</tr>
							<tr>
								<td>
									<input type="text" class="regular-text" name="segment_labels[1]" value="<?php echo esc_attr( $segment_labels[1] ); ?>" placeholder="<?php echo esc_attr( $segment_defaults['labels'][1] ); ?>" />
								</td>
								<td>
									<span class="uappt-boundary-mirror" data-mirror="0"><?php echo esc_html( $segment_boundaries[0] ); ?></span>
									&nbsp;～&nbsp;
									<input type="text" inputmode="numeric" autocomplete="off" class="uappt-time-input uappt-boundary-input" id="uappt-boundary-1" name="segment_boundaries[1]" value="<?php echo esc_attr( $segment_boundaries[1] ); ?>" placeholder="<?php echo esc_attr( $segment_defaults['boundaries'][1] ); ?>" />
								</td>
							</tr>
							<tr>
								<td>
									<input type="text" class="regular-text" name="segment_labels[2]" value="<?php echo esc_attr( $segment_labels[2] ); ?>" placeholder="<?php echo esc_attr( $segment_defaults['labels'][2] ); ?>" />
								</td>
								<td>
									<span class="uappt-boundary-mirror" data-mirror="1"><?php echo esc_html( $segment_boundaries[1] ); ?></span>
									&nbsp;～&nbsp;
									<?php esc_html_e( '當天營業結束', 'ultimate-appointments' ); ?>
								</td>
							</tr>
						</tbody>
					</table>
				</div>
			</div>

			<?php require UAPPT_PLUGIN_DIR . 'includes/views/settings-wizard-usage.php'; ?>
		</div>

		<div<?php uappt_tab_pane( 'notify', $current_tab ); ?>>
			<div class="uappt-panel">
				<?php UAPPT_Admin::panel_head( 'bell', __( '預約提醒', 'ultimate-appointments' ) ); ?>
			<div class="uappt-line-status">
				<?php if ( $line_ready ) : ?>
					<p>
						<strong><?php esc_html_e( 'LINE 推播：可用', 'ultimate-appointments' ); ?></strong>
						<?php if ( $has_notify_addon ) : ?>
							<?php esc_html_e( '（沿用「終極登入」外掛已設定的 Channel Access Token，不需要重複填寫）', 'ultimate-appointments' ); ?>
						<?php endif; ?>
					</p>
				<?php else : ?>
					<p class="uappt-hint">
						<strong><?php esc_html_e( 'LINE 推播：尚未可用。', 'ultimate-appointments' ); ?></strong>
						<?php esc_html_e( '找不到 Channel Access Token。請確認「終極登入」外掛已啟用並設定完成。未設定時，提醒會改用 Email 發送。', 'ultimate-appointments' ); ?>
					</p>
				<?php endif; ?>

				<p class="description">
					<?php esc_html_e( '注意事項：推播給客人的每一則訊息都會計入 LINE 官方帳號的訊息額度，且與既有的訂單通知共用同一份額度，超量需付費。客人必須已加入官方帳號好友才收得到；未綁定 LINE、已封鎖、或客人自行在「帳號綁定」頁關閉了 LINE 通知的情況，系統會自動改寄 Email。', 'ultimate-appointments' ); ?>
				</p>
			</div>

			<table class="form-table">
				<tr>
					<th><?php esc_html_e( '啟用的提醒', 'ultimate-appointments' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="reminder_customer_enabled" value="1" <?php checked( $reminder['customer_enabled'], 1 ); ?> />
							<?php esc_html_e( '前一天提醒客人', 'ultimate-appointments' ); ?>
						</label>
						<br />
						<label>
							<input type="checkbox" name="reminder_shop_enabled" value="1" <?php checked( $reminder['shop_enabled'], 1 ); ?> />
							<?php esc_html_e( '每天發送「明日預約清單」到店家 LINE 群組', 'ultimate-appointments' ); ?>
						</label>
						<?php if ( empty( $shop_targets ) ) : ?>
							<p class="description">
								<?php esc_html_e( '目前沒有可用的店家群組。請在「終極登入」外掛設定群組。', 'ultimate-appointments' ); ?>
							</p>
						<?php else : ?>
							<p class="description">
								<?php
								printf(
									/* translators: %d: 群組數量 */
									esc_html__( '目前會發送到 %d 個已設定的群組/對象。', 'ultimate-appointments' ),
									count( $shop_targets )
								);
								?>
							</p>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th><label for="uappt-reminder-time"><?php esc_html_e( '前一天的發送時間', 'ultimate-appointments' ); ?></label></th>
					<td>
						<input type="text" inputmode="numeric" autocomplete="off" class="uappt-time-input" id="uappt-reminder-time" name="reminder_send_time" value="<?php echo esc_attr( $reminder['send_time'] ); ?>" placeholder="20:00" />
						<p class="description"><?php esc_html_e( '例如填 20:00，表示每天晚上 8 點之後，把「明天」有預約的客人通知一輪。由於排程依賴網站流量觸發，實際發送時間可能略晚幾分鐘，但不會漏掉。', 'ultimate-appointments' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( '服務前提醒', 'ultimate-appointments' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="reminder2_enabled" value="1" <?php checked( $reminder['hour_enabled'], 1 ); ?> />
							<?php esc_html_e( '額外在服務開始前幾小時再提醒一次', 'ultimate-appointments' ); ?>
						</label>
						<p class="description"><?php esc_html_e( '跟上面「前一天」的提醒是完全獨立的兩則，各自判斷有沒有發送過，不會互相取代。適合臨到當天才容易被忘記的服務。', 'ultimate-appointments' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><label for="uappt-reminder2-hours"><?php esc_html_e( '提前幾小時發送', 'ultimate-appointments' ); ?></label></th>
					<td>
						<input type="number" id="uappt-reminder2-hours" name="reminder2_hours" min="0" step="1" value="<?php echo esc_attr( $reminder['hour_before_hours'] ); ?>" class="uappt-input-num" />
						<p class="description"><?php esc_html_e( '例如填 3，代表服務開始前 3 小時內會發送這則提醒（排程每 5 分鐘檢查一次，實際發送時間可能略早於整點）。沒有勾選上面的開關時，這個設定不會生效。', 'ultimate-appointments' ); ?></p>
					</td>
				</tr>
			</table>

			<div class="uappt-card-settings">
				<table class="form-table uappt-card-settings-fields">
					<tr>
						<th><label for="uappt-card-header-color"><?php esc_html_e( '卡片表頭顏色', 'ultimate-appointments' ); ?></label></th>
						<td>
							<input type="color" id="uappt-card-header-color" name="card_style[header_color]" value="<?php echo esc_attr( $card_style['header_color'] ); ?>" />
							<p class="description"><?php esc_html_e( '表頭底色與按鈕同色。預設是 LINE 的品牌綠——卡片出現在 LINE 的對話串裡，跟著 LINE 的視覺走比較不突兀。', 'ultimate-appointments' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label for="uappt-card-greeting"><?php esc_html_e( '問候語', 'ultimate-appointments' ); ?></label></th>
						<td>
							<input type="text" id="uappt-card-greeting" name="card_style[greeting]" value="<?php echo esc_attr( $card_style['greeting'] ); ?>" class="regular-text" />
							<p class="description">
								<?php esc_html_e( '三則通知共用。可用變數：', 'ultimate-appointments' ); ?>
								<code>{customer_name}</code> <code>{shop_name}</code>
							</p>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( '各則的標題', 'ultimate-appointments' ); ?></th>
						<td>
							<p>
								<label class="uappt-card-title-row">
									<span><?php esc_html_e( '前一天提醒', 'ultimate-appointments' ); ?></span>
									<input type="text" id="uappt-card-title-day" name="card_style[title_day]" value="<?php echo esc_attr( $card_style['title_day'] ); ?>" />
								</label>
							</p>
							<p>
								<label class="uappt-card-title-row">
									<span><?php esc_html_e( '服務前提醒', 'ultimate-appointments' ); ?></span>
									<input type="text" id="uappt-card-title-hour" name="card_style[title_hour]" value="<?php echo esc_attr( $card_style['title_hour'] ); ?>" />
								</label>
							</p>
							<p>
								<label class="uappt-card-title-row">
									<span><?php esc_html_e( '服務人員異動', 'ultimate-appointments' ); ?></span>
									<input type="text" id="uappt-card-title-staff" name="card_style[title_staff]" value="<?php echo esc_attr( $card_style['title_staff'] ); ?>" />
								</label>
							</p>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( '按鈕文字', 'ultimate-appointments' ); ?></th>
						<td>
							<p>
								<label class="uappt-card-title-row">
									<span><?php esc_html_e( '一般情況', 'ultimate-appointments' ); ?></span>
									<input type="text" id="uappt-card-button" name="card_style[button_text]" value="<?php echo esc_attr( $card_style['button_text'] ); ?>" />
								</label>
							</p>
							<p>
								<label class="uappt-card-title-row">
									<span><?php esc_html_e( '待付款時', 'ultimate-appointments' ); ?></span>
									<input type="text" id="uappt-card-pay-button" name="card_style[pay_button_text]" value="<?php echo esc_attr( $card_style['pay_button_text'] ); ?>" />
								</label>
							</p>
							<p class="description"><?php esc_html_e( '線上付款尚未完成（ATM、超商代碼、轉帳）時，按鈕會換成付款連結。收現金的預約不受影響，仍然是行事曆。', 'ultimate-appointments' ); ?></p>
						</td>
					</tr>
				</table>

				<div class="uappt-card-preview-pane">
					<p class="uappt-card-preview-head">
						<label for="uappt-card-preview-kind"><?php esc_html_e( '預覽', 'ultimate-appointments' ); ?></label>
						<select id="uappt-card-preview-kind">
							<?php foreach ( UAPPT_Card::sample_kinds() as $uappt_kind => $uappt_kind_label ) : ?>
								<option value="<?php echo esc_attr( $uappt_kind ); ?>"><?php echo esc_html( $uappt_kind_label ); ?></option>
							<?php endforeach; ?>
						</select>
					</p>
					<div id="uappt-card-preview" class="uappt-card-preview"></div>
					<p class="description"><?php esc_html_e( '用範例資料即時重繪，實際排版以 LINE App 顯示為準。LINE 不可用時會改寄 Email，內容相同但沒有卡片外框。', 'ultimate-appointments' ); ?></p>
				</div>
			</div>

			</div>
		</div>

		<div<?php uappt_tab_pane( 'rules payment display notify', $current_tab ); ?>>
			<?php submit_button( __( '儲存設定', 'ultimate-appointments' ) ); ?>
		</div>
	</form>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"<?php uappt_tab_pane( 'notify', $current_tab, 'uappt-form' ); ?>>
		<input type="hidden" name="action" value="uappt_test_line" />
		<?php wp_nonce_field( 'uappt_test_line' ); ?>
		<div class="uappt-panel">
			<?php UAPPT_Admin::panel_head( 'bell', __( '測試發送', 'ultimate-appointments' ) ); ?>
			<div class="uappt-panel-body">
				<p class="description">
					<?php esc_html_e( '填入一組 LINE userId 或群組 ID，用範例資料送出一張真的卡片，直接顯示 LINE 回傳的結果。設定有問題時，這是最快找出原因的方式，也是唯一能在手機上看到實際排版的方法。', 'ultimate-appointments' ); ?>
				</p>
				<p class="description">
					<?php esc_html_e( '送出的是「已儲存」的卡片樣式：剛改完顏色或文字要先按上面的「儲存設定」，測試才會用到新的。卡片標題會多一個「（測試）」，避免送到員工群組時被當成真的預約。', 'ultimate-appointments' ); ?>
				</p>
			</div>
		<table class="form-table">
			<tr>
				<th><label for="uappt-test-target"><?php esc_html_e( '收件 ID', 'ultimate-appointments' ); ?></label></th>
				<td>
					<input type="text" id="uappt-test-target" name="test_target" class="regular-text" placeholder="Uxxxxxxxx / Cxxxxxxxx" />
					<p class="description"><?php esc_html_e( '個人的 userId 以 U 開頭、群組 ID 以 C 開頭。', 'ultimate-appointments' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="uappt-test-kind"><?php esc_html_e( '卡片類型', 'ultimate-appointments' ); ?></label></th>
				<td>
					<select id="uappt-test-kind" name="test_kind">
						<?php foreach ( UAPPT_Card::sample_kinds() as $uappt_kind => $uappt_kind_label ) : ?>
							<option value="<?php echo esc_attr( $uappt_kind ); ?>"><?php echo esc_html( $uappt_kind_label ); ?></option>
						<?php endforeach; ?>
					</select>
					<p class="description"><?php esc_html_e( '跟右邊預覽的四種一樣。「待付款」的按鈕文字與付款那兩列跟其他三種不同，要驗那個差異就選它。', 'ultimate-appointments' ); ?></p>
				</td>
			</tr>
		</table>
		</div>
		<?php submit_button( __( '送出測試卡片', 'ultimate-appointments' ), 'secondary' ); ?>
	</form>

	<div<?php uappt_tab_pane( 'tools', $current_tab ); ?>>
		<div class="uappt-panel">
			<?php UAPPT_Admin::panel_head( 'package', __( '商品類型轉換（進階，風險較高）', 'ultimate-appointments' ) ); ?>
			<div class="uappt-panel-body">
		<div class="uappt-hint">
			<p>
				<?php esc_html_e( '「預約商品」是把服務直接做成一種 WooCommerce 商品類型：商品編輯頁會多一個「預約設定」分頁，可以建立多個服務方案（名稱／時長／價格），並自動鎖定為虛擬商品（不出貨）。', 'ultimate-appointments' ); ?>
			</p>
			<p>
				<strong><?php esc_html_e( '上線前請務必先在測試站驗證：', 'ultimate-appointments' ); ?></strong>
				<?php esc_html_e( '如果網站上還有其他外掛（例如電商模組）或佈景主題客製化程式碼是用嚴格比對商品類型（而不是 WooCommerce 建議的 is_type() 判斷方式）在判斷「是不是簡單商品／可變商品」，轉換後這些判斷可能會失效。請先在測試站把商品轉換過去，完整跑一次「加入購物車 → 結帳 → 金流付款 → 訂單建立」都正常，再轉換正式站的商品。', 'ultimate-appointments' ); ?>
			</p>
			<p>
				<?php esc_html_e( '轉換與還原都只會變更商品類型本身，不會動到任何訂單、預約紀錄或已建立的服務方案，隨時可以轉回去重試。', 'ultimate-appointments' ); ?>
			</p>
		</div>

		<h3><?php esc_html_e( '轉換為「預約商品」類型', 'ultimate-appointments' ); ?></h3>
		<?php if ( empty( $convertible_ids ) ) : ?>
			<p class="description"><?php esc_html_e( '目前沒有可轉換的商品（需要是已勾選「啟用預約」的簡單或可變商品，且尚未是「預約商品」類型）。', 'ultimate-appointments' ); ?></p>
		<?php else : ?>
			<p>
				<?php
				printf(
					/* translators: %d: 可轉換的商品數量 */
					esc_html__( '以下 %d 個商品符合轉換條件：', 'ultimate-appointments' ),
					count( $convertible_ids )
				);
				?>
			</p>
			<ul class="uappt-product-type-list">
				<?php foreach ( $convertible_ids as $pid ) : ?>
					<li><a href="<?php echo esc_url( admin_url( 'post.php?post=' . (int) $pid . '&action=edit' ) ); ?>"><?php echo esc_html( get_the_title( $pid ) ); ?></a></li>
				<?php endforeach; ?>
			</ul>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php echo esc_js( sprintf( __( '確定要把上面列出的 %d 個商品轉換為「預約商品」類型嗎？建議先在測試站驗證過結帳流程沒問題再執行。', 'ultimate-appointments' ), count( $convertible_ids ) ) ); ?>');">
				<input type="hidden" name="action" value="uappt_convert_to_booking_type" />
				<?php wp_nonce_field( 'uappt_convert_to_booking_type' ); ?>
				<?php submit_button( __( '一鍵轉換', 'ultimate-appointments' ), 'secondary' ); ?>
			</form>
		<?php endif; ?>

		<h3><?php esc_html_e( '還原為原本的商品類型', 'ultimate-appointments' ); ?></h3>
		<?php if ( empty( $converted_ids ) ) : ?>
			<p class="description"><?php esc_html_e( '目前沒有「預約商品」類型的商品。', 'ultimate-appointments' ); ?></p>
		<?php else : ?>
			<p>
				<?php
				printf(
					/* translators: %d: 目前是預約商品類型的商品數量 */
					esc_html__( '目前有 %d 個商品是「預約商品」類型：', 'ultimate-appointments' ),
					count( $converted_ids )
				);
				?>
			</p>
			<ul class="uappt-product-type-list">
				<?php foreach ( $converted_ids as $pid ) : ?>
					<li><a href="<?php echo esc_url( admin_url( 'post.php?post=' . (int) $pid . '&action=edit' ) ); ?>"><?php echo esc_html( get_the_title( $pid ) ); ?></a></li>
				<?php endforeach; ?>
			</ul>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php echo esc_js( sprintf( __( '確定要把上面列出的 %d 個商品還原為原本的商品類型嗎？', 'ultimate-appointments' ), count( $converted_ids ) ) ); ?>');">
				<input type="hidden" name="action" value="uappt_revert_booking_type" />
				<?php wp_nonce_field( 'uappt_revert_booking_type' ); ?>
				<?php submit_button( __( '一鍵還原', 'ultimate-appointments' ), 'secondary' ); ?>
			</form>
		<?php endif; ?>
			</div>
		</div>
	</div>

<?php
// ── 功能模組（v2.41.0）─────────────────────────────────────────────
//
// ⚠️ 這個頁籤只對 manage_options 出現（見 UAPPT_Admin::settings_tabs()），而且
// **不屬於上面那張設定表單**——它有自己的 <form> 與自己的 admin-post action。
// 混進去的話 handle_save_settings() 會把它的欄位當成一般設定處理，而那支是
// 「每個 option 無條件寫入」的，模組狀態會被沒有權限的人一起送出。
//
// 非 manage_options 的使用者連 $tabs 裡都沒有 'modules' 這個 key，
// sanitize_settings_tab() 會把 ?tab=modules 退回第一個頁籤，所以這一段
// 其實畫不出來；這裡再檢查一次是第二道防線，成本只有一行。
?>
<?php if ( isset( $tabs['modules'] ) ) : ?>
	<div<?php uappt_tab_pane( 'modules', $current_tab ); ?>>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="uappt-modules-form">
			<input type="hidden" name="action" value="uappt_save_modules" />
			<?php wp_nonce_field( 'uappt_save_modules' ); ?>

			<?php
			$uappt_modules = UAPPT_Modules::definitions();
			$uappt_state   = UAPPT_Modules::settings();
			$uappt_tiers   = UAPPT_Modules::tier_presets();

			$uappt_by_tier = array();
			foreach ( $uappt_modules as $uappt_key => $uappt_mod ) {
				$uappt_by_tier[ $uappt_mod['tier'] ][ $uappt_key ] = $uappt_mod;
			}
			?>

			<div class="uappt-panel">
				<?php UAPPT_Admin::panel_head( 'settings', __( '功能模組', 'ultimate-appointments' ) ); ?>
				<div class="uappt-panel-body">
					<p class="uappt-page-desc">
						<?php esc_html_e( '關掉的功能只是隱藏起來，資料完全保留在資料庫裡——耗材的異動流水帳、客人的聯絡紀錄都還在，重新打開就全部回來。', 'ultimate-appointments' ); ?>
					</p>

					<?php
					// 目前方案：**只是顯示**，不是控制項。它是從下面那些開關反推
					// 出來的（UAPPT_Modules::current_tier()），所以永遠跟開關
					// 一致，不可能出現「這裡說專業版、開關卻是旗艦」的狀態。
					?>
					<?php
					// 方案名稱做成徽章、加購的部分做成一般文字，兩段分開輸出——
					// 不是為了好看而已：JS 要能各自更新，而「基準層級」跟「加購
					// 了什麼」本來就是兩件事。
					$uappt_tier_now  = UAPPT_Modules::current_tier();
					$uappt_tier_name = $uappt_tiers[ $uappt_tier_now ]['label'];
					$uappt_extras    = array_diff(
						array_keys( array_filter( $uappt_state ) ),
						$uappt_tiers[ $uappt_tier_now ]['modules']
					);
					$uappt_extra_names = array();
					foreach ( $uappt_extras as $uappt_ek ) {
						if ( isset( $uappt_modules[ $uappt_ek ] ) ) {
							$uappt_extra_names[] = $uappt_modules[ $uappt_ek ]['label'];
						}
					}
					?>
					<p class="uappt-module-summary">
						<span class="uappt-module-summary-label"><?php esc_html_e( '目前方案', 'ultimate-appointments' ); ?></span>
						<span class="uappt-tier-badge" data-uappt-tier-badge><?php echo esc_html( $uappt_tier_name ); ?></span>
						<span class="uappt-tier-extra" data-uappt-tier-extra>
							<?php
							if ( $uappt_extra_names ) {
								printf(
									/* translators: %s: 額外加購的模組名稱 */
									esc_html__( '＋ 加購 %s', 'ultimate-appointments' ),
									esc_html( implode( '、', $uappt_extra_names ) )
								);
							}
							?>
						</span>
						<span class="uappt-module-dirty" data-uappt-dirty hidden><?php esc_html_e( '有未儲存的變更', 'ultimate-appointments' ); ?></span>
					</p>

					<?php
					// 群組的總開關跟它底下的模組開關**對齊在同一欄**，用的也是同一個
					// 滑桿元件——整頁只有一種控制項的形狀。
					//
					// ⚠️ 它是「這一組全開／全關」，**不是累加的方案層級**。專業版開
					// 不開跟旗艦版無關，所以不會有「旗艦版打開時專業版算開還是關」
					// 那個答不出來的問題。累加的層級只用在最上面那行顯示。
					//
					// 只開了組內一部分時是 indeterminate（半開），由 JS 設定
					// ——那是 DOM 屬性，沒有對應的 HTML attribute 可以直接印出來。
					// 「基礎版」沒有自己的群組：它一個模組都沒有，全部關掉就是基礎版。
					?>
					<?php foreach ( $uappt_tiers as $uappt_tier_key => $uappt_tier ) : ?>
						<?php if ( empty( $uappt_by_tier[ $uappt_tier_key ] ) ) : ?>
							<?php continue; ?>
						<?php endif; ?>
						<?php
						$uappt_group_keys = array_keys( $uappt_by_tier[ $uappt_tier_key ] );
						$uappt_group_on   = array_filter(
							$uappt_group_keys,
							function ( $k ) use ( $uappt_state ) {
								return ! empty( $uappt_state[ $k ] );
							}
						);
						$uappt_gid = 'uappt-group-' . $uappt_tier_key;
						?>
						<div class="uappt-module-row is-group">
							<label class="uappt-toggle">
								<input
									type="checkbox"
									id="<?php echo esc_attr( $uappt_gid ); ?>"
									data-uappt-group="<?php echo esc_attr( $uappt_tier_key ); ?>"
									<?php checked( count( $uappt_group_on ) === count( $uappt_group_keys ) ); ?>
								/>
								<span class="uappt-slider"></span>
							</label>
							<label class="uappt-module-label" for="<?php echo esc_attr( $uappt_gid ); ?>"><?php echo esc_html( $uappt_tier['label'] ); ?></label>
							<span class="uappt-module-desc"><?php echo esc_html( $uappt_tier['desc'] ); ?></span>
						</div>

						<?php foreach ( $uappt_by_tier[ $uappt_tier_key ] as $uappt_key => $uappt_mod ) : ?>
							<?php $uappt_id = 'uappt-module-' . $uappt_key; ?>
							<div class="uappt-module-row" data-uappt-tier="<?php echo esc_attr( $uappt_tier_key ); ?>">
								<label class="uappt-toggle">
									<input
										type="checkbox"
										id="<?php echo esc_attr( $uappt_id ); ?>"
										name="modules[<?php echo esc_attr( $uappt_key ); ?>]"
										value="1"
										data-uappt-tier="<?php echo esc_attr( $uappt_tier_key ); ?>"
										<?php checked( ! empty( $uappt_state[ $uappt_key ] ) ); ?>
									/>
									<span class="uappt-slider"></span>
								</label>
								<label class="uappt-module-label" for="<?php echo esc_attr( $uappt_id ); ?>"><?php echo esc_html( $uappt_mod['label'] ); ?></label>
								<span class="uappt-module-desc"><?php echo esc_html( $uappt_mod['desc'] ); ?></span>
							</div>
						<?php endforeach; ?>
					<?php endforeach; ?>
				</div>
			</div>

			<?php submit_button( __( '儲存功能模組', 'ultimate-appointments' ) ); ?>
		</form>
	</div>
<?php endif; ?>
