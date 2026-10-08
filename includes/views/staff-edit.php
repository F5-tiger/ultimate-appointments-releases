<?php
/**
 * View：人員新增／編輯頁。
 *
 * 傳入變數：
 * - $editing   正在編輯的人員（新增時為 null）
 * - $form      要填入表單的值（正常情況等同 $editing；若上一次存檔驗證失敗，
 *              則是使用者上次送出的內容，避免打了一堆設定卻因為一個格式錯誤全部消失）
 *
 * @package Ultimate_Appointments
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$day_labels = array(
	'mon' => __( '週一', 'ultimate-appointments' ),
	'tue' => __( '週二', 'ultimate-appointments' ),
	'wed' => __( '週三', 'ultimate-appointments' ),
	'thu' => __( '週四', 'ultimate-appointments' ),
	'fri' => __( '週五', 'ultimate-appointments' ),
	'sat' => __( '週六', 'ultimate-appointments' ),
	'sun' => __( '週日', 'ultimate-appointments' ),
);

$global_interval = (int) get_option( 'uappt_slot_interval_minutes', 15 );

$is_24h     = ! empty( $form['is_24h'] );
$form_hours = isset( $form['business_hours'] ) && is_array( $form['business_hours'] ) ? $form['business_hours'] : array();

// 排班方式三選一（v2.93.0）。只有編輯頁有這張卡片（v2.96.2 起新增頁不問，見下面
// 卡片那裡的說明）。
$uappt_kind = $is_24h ? '24h' : ( isset( $form['schedule_mode'] ) ? UAPPT_Staff::sanitize_schedule_mode( $form['schedule_mode'] ) : '' );

// 剛建立完、第一次進到編輯頁：把「排班方式」打開。新增頁不問排班方式，這是第一次
// 看到它的地方——收著的話，固定班的店要自己想到「去那張卡片改成固定班」。
$uappt_just_created = $editing && isset( $_GET['uappt_notice'] ) && 'staff_created' === sanitize_key( wp_unslash( $_GET['uappt_notice'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

/*
 * 卡片標題列的摘要（v2.89.0）。
 *
 * ⚠️ **全部在這裡算完，不要散在各張卡片旁邊。** 摘要是「卡片收起來之後唯一看得到
 * 的東西」，散在頁面中段的話，日後改欄位很容易改了欄位卻忘了改摘要——而收合狀態下
 * 沒有人會發現摘要在說謊。
 */
$uappt_template_summary = '';
$uappt_month_label      = '';
$uappt_month_scheduled  = 0;
$blocks                = array();

// 固定班樣板：「每週幾天」＋「時段」。七天時段不一致時不硬湊一句話——說「時段不一」
// 比挑其中一天的時間當代表誠實。
$uappt_tpl_days   = 0;
$uappt_tpl_shapes = array();
foreach ( UAPPT_Staff::WEEKDAY_KEYS as $uappt_tpl_key ) {
	$uappt_tpl_ranges = isset( $form_hours[ $uappt_tpl_key ] ) && is_array( $form_hours[ $uappt_tpl_key ] ) ? $form_hours[ $uappt_tpl_key ] : array();
	if ( ! empty( $uappt_tpl_ranges ) ) {
		$uappt_tpl_days++;
		$uappt_tpl_shapes[ UAPPT_Shift_Preset::format_ranges( $uappt_tpl_ranges ) ] = true;
	}
}
if ( $uappt_tpl_days ) {
	$uappt_template_summary = UAPPT_Admin::card_summary(
		array(
			sprintf(
				/* translators: %d: 一週上班幾天 */
				__( '每週 %d 天', 'ultimate-appointments' ),
				$uappt_tpl_days
			),
			1 === count( $uappt_tpl_shapes ) ? key( $uappt_tpl_shapes ) : __( '時段不一', 'ultimate-appointments' ),
		)
	);
}

if ( $editing ) {
	$uappt_month_dt    = date_create( $batch_calendar['month'] . '-01', wp_timezone() );
	$uappt_month_label = $uappt_month_dt ? wp_date( 'Y 年 n 月', $uappt_month_dt->getTimestamp() ) : $batch_calendar['month'];

	// 「已排 N 天」數的是**真的有上班時段**的日子，不是「有資料列」的日子——例休
	// 與請假也有列，把它們算進去會讓「已排 30 天」在一個整月排休的人身上也成立。
	foreach ( $batch_calendar['days'] as $uappt_month_day ) {
		if ( $uappt_month_day['is_in_month'] && ! empty( $uappt_month_day['ranges'] ) ) {
			$uappt_month_scheduled++;
		}
	}

	$blocks = UAPPT_Booking::get_upcoming_blocks( $editing['id'] );
}
?>

	<?php
	// ⚠️ **新增與編輯的表單結構不一樣，這是刻意的。**
	//
	// 新增：還不存在的紀錄沒辦法分批存，所以三張設定卡片共用一個表單、一顆
	// 「建立人員」——而且第一次填本來就是一口氣填完。
	// 編輯：一張卡片一個表單一顆鈕（鈕在卡片裡），範圍由卡片的標題定義。
	//
	// 做法是「編輯時不開這個外層表單」，各張卡片自己開（staff_card_form_open()）。
	if ( ! $editing ) :
		?>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="uappt-form">
		<input type="hidden" name="action" value="uappt_save_staff" />
		<input type="hidden" name="staff_id" value="0" />
		<?php wp_nonce_field( 'uappt_save_staff' ); ?>
	<?php endif; ?>

		<?php
		// ⚠️ **原本這裡是一張 12 個欄位的「基本資料」面板。** 盤點之後發現它裝了
		// 四件完全不同的事（這個人是誰／客人怎麼約他／錢怎麼算／班表），所以拆開。
		// 計畫與理由見 docs/staff-admin-v4-plan.md 的 D2。
		//
		// 卡片收合用原生 <details>，理由見 UAPPT_Admin::card_open() 的 ⚠️。
		//
		// **新增人員時全部展開**：那是第一次填，收起來等於要人先猜到欄位在哪裡。
		// 既有人員預設收合，標題列的摘要就看得完。
		$uappt_card_open = ! $editing;
		?>
		<?php
		UAPPT_Admin::card_open(
			array(
				'icon'    => 'user',
				'title'   => __( '基本資料', 'ultimate-appointments' ),
				'summary' => $editing ? UAPPT_Admin::card_summary(
					array(
						$form['name'],
						'active' === $form['status'] ? __( '啟用中', 'ultimate-appointments' ) : __( '已停用', 'ultimate-appointments' ),
					)
				) : '',
				'open'    => $uappt_card_open,
			)
		);
		?>
		<?php if ( $editing ) { UAPPT_Admin::staff_card_form_open( 'basic', $editing['id'] ); } ?>
		<table class="form-table">
			<tr>
				<th><label for="uappt-name"><?php esc_html_e( '姓名', 'ultimate-appointments' ); ?></label></th>
				<td>
					<input type="text" id="uappt-name" name="name" class="regular-text" required value="<?php echo esc_attr( isset( $form['name'] ) ? $form['name'] : '' ); ?>" />
					<p class="description"><?php esc_html_e( '會顯示在前台的服務人員下拉選單中，例如：王老師。', 'ultimate-appointments' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label><?php esc_html_e( '照片', 'ultimate-appointments' ); ?></label></th>
				<td>
					<?php
					$photo_id  = isset( $form['photo_id'] ) ? (int) $form['photo_id'] : 0;
					$photo_url = $photo_id ? wp_get_attachment_image_url( $photo_id, 'thumbnail' ) : '';
					?>
					<div class="uappt-photo-field" data-has-photo="<?php echo $photo_url ? '1' : '0'; ?>">
						<input type="hidden" id="uappt-photo-id" name="photo_id" value="<?php echo esc_attr( $photo_id ); ?>" />
						<div class="uappt-photo-preview">
							<?php if ( $photo_url ) : ?>
								<img src="<?php echo esc_url( $photo_url ); ?>" alt="" />
							<?php endif; ?>
						</div>
						<p>
							<button type="button" class="button uappt-photo-select">
								<?php echo $photo_url ? esc_html__( '更換照片', 'ultimate-appointments' ) : esc_html__( '選擇照片', 'ultimate-appointments' ); ?>
							</button>
							<button type="button" class="button-link uappt-photo-remove" <?php echo $photo_url ? '' : 'style="display:none;"'; ?>>
								<?php esc_html_e( '移除', 'ultimate-appointments' ); ?>
							</button>
						</p>
					</div>
					<?php
					UAPPT_Admin::help(
						array(
							__( '前台的人員卡片會把這張照片裁成圓形大頭貼。建議用正方形、人臉置中的照片，短邊至少 300px——四個角會被裁掉，人臉偏一邊的照片會切到。', 'ultimate-appointments' ),
							__( '留空則只顯示姓名。', 'ultimate-appointments' ),
						)
					);
					?>
				</td>
			</tr>
			<tr>
				<th><label for="uappt-status"><?php esc_html_e( '狀態', 'ultimate-appointments' ); ?></label></th>
				<td>
					<?php $current_status = isset( $form['status'] ) ? $form['status'] : 'active'; ?>
					<select id="uappt-status" name="status">
						<option value="active" <?php selected( 'inactive' !== $current_status ); ?>><?php esc_html_e( '啟用中', 'ultimate-appointments' ); ?></option>
						<option value="inactive" <?php selected( 'inactive' === $current_status ); ?>><?php esc_html_e( '已停用（暫停所有預約，前台不會出現）', 'ultimate-appointments' ); ?></option>
					</select>
				</td>
			</tr>
			<tr>
				<th><label for="uappt-sort-order"><?php esc_html_e( '排序', 'ultimate-appointments' ); ?></label></th>
				<td>
					<input type="number" id="uappt-sort-order" name="sort_order" step="1" value="<?php echo esc_attr( isset( $form['sort_order'] ) ? $form['sort_order'] : 0 ); ?>" />
					<p class="description"><?php esc_html_e( '數字越小排越前面，決定前台人員下拉選單與商品編輯頁勾選清單的顯示順序。', 'ultimate-appointments' ); ?></p>
				</td>
			</tr>
		</table>
		<?php if ( $editing ) { UAPPT_Admin::staff_card_form_close(); } ?>
		<?php UAPPT_Admin::card_close(); ?>

		<?php
		UAPPT_Admin::card_open(
			array(
				'icon'    => 'settings',
				'title'   => __( '預約設定', 'ultimate-appointments' ),
				'summary' => $editing ? UAPPT_Admin::card_summary(
					array(
						sprintf( /* translators: %d: 同時可服務人數 */ __( '同時 %d 人', 'ultimate-appointments' ), max( 1, (int) $form['capacity'] ) ),
						! empty( $form['slot_interval'] )
							? sprintf( /* translators: %d: 分鐘 */ __( '%d 分鐘', 'ultimate-appointments' ), (int) $form['slot_interval'] )
							: sprintf( /* translators: %d: 分鐘 */ __( '預設 %d 分鐘', 'ultimate-appointments' ), $global_interval ),
						// ⚠️ **不要用 wc_price()**：它回傳的是一整包 HTML（含
						// <span class="woocommerce-Price-amount">），而摘要會過
						// esc_html()，結果是把標籤原封不動印在標題列上。摘要是純文字。
						(float) $form['price_adjustment'] > 0
							? sprintf(
								/* translators: 1: 貨幣符號 2: 金額 */
								__( '指定 +%1$s%2$s', 'ultimate-appointments' ),
								html_entity_decode( get_woocommerce_currency_symbol() ),
								number_format_i18n( (float) $form['price_adjustment'], 0 )
							)
							: '',
					)
				) : '',
				'open'    => $uappt_card_open,
			)
		);
		?>
		<?php if ( $editing ) { UAPPT_Admin::staff_card_form_open( 'booking', $editing['id'] ); } ?>
		<table class="form-table">
			<tr>
				<th><label for="uappt-capacity"><?php esc_html_e( '同時可服務人數', 'ultimate-appointments' ); ?></label></th>
				<td>
					<input type="number" id="uappt-capacity" name="capacity" min="1" step="1" value="<?php echo esc_attr( isset( $form['capacity'] ) ? $form['capacity'] : 1 ); ?>" />
					<p class="description"><?php esc_html_e( '這位人員在同一個時段最多能同時服務幾位客人。大多數服務是一對一，填 1 即可；若是能同時帶多人的課程或活動（例如瑜珈班），可以填實際的人數上限。', 'ultimate-appointments' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="uappt-slot-interval"><?php esc_html_e( '時間格顆粒（分鐘）', 'ultimate-appointments' ); ?></label></th>
				<td>
					<input
						type="number"
						id="uappt-slot-interval"
						name="slot_interval"
						min="5"
						step="5"
						placeholder="<?php echo esc_attr( $global_interval ); ?>"
						value="<?php echo esc_attr( ! empty( $form['slot_interval'] ) ? $form['slot_interval'] : '' ); ?>"
					/>
					<p class="description">
						<?php
						printf(
							/* translators: %d: 全站預設的時間格顆粒分鐘數 */
							esc_html__( '留空代表沿用全站預設值（目前是 %d 分鐘，可到「終極預約 → 設定」調整）。不同人員可以用不同顆粒，例如做臉部保養用 15 分鐘、做全身按摩用 30 分鐘。', 'ultimate-appointments' ),
							$global_interval
						);
						?>
					</p>
				</td>
			</tr>
			<tr>
				<th><label for="uappt-price-adjustment"><?php esc_html_e( '指定加價', 'ultimate-appointments' ); ?></label></th>
				<td>
					<input type="number" id="uappt-price-adjustment" name="price_adjustment" min="0" step="0.01" value="<?php echo esc_attr( isset( $form['price_adjustment'] ) ? $form['price_adjustment'] : 0 ); ?>" />
					<p class="description"><?php esc_html_e( '客人主動指定這位人員時加收的金額。填 0 代表不加價。', 'ultimate-appointments' ); ?></p>
					<?php
					UAPPT_Admin::help(
						array(
							__( '只有客人在前台**主動指定**時才加收（例如資深療程師 +200）。選「不指定（系統自動安排）」時就算剛好排到這位人員也不會加收。', 'ultimate-appointments' ),
							__( '金額只在下單當下寫入該筆訂單，之後調整不影響已成立的訂單。', 'ultimate-appointments' ),
						)
					);
					?>
				</td>
			</tr>
			<tr>
				<th><label for="uappt-user-id"><?php esc_html_e( '綁定的帳號', 'ultimate-appointments' ); ?></label></th>
				<td>
					<?php $current_user_id = isset( $form['user_id'] ) ? (int) $form['user_id'] : 0; ?>
					<select id="uappt-user-id" name="user_id">
						<option value="0"><?php esc_html_e( '不綁定', 'ultimate-appointments' ); ?></option>
						<?php foreach ( $linkable_users as $user ) : ?>
							<option value="<?php echo esc_attr( $user->ID ); ?>" <?php selected( $current_user_id, (int) $user->ID ); ?>>
								<?php echo esc_html( $user->display_name . ' (' . $user->user_email . ')' ); ?>
							</option>
						<?php endforeach; ?>
					</select>
					<p class="description">
						<?php esc_html_e( '綁定後這位人員可以在前台看到自己的班表、送出排班申請。', 'ultimate-appointments' ); ?>
					</p>
				</td>
			</tr>
		</table>
		<?php if ( $editing ) { UAPPT_Admin::staff_card_form_close(); } ?>
		<?php UAPPT_Admin::card_close(); ?>

		<?php
		// 抽成獨立一張（D2）：它一個人就佔了 8 段說明、2 個長警告，是整頁最重的
		// 一塊，而且**不是每家店都用**。收起來之後，不用抽成的店從此看不到它。
		// ⚠️ 來源是 $form['commission']，不是 $commission——後者不存在。第一版寫錯
		// 時沒有任何錯誤畫面，只是摘要永遠顯示「不抽成」（PHP 把未定義變數當 null，
		// 迴圈跑 0 次）。**摘要說謊比摘要壞掉難發現**，所以這裡用的是跟下面欄位
		// 完全同一支清洗過的資料。
		$uappt_comm_summary = isset( $form['commission'] ) && is_array( $form['commission'] )
			? UAPPT_Staff::sanitize_commission( $form['commission'] )
			: UAPPT_Staff::default_commission();
		$uappt_tier_count   = 0;
		foreach ( (array) $uappt_comm_summary['tiers'] as $uappt_tier ) {
			if ( '' !== (string) $uappt_tier['rate'] ) {
				$uappt_tier_count++;
			}
		}
		UAPPT_Admin::card_open(
			array(
				'icon'    => 'wallet',
				'title'   => __( '業績抽成', 'ultimate-appointments' ),
				'summary' => $editing ? (
					$uappt_tier_count
						? sprintf( /* translators: %d: 級距數 */ __( '%d 段級距', 'ultimate-appointments' ), $uappt_tier_count )
						: __( '不抽成', 'ultimate-appointments' )
				) : '',
				'open'    => $uappt_card_open,
			)
		);
		?>
		<?php if ( $editing ) { UAPPT_Admin::staff_card_form_open( 'commission', $editing['id'] ); } ?>
		<table class="form-table">
			<tr>
				<th><?php esc_html_e( '業績抽成', 'ultimate-appointments' ); ?></th>
				<td>
					<?php
					$uappt_comm = isset( $form['commission'] ) && is_array( $form['commission'] )
						? UAPPT_Staff::sanitize_commission( $form['commission'] )
						: UAPPT_Staff::default_commission();
					// 永遠多印一列空白供新增，跟收款方式設定同一個模式：沒有 JS
					// 時那一列就是唯一的新增方式。
					$uappt_tiers = $uappt_comm['tiers'];
					$uappt_tiers[] = array( 'from' => '', 'rate' => '' );
					?>
					<fieldset class="uappt-commission">
						<p>
							<label>
								<input type="radio" name="commission_mode" value="marginal" <?php checked( 'marginal', $uappt_comm['mode'] ); ?> />
								<?php esc_html_e( '累進（每一段各自套用自己的比例）', 'ultimate-appointments' ); ?>
							</label>
							<br />
							<label>
								<input type="radio" name="commission_mode" value="flat" <?php checked( 'flat', $uappt_comm['mode'] ); ?> />
								<?php esc_html_e( '全額適用（達到哪一級，整筆業績就用那一級的比例）', 'ultimate-appointments' ); ?>
							</label>
						</p>
						<p class="description">
							<?php esc_html_e( '⚠️ 兩種算法算出來差很多，請照實際跟人員談定的方式選。', 'ultimate-appointments' ); ?>
						</p>
						<?php
						UAPPT_Admin::help(
							array(
								__( '以「15 萬以下 10%、15～30 萬 15%」、當月一般業績 20 萬為例：**累進**是 15 萬×10% ＋ 5 萬×15% ＝ 22,500；**全額適用**是 20 萬×15% ＝ 30,000。', 'ultimate-appointments' ),
								__( '搭配 0% 門檻時差更多。以「0～20 萬 0%、20 萬以上 30%」、當月一般業績 25 萬為例：累進只抽超過門檻的 5 萬 ＝ 15,000；全額適用是達標後整筆 25 萬×30% ＝ 75,000。兩種都是真實存在的制度，別選錯。', 'ultimate-appointments' ),
							)
						);
						?>

						<table class="uappt-table uappt-commission-table">
							<thead>
								<tr>
									<th scope="col"><?php esc_html_e( '一般業績達到（含）', 'ultimate-appointments' ); ?></th>
									<th scope="col"><?php esc_html_e( '抽成比例', 'ultimate-appointments' ); ?></th>
									<th scope="col" class="uappt-tier-actions"><span class="screen-reader-text"><?php esc_html_e( '操作', 'ultimate-appointments' ); ?></span></th>
								</tr>
							</thead>
							<tbody class="uappt-tier-body">
								<?php foreach ( $uappt_tiers as $uappt_i => $uappt_tier ) : ?>
									<tr class="uappt-tier-row">
										<td data-label="<?php esc_attr_e( '一般業績達到（含）', 'ultimate-appointments' ); ?>">
											<input type="number" min="0" step="1" name="commission_tiers[<?php echo (int) $uappt_i; ?>][from]" value="<?php echo esc_attr( '' === $uappt_tier['from'] ? '' : (int) $uappt_tier['from'] ); ?>" placeholder="0" />
										</td>
										<td data-label="<?php esc_attr_e( '抽成比例', 'ultimate-appointments' ); ?>">
											<span class="uappt-pm-rate">
												<input type="number" min="0" max="100" step="0.1" class="uappt-input-rate" name="commission_tiers[<?php echo (int) $uappt_i; ?>][rate]" value="<?php echo esc_attr( '' === $uappt_tier['rate'] ? '' : $uappt_tier['rate'] ); ?>" />
												<span aria-hidden="true">%</span>
											</span>
										</td>
										<td class="uappt-tier-actions">
											<button type="button" class="button-link uappt-tier-remove"><?php esc_html_e( '移除', 'ultimate-appointments' ); ?></button>
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
						<p class="uappt-tier-add-wrap">
							<button type="button" class="button uappt-tier-add"><?php esc_html_e( '＋ 新增級距', 'ultimate-appointments' ); ?></button>
						</p>
						<p class="description">
							<?php esc_html_e( '整張表留空＝這位人員不抽成。', 'ultimate-appointments' ); ?>
						</p>

						<p>
							<label for="uappt-commission-upcharge"><?php esc_html_e( '指定加價分成', 'ultimate-appointments' ); ?></label>
							<span class="uappt-pm-rate">
								<input type="number" id="uappt-commission-upcharge" min="0" max="100" step="0.1" class="uappt-input-rate" name="commission_upcharge_rate" value="<?php echo esc_attr( $uappt_comm['upcharge_rate'] > 0 ? $uappt_comm['upcharge_rate'] : '' ); ?>" />
								<span aria-hidden="true">%</span>
							</span>
						</p>
						<p class="description">
							<?php esc_html_e( '填 100 代表指定費全歸人員，填 0（或留空）代表全歸店家。', 'ultimate-appointments' ); ?>
						</p>
						<p class="description">
							<?php
							UAPPT_Admin::help(
								array(
									__( '比例可以填 0：「0%」代表這一段不抽成，用來做基本業績門檻。最低的那一段沒從 0 開始時會自動補一段 0%——只填「200000 起 30%」就等於「0～20 萬 0%、20 萬以上 30%」。', 'ultimate-appointments' ),
									__( '比例留空的那一列會被忽略（新增用的空白列就是這樣運作的），要設 0% 請明確填 0。每一段都是 0% 也等於不抽成。', 'ultimate-appointments' ),
									__( '指定加價**不走上面的級距**，用「指定加價分成」單獨計算，級距的門檻也不把它算進去。', 'ultimate-appointments' ),
									__( '⚠️ 抽成是以**整個日曆月**的業績判定級距的，所以報表上的試算永遠是整月的數字，跟你選的統計區間不一定相同。這是試算，不含底薪、獎金與跨月結算。', 'ultimate-appointments' ),
								)
							);
							?>
						</p>
					</fieldset>
				</td>
			</tr>
		</table>
		<?php if ( $editing ) { UAPPT_Admin::staff_card_form_close(); } ?>
		<?php UAPPT_Admin::card_close(); ?>


		<?php
		// 排班方式（v2.93.0，原本叫「固定班樣板」）。
		//
		// 三選一在最上面，選固定班才展開樣板。原本這張卡片永遠在、而且護欄會叫
		// 「接下來 30 天沒有班表」，讓彈性排班的店以為**一定要**先設樣板再一個一個
		// 改——那正是這一版要解決的事（docs/staff-roster-plan.md）。
		//
		// 24 小時從「預約設定」搬過來併成第三個選項：它回答的是同一個問題，而且跟
		// 樣板互斥。儲存仍然是 is_24h ＋ schedule_mode 兩個欄位（見 D1）。
		//
		// ⚠️ 樣板編輯器**只是藏起來，不是拿掉**：選彈性班或 24 小時時它的欄位照樣
		// 送出，存下去的就是原本的樣板——切回固定班時樣板還在。
		//
		// ⚠️ **新增頁沒有這張卡片**（v2.96.2，使用者決定）。新增頁沒有月曆（排的每一天
		// 都要掛在一位已存在的人員底下），而這張卡片的說明句句都在講「下面的月曆」——
		// 選了彈性班卻看不到月曆，看起來像漏做。新人員一律從彈性班、沒有任何班開始
		// （UAPPT_Staff::save() 沒收到排班方式時照樣板推導，樣板空白＝彈性班，跟舊資料
		// 回填、CSV 匯入同一條規則），建立後到編輯頁再決定；那時這張卡片預設打開。
		if ( $editing ) :
		if ( $is_24h ) {
			$uappt_schedule_summary = __( '24 小時營業', 'ultimate-appointments' );
		} elseif ( UAPPT_Staff::MODE_FLEX === $uappt_kind ) {
			$uappt_schedule_summary = __( '彈性班', 'ultimate-appointments' );
		} else {
			$uappt_schedule_summary = UAPPT_Admin::card_summary(
				array(
					__( '固定班', 'ultimate-appointments' ),
					$uappt_template_summary ? $uappt_template_summary : __( '樣板未設定', 'ultimate-appointments' ),
				)
			);
		}
		UAPPT_Admin::card_open(
			array(
				'icon'    => 'clipboard-list',
				'title'   => __( '排班方式', 'ultimate-appointments' ),
				'summary' => $uappt_schedule_summary,
				'open'    => $uappt_just_created,
				'id'      => 'uappt-schedule-card',
			)
		);
		?>
		<?php UAPPT_Admin::staff_card_form_open( 'schedule', $editing['id'] ); ?>
		<?php
		$uappt_kind_options = array(
			UAPPT_Staff::MODE_FIXED => array(
				__( '固定班', 'ultimate-appointments' ),
				__( '每週差不多一樣。設定一次樣板，系統自動排。', 'ultimate-appointments' ),
			),
			UAPPT_Staff::MODE_FLEX  => array(
				__( '彈性班', 'ultimate-appointments' ),
				__( '每週每月都不一樣。直接在月曆上排。', 'ultimate-appointments' ),
			),
			'24h'                  => array(
				__( '24 小時', 'ultimate-appointments' ),
				__( '全年無休，全天候可預約。', 'ultimate-appointments' ),
			),
		);
		?>
		<fieldset class="uappt-kind-choice">
			<legend class="screen-reader-text"><?php esc_html_e( '這位人員怎麼排班', 'ultimate-appointments' ); ?></legend>
			<?php foreach ( $uappt_kind_options as $uappt_kind_value => $uappt_kind_text ) : ?>
				<label class="uappt-kind-option">
					<input type="radio" name="schedule_kind" value="<?php echo esc_attr( $uappt_kind_value ); ?>"
						<?php checked( $uappt_kind, $uappt_kind_value ); ?>
						<?php // 編輯頁一定已經有一個被選，required 只是保險（自己組的表單沒選就送不出去）。 ?>
						required />
					<span class="uappt-kind-text">
						<strong><?php echo esc_html( $uappt_kind_text[0] ); ?></strong>
						<span class="description"><?php echo esc_html( $uappt_kind_text[1] ); ?></span>
					</span>
				</label>
			<?php endforeach; ?>
		</fieldset>

		<?php
		// 選了之後下面只出現跟那個選項有關的一句話（或樣板）。沒有 JS 時三塊都會
		// 顯示——`hidden` 是伺服器端照目前的值設的，JS 只是在切換時跟著改。
		?>
		<p class="description uappt-kind-note" data-uappt-kind="<?php echo esc_attr( UAPPT_Staff::MODE_FLEX ); ?>"<?php echo UAPPT_Staff::MODE_FLEX === $uappt_kind ? '' : ' hidden'; ?>>
			<?php esc_html_e( '不用設樣板。班表直接在下面「這個月的班」排，或到「月排班表」跟其他人一起排；系統不會自動幫這位人員排任何一天。', 'ultimate-appointments' ); ?>
		</p>
		<p class="description uappt-kind-note" data-uappt-kind="24h"<?php echo '24h' === $uappt_kind ? '' : ' hidden'; ?>>
			<?php esc_html_e( '每天 00:00 到隔天 00:00 都開放預約，不用排班。', 'ultimate-appointments' ); ?>
		</p>

		<div class="uappt-kind-note uappt-template-editor" data-uappt-kind="<?php echo esc_attr( UAPPT_Staff::MODE_FIXED ); ?>"<?php echo UAPPT_Staff::MODE_FIXED === $uappt_kind ? '' : ' hidden'; ?>>
					<p class="description">
						<?php esc_html_e( '每週固定上哪幾段。系統會自動鋪成下面月曆的實際班表，設定這一次就好。', 'ultimate-appointments' ); ?>
					</p>
					<?php
					UAPPT_Admin::help(
						array(
							__( '樣板會把班表鋪到「開放預約天數」為止，而且每天自動往前補——不用每個月回來排。空白的星期鋪成「例休」。', 'ultimate-appointments' ),
							__( '⚠️ 樣板只擁有「它自己產生的日子」。你在月曆上排過的、員工排班申請核准的、CSV 匯入的日子，改樣板都不會被覆蓋。反過來說，改了樣板之後存檔，今天以後**由樣板產生**的日子會整批重鋪一次。', 'ultimate-appointments' ),
							__( '時間可以直接打字（輸入「930」會自動變成「09:30」），也可以從下拉選單挑；結束時間填得比開始早代表上到隔天。', 'ultimate-appointments' ),
							// 換排班方式的兩個方向放在這裡，不另開一個說明：要換的那一刻
							// 這個人一定是固定班（要換走）或正要選固定班（要換來），兩種
							// 情況這一塊都在畫面上。
							__( '固定班換成彈性班：已經自動排好的日子會保留，變成一般排好的班，之後自己改。樣板也會留著，換回固定班時還在。', 'ultimate-appointments' ),
							__( '彈性班換成固定班：樣板只補「還沒排」的日子，已經排好的不會被蓋掉。', 'ultimate-appointments' ),
						)
					);
					?>

					<?php
					// 時間下拉的選項照這位人員的時間格顆粒產生（沒設就用全域）。
					// 用 <datalist> 而不是 <input type="time">：後者會拿掉「打 930」
					// 的容錯，而那個容錯是這張表最常被用到的輸入方式。
					$uappt_step = (int) ( $form['slot_interval'] ?? 0 );
					if ( $uappt_step < 5 ) {
						$uappt_step = max( 5, (int) get_option( 'uappt_slot_interval_minutes', 15 ) );
					}
					?>
					<datalist id="uappt-time-options">
						<?php for ( $uappt_m = 0; $uappt_m < 1440; $uappt_m += $uappt_step ) : ?>
							<option value="<?php echo esc_attr( sprintf( '%02d:%02d', intdiv( $uappt_m, 60 ), $uappt_m % 60 ) ); ?>"></option>
						<?php endfor; ?>
					</datalist>

					<div class="uappt-week">
						<?php foreach ( $day_labels as $day_key => $day_label ) : ?>
							<?php
							$uappt_day_ranges = isset( $form_hours[ $day_key ] ) && is_array( $form_hours[ $day_key ] ) ? $form_hours[ $day_key ] : array();
							// 「上班／公休」不是獨立欄位，是**推導出來的**：這天沒有任何
							// 時段就是公休。維持跟 sanitize_ranges() 一樣的語意，伺服器端
							// 完全不用改。勾選框因此刻意沒有 name，純粹是畫面控制。
							// ⚠️ **樣板整張空白時預設「上班」。** 照「有時段才算上班」
							// 推導的話七列全部變成公休、整張表淡化成 0.55——要你填的
							// 表單看起來像停用的。
							//
							// v2.96.2 以前條件是「新增頁」；新增頁拿掉這張卡片之後，第一次
							// 填樣板一定是在編輯頁（新人員從彈性班、空樣板開始），條件
							// 改成「樣板空白」才保住原本的用意。
							//
							// 預設勾起來不會造成資料差異：空的時段存下去一樣是公休
							// （sanitize_ranges() 的語意沒變），只是讓表單看起來
							// 是可以填的。
							$uappt_working = $uappt_tpl_days ? ! empty( $uappt_day_ranges ) : true;
							?>
							<div class="uappt-week-row<?php echo $uappt_working ? '' : ' is-off'; ?>" data-day="<?php echo esc_attr( $day_key ); ?>">
								<span class="uappt-week-day"><?php echo esc_html( $day_label ); ?></span>

								<label class="uappt-week-toggle">
									<input type="checkbox" class="uappt-day-working" <?php checked( $uappt_working ); ?> />
									<span class="uappt-week-state"><?php echo $uappt_working ? esc_html__( '上班', 'ultimate-appointments' ) : esc_html__( '公休', 'ultimate-appointments' ); ?></span>
								</label>

								<span class="uappt-week-ranges">
									<?php
									// ⚠️ 三個時段**一律印進 DOM**，空的只是用 CSS 藏起來。
									// 「＋加一段」只是把下一個顯示出來——這樣沒有 JS 時三段
									// 全部看得到、完全不會比改版前退步。
									for ( $uappt_i = 0; $uappt_i < 3; $uappt_i++ ) :
										$uappt_r     = isset( $uappt_day_ranges[ $uappt_i ] ) ? $uappt_day_ranges[ $uappt_i ] : array( '', '' );
										$uappt_s     = isset( $uappt_r[0] ) ? $uappt_r[0] : '';
										$uappt_e     = isset( $uappt_r[1] ) ? $uappt_r[1] : '';
										$uappt_empty = ( '' === $uappt_s && '' === $uappt_e );
										// 第一段永遠顯示（它是「上班」的意思）；二、三段空的就收起來。
										$uappt_extra = ( $uappt_i > 0 && $uappt_empty );
										?>
										<span class="uappt-range<?php echo $uappt_extra ? ' is-extra' : ''; ?>">
											<input type="text" inputmode="numeric" autocomplete="off" class="uappt-time-input"
												list="uappt-time-options"
												placeholder="<?php echo 0 === $uappt_i ? '09:00' : ''; ?>"
												aria-label="<?php printf( esc_attr__( '%1$s 時段%2$d 開始', 'ultimate-appointments' ), esc_attr( $day_label ), (int) $uappt_i + 1 ); ?>"
												name="hours[<?php echo esc_attr( $day_key ); ?>][<?php echo (int) $uappt_i; ?>][start]"
												value="<?php echo esc_attr( $uappt_s ); ?>" />
											<span class="uappt-range-dash" aria-hidden="true">–</span>
											<input type="text" inputmode="numeric" autocomplete="off" class="uappt-time-input"
												list="uappt-time-options"
												placeholder="<?php echo 0 === $uappt_i ? '21:00' : ''; ?>"
												aria-label="<?php printf( esc_attr__( '%1$s 時段%2$d 結束', 'ultimate-appointments' ), esc_attr( $day_label ), (int) $uappt_i + 1 ); ?>"
												name="hours[<?php echo esc_attr( $day_key ); ?>][<?php echo (int) $uappt_i; ?>][end]"
												value="<?php echo esc_attr( $uappt_e ); ?>" />
											<span class="uappt-range-cross" hidden><?php esc_html_e( '→隔天', 'ultimate-appointments' ); ?></span>
											<button type="button" class="uappt-range-remove" aria-label="<?php esc_attr_e( '清掉這一段', 'ultimate-appointments' ); ?>">&times;</button>
										</span>
									<?php endfor; ?>
									<button type="button" class="button-link uappt-range-add"><?php esc_html_e( '＋加一段', 'ultimate-appointments' ); ?></button>
								</span>
							</div>
						<?php endforeach; ?>
					</div>

					<p class="uappt-week-actions">
						<button type="button" class="button" id="uappt-apply-weekdays"><?php esc_html_e( '套用到平日（一～五）', 'ultimate-appointments' ); ?></button>
						<button type="button" class="button" id="uappt-apply-all"><?php esc_html_e( '套用到全部', 'ultimate-appointments' ); ?></button>
						<span class="description"><?php esc_html_e( '以「週一」那一列為準複製過去。', 'ultimate-appointments' ); ?></span>
					</p>

					<p class="description uappt-hint">
						<?php esc_html_e( '⚠️ 療程必須完整落在同一個時段內。整天連續上班請用一段涵蓋（例如 09:00–21:00），不要為了分上午／下午拆成兩段——拆開會讓跨越分界的時段無法預約。前台要分上午／下午顯示請用「設定 ▸ 前台顯示」的時段分類，那是全店共用一組。', 'ultimate-appointments' ); ?>
					</p>
		</div>
		<?php UAPPT_Admin::staff_card_form_close(); ?>
		<?php UAPPT_Admin::card_close(); ?>
		<?php endif; // 排班方式只在編輯頁 ?>

		<?php
		// ⚠️ **送出鈕放在表單裡、所有卡片外面。** 它存的是上面三張設定卡片的全部
		// 內容，放進其中任何一張都會變成「收起那張卡片就找不到儲存鈕」。
		?>
		<?php if ( ! $editing ) : ?>
			<p class="submit uappt-settings-submit">
				<?php submit_button( __( '建立人員', 'ultimate-appointments' ), 'primary', 'submit', false ); ?>
			</p>
		</form>
	<?php endif; ?>

	<?php if ( $editing ) : ?>
		<?php
		// 這個月的班：整個排班工作都在這一張卡片裡，而且**預設展開**——它是這一頁
		// 唯一每個月都要用的東西，其他四張是設定一次就不太碰的。
		UAPPT_Admin::card_open(
			array(
				'icon'    => 'calendar',
				'title'   => __( '這個月的班', 'ultimate-appointments' ),
				'summary' => UAPPT_Admin::card_summary(
					array(
						$uappt_month_label,
						sprintf( /* translators: %d: 已排的天數 */ __( '已排 %d 天', 'ultimate-appointments' ), $uappt_month_scheduled ),
					)
				),
				'open'    => true,
				'class'   => 'uappt-hours-section',
				'id'      => 'uappt-month-schedule',
			)
		);
		?>
					<?php
					// 排班狀態列（v2.87.0）。**這是整個排班改版最重要的一條資訊**：
					// 班表排到哪一天為止，以及那是不是夠遠。
					//
					// 沒有它，「忘了往下排」這件事在後台**完全沒有痕跡**——月曆翻到
					// 下個月是一片空白，但空白跟「排好了、那幾天就是不上班」長得
					// 一模一樣。階段 6 把每週範本降級之後，這個失效會從邊角情境變成
					// 主要失效模式，所以護欄要先立起來再拔範本。
					if ( $editing ) :
						$uappt_coverage = UAPPT_Staff::coverage_for( $editing );
						$uappt_gap_month = substr( $uappt_coverage['next_gap'], 0, 7 );
						?>
						<?php
						// ⚠️ 顏色與文字看 `short`，不是 `uncovered`（v2.93.0）。彈性班按月排，
						// 月初時下個月還沒排是常態——照 uncovered 判斷會從月初紅到月底。
						// 彈性班還沒到提醒的時候，走最後那個中性的分支。
						?>
						<p class="uappt-coverage <?php echo $uappt_coverage['short'] ? 'is-short' : 'is-ok'; ?>">
							<?php if ( null === $uappt_coverage['last_covered'] ) : ?>
								<strong>
									<?php
									printf(
										/* translators: %d: 開放預約天數 */
										esc_html__( '⚠️ 接下來 %d 天完全沒有班表，客人約不到這位人員。', 'ultimate-appointments' ),
										(int) $uappt_coverage['days']
									);
									?>
								</strong>
							<?php elseif ( $uappt_coverage['uncovered'] > 0 ) : ?>
								<?php if ( $uappt_coverage['short'] ) : ?>
									<strong>
										<?php
										printf(
											/* translators: 1: 日期 2: 天數 */
											esc_html__( '⚠️ 班表排到 %1$s，之後還有 %2$d 天沒排。', 'ultimate-appointments' ),
											esc_html( wp_date( 'Y/n/j', strtotime( $uappt_coverage['last_covered'] . ' 12:00:00' ) ) ),
											(int) $uappt_coverage['uncovered']
										);
										?>
									</strong>
								<?php else : ?>
									<?php
									printf(
										/* translators: 1: 日期 2: 提前幾天提醒 */
										esc_html__( '班表排到 %1$s。彈性班在斷班前 %2$d 天會提醒你排下一段。', 'ultimate-appointments' ),
										esc_html( wp_date( 'Y/n/j', strtotime( $uappt_coverage['last_covered'] . ' 12:00:00' ) ) ),
										(int) UAPPT_Staff::FLEX_NOTICE_DAYS
									);
									?>
								<?php endif; ?>
								<?php
								// 「跳到第一個沒排的月份」。沒有這個連結的話，排班的人
								// 要自己算「排到 11/30、那我該翻到 12 月」——而那正是
								// 他已經忘過一次的那件事。
								if ( $uappt_gap_month !== $batch_calendar['month'] ) :
									?>
									<a href="<?php echo esc_url( add_query_arg( 'month', $uappt_gap_month, UAPPT_Admin::url( 'staff', array( 'action' => 'edit', 'staff_id' => $editing['id'] ) ) ) ); ?>">
										<?php
										printf(
											/* translators: %s: 年月，例如 2027 年 1 月 */
											esc_html__( '翻到 %s 接著排 →', 'ultimate-appointments' ),
											esc_html( wp_date( 'Y 年 n 月', strtotime( $uappt_coverage['next_gap'] . ' 12:00:00' ) ) )
										);
										?>
									</a>
								<?php endif; ?>
							<?php else : ?>
								<?php
								printf(
									/* translators: 1: 日期 2: 開放預約天數 */
									esc_html__( '✓ 班表已排到 %1$s，涵蓋了開放預約的 %2$d 天。', 'ultimate-appointments' ),
									esc_html( wp_date( 'Y/n/j', strtotime( $uappt_coverage['last_covered'] . ' 12:00:00' ) ) ),
									(int) $uappt_coverage['days']
								);
								?>
							<?php endif; ?>
						</p>
					<?php endif; ?>
					<?php
					// 店休是全店共用的設定，不屬於任何一位人員——這裡只讀不改，
					// 但一定要顯示：少了它，排班的人會在這張表上看到「週日 09:00
					// –21:00」卻發現前台約不到，而畫面上完全沒有線索。
					$uappt_closure_summary = UAPPT_Shop_Closure::summary();
					?>
					<?php if ( '' !== $uappt_closure_summary ) : ?>
						<p class="description uappt-hint">
							<?php
							printf(
								/* translators: 1: 公休日摘要 2: 設定頁連結 */
								wp_kses_post( __( '🏪 <strong>本店公休：%1$s</strong>——這幾天不論下面怎麼排都不開放預約，固定班樣板也不會在那幾天產生班。公休是全店設定，在 %2$s 調整。需要讓這位人員在公休日破例上班，請在下面的月曆點那一天設一筆自訂時段（單日調整的優先序高於公休）。', 'ultimate-appointments' ) ),
								esc_html( $uappt_closure_summary ),
								'<a href="' . esc_url( UAPPT_Admin::url( 'settings', array( 'tab' => 'rules' ) ) ) . '">' . esc_html__( '設定 ▸ 預約規則', 'ultimate-appointments' ) . '</a>'
							);
							?>
						</p>
					<?php endif; ?>

		<?php
		// 這個月的班。月曆本身跟前台「我的班表」共用同一支資料
		// （UAPPT_Staff::get_schedule_month()）。
		//
		// v2.85.0 從「勾選日期 ＋ 下面填一組設定 ＋ 送出」改成**選畫筆直接塗**。
		// 前者要走四步才排得完一個月（勾 → 選類型 → 填時間 → 送出），而且勾選框
		// 散在整個月曆裡，捲動之後看不到自己勾了哪些；塗抹是「選一次畫筆、點幾下
		// 就好」，而且每一格當下就看得到結果。
		$uappt_batch_base = UAPPT_Admin::url( 'staff', array( 'action' => 'edit', 'staff_id' => $editing['id'] ) );
		?>
		<div class="uappt-card-section">
			<p class="uappt-cal-nav">
				<a class="button" href="<?php echo esc_url( add_query_arg( 'month', $batch_calendar['prev_month'], $uappt_batch_base ) ); ?>">&laquo; <?php esc_html_e( '上個月', 'ultimate-appointments' ); ?></a>
				<strong class="uappt-cal-nav-label">
					<?php
					$uappt_batch_dt = date_create( $batch_calendar['month'] . '-01', wp_timezone() );
					echo esc_html( $uappt_batch_dt ? wp_date( 'Y 年 n 月', $uappt_batch_dt->getTimestamp() ) : $batch_calendar['month'] );
					?>
				</strong>
				<a class="button" href="<?php echo esc_url( add_query_arg( 'month', $batch_calendar['next_month'], $uappt_batch_base ) ); ?>"><?php esc_html_e( '下個月', 'ultimate-appointments' ); ?> &raquo;</a>
				<?php
				// 同一個月的全店月排班表（v2.95.0）。排一個人時常常要看「那天還有誰」，
				// 而那正是全店表回答的問題。
				?>
				<a class="uappt-cal-nav-roster" href="<?php echo esc_url( UAPPT_Admin::url( 'staff', array( 'tab' => 'roster', 'month' => $batch_calendar['month'] ) ) ); ?>"><?php esc_html_e( '看全店月排班表 →', 'ultimate-appointments' ); ?></a>
			</p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="uappt-month-paint">
				<input type="hidden" name="action" value="uappt_save_staff_month" />
				<input type="hidden" name="staff_id" value="<?php echo esc_attr( $editing['id'] ); ?>" />
				<input type="hidden" name="month" value="<?php echo esc_attr( $batch_calendar['month'] ); ?>" />
				<?php wp_nonce_field( 'uappt_save_staff_month' ); ?>


				<?php
				// 班別是「選了日子之後能挑什麼」的清單。沒設定班別時只剩例休／請假／
				// 清除，排不出上班的日子——所以這條提示要在月曆上方，不是收在某個
				// 說明段落裡。
				$uappt_shifts = UAPPT_Shift_Preset::all();
				?>
				<?php if ( empty( $uappt_shifts ) ) : ?>
					<p class="description uappt-hint">
						<?php
						printf(
							/* translators: %s: 設定頁連結 */
							wp_kses_post( __( '還沒有設定班別，所以選了日子之後只排得出「例休」「請假」。到 %s 建好「早班」「晚班」，排班就只要點日子、再點班別。', 'ultimate-appointments' ) ),
							'<a href="' . esc_url( UAPPT_Admin::url( 'staff', array( 'tab' => 'shifts' ) ) ) . '">' . esc_html__( '人員管理 ▸ 班別設定', 'ultimate-appointments' ) . '</a>'
						);
						?>
					</p>
				<?php endif; ?>

				<?php
				// 產生器：一次把整個月先塗好。
				//
				// ⚠️ **三顆鈕都只是「幫你先塗好」，不是第二條寫入路徑。** 它們產生的
				// 是跟手動塗抹一模一樣的未儲存狀態（同樣的 hidden input、同樣的
				// dirty 規則），所以仍然要按「儲存這個月」、仍然可以還原、仍然只送
				// 改過的日子。這是刻意的：產生出來的班表**一定**需要微調，先進資料庫
				// 再改就得改兩次。
				//
				// 資料整包印進 data 屬性給 JS 用，不走 AJAX——月曆已經在畫面上了，
				// 再多一個請求只是讓「按下去沒反應」多一種可能的原因。
				$uappt_gen_month    = $batch_calendar['month'];
				$uappt_gen_prev     = UAPPT_Staff::get_prev_month_plan_aligned( $editing, $uappt_gen_month );
				$uappt_gen_month_1  = $uappt_gen_month . '-01';
				$uappt_gen_template = UAPPT_Staff::get_range_plan(
					$editing,
					$uappt_gen_month_1,
					uappt_local_date( $uappt_gen_month_1, 'last day of this month' ),
					true // 假裝沒有任何逐日調整＝純粹照固定班樣板。
				);
				// 同事只列**啟用中**的：停用的人員本來就不會被排進任何預約，拿他的班
				// 當範本沒有意義。
				$uappt_gen_mates = array_filter(
					UAPPT_Staff::get_all( true ),
					static function ( $mate ) use ( $editing ) {
						return (int) $mate['id'] !== (int) $editing['id'];
					}
				);
				?>
				<div class="uappt-generators">
					<span class="uappt-generators-label"><?php esc_html_e( '一次排整個月', 'ultimate-appointments' ); ?></span>

					<button type="button" class="button uappt-generator"
						data-uappt-plan="<?php echo esc_attr( wp_json_encode( $uappt_gen_prev ) ); ?>">
						<?php esc_html_e( '複製上個月', 'ultimate-appointments' ); ?>
					</button>

					<?php
					// 彈性班、樣板整張空白都不給這顆：樣板對他們不參與，按下去的結果是一整個
					// 月的「例休」或什麼都沒有，兩種都不是使用者要的。條件跟全店月排班表的
					// 同一顆鈕一致（UAPPT_Admin::render_roster_page()）。
					if ( ! UAPPT_Staff::is_flex( $editing ) && UAPPT_Staff::template_has_ranges( $editing['business_hours'] ) ) :
						?>
						<button type="button" class="button uappt-generator"
							data-uappt-plan="<?php echo esc_attr( wp_json_encode( $uappt_gen_template ) ); ?>">
							<?php esc_html_e( '套用固定班樣板', 'ultimate-appointments' ); ?>
						</button>
					<?php endif; ?>

					<?php if ( $uappt_gen_mates ) : ?>
						<span class="uappt-generator-mate">
							<label class="screen-reader-text" for="uappt-gen-mate"><?php esc_html_e( '複製哪一位同事的班', 'ultimate-appointments' ); ?></label>
							<select id="uappt-gen-mate">
								<option value=""><?php esc_html_e( '— 複製同事 —', 'ultimate-appointments' ); ?></option>
								<?php foreach ( $uappt_gen_mates as $uappt_mate ) : ?>
									<?php
									$uappt_mate_plan = UAPPT_Staff::get_range_plan(
										$uappt_mate,
										$uappt_gen_month_1,
										uappt_local_date( $uappt_gen_month_1, 'last day of this month' )
									);
									?>
									<option value="<?php echo esc_attr( $uappt_mate['id'] ); ?>"
										data-uappt-plan="<?php echo esc_attr( wp_json_encode( $uappt_mate_plan ) ); ?>">
										<?php echo esc_html( $uappt_mate['name'] ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<button type="button" class="button uappt-generator" id="uappt-gen-mate-apply"><?php esc_html_e( '套用', 'ultimate-appointments' ); ?></button>
						</span>
					<?php endif; ?>
				</div>

				<?php
				UAPPT_Admin::help(
					array(
						__( '「複製上個月」是按月曆上的「第幾週 × 星期幾」對應，不是按日期——服務業的班跟著星期走，照日期抄會把整組規律錯開。', 'ultimate-appointments' ),
						__( '三顆都只是先幫你排好，一樣是未儲存的，確認過再按儲存。', 'ultimate-appointments' ),
					)
				);
				?>

				<?php
				// 月曆格線與員工中心共用同一份 partial。
				//
				// ⚠️ selectable 改成 false：勾選框是前台員工送排班申請用的，後台這一側
				// 從 v2.85.0 起改成直接塗抹，兩套選取方式並存只會讓人不知道該用哪個
				// （v2.81.0 把四個入口收成兩個，就是為了不要再發生這件事）。
				$calendar           = $batch_calendar;
				$grid_mode          = 'schedule';
				$grid_selectable    = false;
				$grid_day_url       = '';
				$grid_linkable      = array();
				$grid_selected_date = '';
				$grid_amounts       = array();
				$grid_editable      = true;
				$grid_paintable     = true;
				require UAPPT_PLUGIN_DIR . 'includes/views/partials/month-grid.php';
				?>

				<?php
				// ⚠️ **這一列在月曆下面，而且選了日子才長出班別鈕。**
				//
				// v2.85.0 是「選畫筆 → 點格子」。問題是畫筆是一個**看不見的模式**：
				// 選了「不塗」再點日子，什麼都不會發生，而使用者不會知道為什麼。
				// 對不熟電腦的人這是致命的。
				//
				// 改成「先選日子、再選要排什麼」——跟選檔案再選功能同一個模式，是
				// 使用者早就會的；順序也跟講話一樣（「11 月 5 日排早班」，不是
				// 「拿著早班去點 11 月 5 日」）。沒選日子時班別鈕根本不存在，所以
				// 不可能出現「點了沒反應」。
				?>
				<div class="uappt-schedule-actions" id="uappt-schedule-actions">
					<p class="uappt-shift-pick" hidden>
						<?php // 樣板字串交給 JS 代入，翻譯留在 PHP 這一側。 ?>
						<span class="uappt-shift-pick-label"
							data-one="<?php esc_attr_e( '%s 要排什麼？', 'ultimate-appointments' ); ?>"
							data-many="<?php esc_attr_e( '已選 %s 天，要排什麼？', 'ultimate-appointments' ); ?>"></span>

						<?php foreach ( $uappt_shifts as $uappt_shift ) : ?>
							<button type="button" class="uappt-shift uappt-shift--hours <?php echo esc_attr( UAPPT_Shift_Preset::color_class( $uappt_shift['color'] ) ); ?>"
								data-uappt-shift="hours"
								data-uappt-color="<?php echo esc_attr( $uappt_shift['color'] ); ?>"
								data-uappt-ranges="<?php echo esc_attr( wp_json_encode( $uappt_shift['ranges'] ) ); ?>">
								<span class="uappt-shift-name"><?php echo esc_html( $uappt_shift['name'] ); ?></span>
								<span class="uappt-shift-time"><?php echo esc_html( UAPPT_Shift_Preset::format_ranges( $uappt_shift['ranges'] ) ); ?></span>
							</button>
						<?php endforeach; ?>

						<?php require UAPPT_PLUGIN_DIR . 'includes/views/partials/shift-custom.php'; // 自訂時段（v2.100.0） ?>
						<button type="button" class="uappt-shift uappt-shift--off" data-uappt-shift="off" data-uappt-ranges="[]">
							<span class="uappt-shift-name"><?php esc_html_e( '例休', 'ultimate-appointments' ); ?></span>
						</button>
						<button type="button" class="uappt-shift uappt-shift--leave" data-uappt-shift="leave" data-uappt-ranges="[]">
							<span class="uappt-shift-name"><?php esc_html_e( '請假', 'ultimate-appointments' ); ?></span>
						</button>
						<button type="button" class="uappt-shift uappt-shift--clear" data-uappt-shift="clear" data-uappt-ranges="[]">
							<span class="uappt-shift-name"><?php esc_html_e( '清除', 'ultimate-appointments' ); ?></span>
						</button>

						<?php // 只選一天時才有意義：那張表單一次只能改一天。 ?>
						<button type="button" class="button-link uappt-shift-detail" hidden><?php esc_html_e( '其他…', 'ultimate-appointments' ); ?></button>
						<button type="button" class="button-link uappt-shift-cancel"><?php esc_html_e( '取消選取', 'ultimate-appointments' ); ?></button>
						<?php
						// 「管理班別」（v2.98.0）：有班別之後排班畫面原本找不到任何地方新增或修改
						// 班別——使用者回報「班別無法自行設定」的原因。未儲存的排班由離開頁面的
						// 提示攔著，不會默默丟掉。
						?>
						<a class="button-link uappt-manage-shifts" href="<?php echo esc_url( UAPPT_Admin::url( 'staff', array( 'tab' => 'shifts' ) ) ); ?>"><?php esc_html_e( '管理班別', 'ultimate-appointments' ); ?></a>
					</p>

					<p class="uappt-paint-actions">
						<?php submit_button( __( '儲存這個月', 'ultimate-appointments' ), 'primary', '', false ); ?>
						<button type="button" class="button" id="uappt-paint-reset" hidden><?php esc_html_e( '還原未儲存的變更', 'ultimate-appointments' ); ?></button>
						<span id="uappt-paint-dirty" class="uappt-paint-dirty" data-template="<?php esc_attr_e( '未儲存 %d 天', 'ultimate-appointments' ); ?>" hidden></span>
					</p>
				</div>

				<p class="description">
					<?php esc_html_e( '點日子選起來（可以連點好幾天，或點星期標題選整欄），再點下面的班別就排好了。按「儲存這個月」才會寫進去。', 'ultimate-appointments' ); ?>
				</p>
				<?php
				UAPPT_Admin::help(
					array(
						__( '按儲存之前重新整理就會回到原狀，所以可以放心先排排看。', 'ultimate-appointments' ),
						__( '只有改過的日子會被送出——沒碰過的日子不會被重寫，員工排班申請核准後留下的紀錄也就不會被洗掉。', 'ultimate-appointments' ),
						__( '排好的日子會沿用原本的備註。要改備註或排非標準時段，選一天之後按「其他…」。', 'ultimate-appointments' ),
					)
				);
				?>
			</form>
		</div>

		<?php
		// ⚠️ **「進階：單日調整清單」整個拿掉了（v2.92.0）。**
		//
		// 它存在的意義是「這個人有哪幾天跟平常不一樣」，而 v2.88.0 之後每一天都有
		// 資料列了——實測排完一個月之後它顯示 **30 筆**，列的是每一天，等於沒有列。
		//
		// 它原本提供的四件事現在都有更近的入口：
		//
		// | 原本用它做什麼 | 現在 |
		// | --- | --- |
		// | 看哪幾天不一樣 | 月曆就是（而且看得到時段，不是一行字） |
		// | 刪掉某一天的調整 | 選那天 → 班別列的「清除」 |
		// | 看備註 | 選那天 → 「其他…」 |
		// | 看來源 | 月曆格子上的「已核准調整」「批次匯入」徽章 |
		//
		// CSV 匯入也不在這裡了：人員清單頁的標題列本來就有「批次匯入」鈕
		// （UAPPT_Admin::render_staff_page()），而那裡才是「一次處理很多人」的地方。
		// 這個 summary 甚至寫著「／批次匯入」卻沒有任何連結，已經騙人很久了。
		?>


		<?php
		// 「改這一天」改成收合（v2.92.0）。九成的排班是「選日子 → 選班別」，這張
		// 表單只處理剩下的一成：非標準時段、備註。永遠攤開的話，每一次排班都要
		// 捲過一整張它。
		//
		// ⚠️ **用 `<details>` 而不是 JS 顯示／隱藏**：沒有 JS 時它仍然打得開
		// （點標題就展開），所以「JS 壞掉的最差情況是要自己填日期」這條退路
		// （v2.81.0 定下的）還在。
		?>
		<details class="uappt-day-editor" id="uappt-day-editor">
			<summary>
				<?php esc_html_e( '改這一天（非標準時段、備註）', 'ultimate-appointments' ); ?>
			</summary>
			<p class="description"><?php esc_html_e( '在上面的月曆選一天，再按「其他…」就會自動帶入；也可以直接填日期。', 'ultimate-appointments' ); ?></p>
		<?php
		// 這些表單放在面板裡，卡片外框由面板提供，不再掛 .uappt-form（會變框中框）。
		//
		// ⚠️ 這張表單**不是新東西**，是原本就在的「新增／更新逐日調整」。月曆的
		// 「點一天」只是用 JS 把它填好再捲過來——寫入仍然走 admin-post + nonce +
		// capability（設計紀律 #8：寫入不用 AJAX）。所以點格子這件事壞掉的最差
		// 情況是「要自己填日期」，不會變成存不進去。
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="uappt-day-form">
			<input type="hidden" name="action" value="uappt_save_staff_override" />
			<input type="hidden" name="staff_id" value="<?php echo esc_attr( $editing['id'] ); ?>" />
			<?php wp_nonce_field( 'uappt_save_staff_override' ); ?>

			<table class="form-table">
				<tr>
					<th><label for="uappt-override-date"><?php esc_html_e( '日期', 'ultimate-appointments' ); ?></label></th>
					<td><input type="date" id="uappt-override-date" name="override_date" required /></td>
				</tr>
				<tr>
					<th><?php esc_html_e( '類型', 'ultimate-appointments' ); ?></th>
					<td>
						<?php
						// ⚠️ **預設維持「請假」，不是「例休」。** 這張表單在這一版
						// 之前的預設是「整天休假」，而那個值的語意就是請假；改成預設
						// 例休的話，照習慣直接存檔的人會把請假悄悄記成排休。兩者對班表
						// 的效果一樣，所以不會有人立刻發現，只有報表的請假天數會慢慢
						// 失真。例休要明選。
						?>
						<label>
							<input type="radio" name="override_type" value="leave" checked="checked" class="uappt-override-type" />
							<?php esc_html_e( '請假（本來要上班，臨時不上）', 'ultimate-appointments' ); ?>
						</label>
						<br />
						<label>
							<input type="radio" name="override_type" value="off" class="uappt-override-type" />
							<?php esc_html_e( '例休（排定不上班）', 'ultimate-appointments' ); ?>
						</label>
						<br />
						<label>
							<input type="radio" name="override_type" value="hours" class="uappt-override-type" />
							<?php esc_html_e( '自訂時段（取代那天原本的安排）', 'ultimate-appointments' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( '班別', 'ultimate-appointments' ); ?></th>
					<td>
						<?php
						// ⚠️ 這一列**放在「類型」外面、永遠看得到**，而不是塞進下面
						// 那個會被隱藏的時段列裡。類型預設是「整天休假」＝時段列是收
						// 起來的，下拉藏在裡面就永遠選不到——而選班別本身就該把類型
						// 切成「自訂時段」（$preset_set_type）。
						$preset_target   = 'override_hours';
						$preset_set_type = true;
						require UAPPT_PLUGIN_DIR . 'includes/views/partials/shift-preset-picker.php';
						?>
					</td>
				</tr>
				<?php
				// ⚠️ **這一列不再用 inline style 藏起來**，改由 admin-staff.js 在
				// 初始化時收合。理由是「JS 掛掉時要留在可用的那一側」：寫死
				// display:none 的話，JS 一壞掉這幾個時段欄位就永遠打不開，表單只剩
				// 「整天休假」能用。沒有 JS 時全部展開，跟 v2.80.0「三個時段一律印
				// 進 DOM、空的用 CSS 收起來」是同一個取捨。
				?>
				<tr class="uappt-override-hours-row">
					<th><?php esc_html_e( '當天時段', 'ultimate-appointments' ); ?></th>
					<td>
						<table>
							<tr>
								<td><input type="text" inputmode="numeric" autocomplete="off" class="uappt-time-input" list="uappt-time-options" placeholder="09:00" name="override_hours[0][start]" /></td>
								<td>–</td>
								<td><input type="text" inputmode="numeric" autocomplete="off" class="uappt-time-input" list="uappt-time-options" placeholder="13:00" name="override_hours[0][end]" /></td>
							</tr>
							<tr>
								<td><input type="text" inputmode="numeric" autocomplete="off" class="uappt-time-input" list="uappt-time-options" placeholder="" name="override_hours[1][start]" /></td>
								<td>–</td>
								<td><input type="text" inputmode="numeric" autocomplete="off" class="uappt-time-input" list="uappt-time-options" placeholder="" name="override_hours[1][end]" /></td>
							</tr>
							<?php
							// ⚠️ **第三段是必要的，不是湊數。** 每週班表允許三段，
							// 而 v2.81.0 起點月曆會把「這天目前的時段」帶進這張表單
							// ——只有兩格的話，三段班的人（例如 09-12／13-18／
							// 20-02）點一下再存檔，深夜那段就被**靜默丟掉**了。
							// sanitize_ranges() 本來就沒有數量上限，是這裡少印。
							?>
							<tr>
								<td><input type="text" inputmode="numeric" autocomplete="off" class="uappt-time-input" list="uappt-time-options" placeholder="" name="override_hours[2][start]" /></td>
								<td>–</td>
								<td><input type="text" inputmode="numeric" autocomplete="off" class="uappt-time-input" list="uappt-time-options" placeholder="" name="override_hours[2][end]" /></td>
							</tr>
						</table>
						<p class="description"><?php esc_html_e( '半天假直接留白不要的那段即可（不用另外設請假）。跨午夜寫法跟班表一樣：結束時間填得比開始時間早，代表營業到隔天。', 'ultimate-appointments' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><label for="uappt-override-note"><?php esc_html_e( '備註', 'ultimate-appointments' ); ?></label></th>
					<td><input type="text" id="uappt-override-note" name="note" class="regular-text" placeholder="<?php esc_attr_e( '例如：特休 / 中秋節公休 / 只上半天', 'ultimate-appointments' ); ?>" /></td>
				</tr>
			</table>

			<?php
			// 跟設定卡片的儲存鈕同一個外框（上方分隔線＋固定間距）。submit_button() 預設
			// 包的是 WordPress 的 <p class="submit">，上下內距是 1.5em，同一頁上兩種鈕的
			// 間距不一樣（v2.96.1）。
			?>
			<p class="submit uappt-card-submit"><?php submit_button( __( '儲存逐日調整', 'ultimate-appointments' ), 'primary', 'submit', false ); ?></p>
		</form>
		</details>
		<?php
		// 「類型」與「當天時段」的連動搬到 admin-staff.js 了（v2.83.0）。
		//
		// ⚠️ **原本那段 inline script 用的是原生 addEventListener('change')，而
		// 點月曆的程式碼（admin-staff.js）是用 jQuery 的 .trigger('change') 切
		// 類型——jQuery 3.7.1 的 trigger 只會跑它自己註冊的 handler，原生
		// addEventListener 的不會被呼叫**（leverageNative 只套用在 click／
		// focus／blur 三種事件上）。結果是「點一天帶出了時段，但『當天時段』那一
		// 列還是收著」。兩邊都用 jQuery 綁，這個落差才不會再出現。
		?>
		<?php UAPPT_Admin::card_close(); // 這個月的班 ?>

		<?php
		// 時段佔用也是一張收合卡片（v4 計畫 D5）。原本建議把它搬到日檢視——它是
		// 營運動作不是人員設定——使用者決定留在這一頁。留下來的成本因此只剩
		// 「多一張收合的卡片」，不再是「多一張永遠攤開的面板加兩個表單」。
		UAPPT_Admin::card_open(
			array(
				'icon'    => 'clock',
				'title'   => __( '時段佔用', 'ultimate-appointments' ),
				'summary' => $blocks
					? sprintf( /* translators: %d: 筆數 */ __( '%d 段', 'ultimate-appointments' ), count( $blocks ) )
					: __( '無', 'ultimate-appointments' ),
			)
		);
		?>
		<p class="description">
			<?php esc_html_e( '把一段時間從可預約名額裡扣掉（教育訓練、開會、現場客人）。跟「請假」的差別是：請假是整天，這個是某一段時間。', 'ultimate-appointments' ); ?>
		</p>


		<table class="widefat striped uappt-table">
			<thead>
				<tr>
					<th><?php esc_html_e( '時間', 'ultimate-appointments' ); ?></th>
					<th><?php esc_html_e( '佔用名額', 'ultimate-appointments' ); ?></th>
					<th><?php esc_html_e( '原因', 'ultimate-appointments' ); ?></th>
					<th><?php esc_html_e( '操作', 'ultimate-appointments' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $blocks ) ) : ?>
					<tr><td colspan="4"><?php esc_html_e( '目前沒有即將到來的時段佔用。', 'ultimate-appointments' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $blocks as $block ) : ?>
					<?php
					$b_start = date_create( $block['service_start'], wp_timezone() );
					$b_end   = date_create( $block['service_end'], wp_timezone() );
					$b_label = ( $b_start && $b_end )
						? wp_date( 'Y-m-d (D) H:i', $b_start->getTimestamp() ) . '–' . wp_date( 'H:i', $b_end->getTimestamp() )
						: '';
					?>
					<tr>
						<td data-label="<?php esc_attr_e( '時間', 'ultimate-appointments' ); ?>"><?php echo esc_html( $b_label ); ?></td>
						<td data-label="<?php esc_attr_e( '佔用名額', 'ultimate-appointments' ); ?>">
							<?php
							$b_units = (int) $block['occupied_units'];
							if ( $b_units >= (int) $form['capacity'] ) {
								esc_html_e( '佔滿', 'ultimate-appointments' );
							} else {
								printf(
									/* translators: %d: 佔用的名額數 */
									esc_html__( '%d 個名額', 'ultimate-appointments' ),
									$b_units
								);
							}
							?>
						</td>
						<td data-label="<?php esc_attr_e( '原因', 'ultimate-appointments' ); ?>"><?php echo esc_html( $block['note'] ? $block['note'] : '—' ); ?></td>
						<td class="uappt-cell-block" data-label="<?php esc_attr_e( '操作', 'ultimate-appointments' ); ?>">
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php echo esc_js( __( '確定要刪除這段佔用嗎？名額會立刻還回去，該時段就能再被預約。', 'ultimate-appointments' ) ); ?>');">
								<input type="hidden" name="action" value="uappt_delete_staff_block" />
								<input type="hidden" name="block_id" value="<?php echo esc_attr( $block['id'] ); ?>" />
								<input type="hidden" name="staff_id" value="<?php echo esc_attr( $editing['id'] ); ?>" />
								<?php wp_nonce_field( 'uappt_delete_staff_block' ); ?>
								<button type="submit" class="button-link-delete"><?php esc_html_e( '刪除', 'ultimate-appointments' ); ?></button>
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<h3 class="uappt-card-subhead"><?php esc_html_e( '新增時段佔用', 'ultimate-appointments' ); ?></h3>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="uappt_save_staff_block" />
			<input type="hidden" name="staff_id" value="<?php echo esc_attr( $editing['id'] ); ?>" />
			<?php wp_nonce_field( 'uappt_save_staff_block' ); ?>

			<table class="form-table">
				<tr>
					<th><label for="uappt-block-date"><?php esc_html_e( '日期', 'ultimate-appointments' ); ?></label></th>
					<td><input type="date" id="uappt-block-date" name="block_date" value="<?php echo esc_attr( current_time( 'Y-m-d' ) ); ?>" required /></td>
				</tr>
				<tr>
					<th><label for="uappt-block-start"><?php esc_html_e( '起訖時間', 'ultimate-appointments' ); ?></label></th>
					<td>
						<input type="text" inputmode="numeric" autocomplete="off" class="uappt-time-input" id="uappt-block-start" name="block_start" placeholder="14:00" required />
						&nbsp;～&nbsp;
						<input type="text" inputmode="numeric" autocomplete="off" class="uappt-time-input" name="block_end" placeholder="15:30" required />
						<p class="description"><?php esc_html_e( '結束時間填得比開始早，代表佔用到隔天（深夜班用），例如 22:00 ～ 02:00。', 'ultimate-appointments' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><label for="uappt-block-units"><?php esc_html_e( '佔用名額', 'ultimate-appointments' ); ?></label></th>
					<td>
						<input type="number" id="uappt-block-units" name="block_units" min="0" step="1" value="0" />
						<p class="description">
							<?php
							printf(
								/* translators: %d: 這位人員的同時可服務人數 */
								esc_html__( '填 0 代表「佔滿」——整個人都不在（訓練、開會、休息）。這位人員目前的同時可服務人數是 %d，如果只是被現場一位客人佔住，填 1 就好，其餘名額仍然可以被預約。', 'ultimate-appointments' ),
								(int) $form['capacity']
							);
							?>
						</p>
					</td>
				</tr>
				<tr>
					<th><label for="uappt-block-note"><?php esc_html_e( '原因', 'ultimate-appointments' ); ?></label></th>
					<td>
						<input type="text" id="uappt-block-note" name="block_note" class="regular-text" placeholder="<?php esc_attr_e( '例如：教育訓練 / 現場客人 / 午休', 'ultimate-appointments' ); ?>" />
						<p class="description"><?php esc_html_e( '會顯示在日曆的日檢視上，讓當天看排班的人知道這段時間在做什麼。', 'ultimate-appointments' ); ?></p>
					</td>
				</tr>
			</table>

			<?php // 跟「儲存逐日調整」一樣是建立新資料，按鈕階層要一致（原本這個是 secondary）。 ?>
			<p class="submit uappt-card-submit"><?php submit_button( __( '建立時段佔用', 'ultimate-appointments' ), 'primary', 'submit', false ); ?></p>
		</form>
		<?php UAPPT_Admin::card_close(); // 時段佔用 ?>
	<?php endif; ?>
