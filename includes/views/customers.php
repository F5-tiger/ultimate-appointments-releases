<?php
/**
 * View：回訪管理——該聯絡的客人。
 *
 * **這一頁是「做」不是「看」。** 版面上的每一列都要能直接打電話：電話號碼是
 * `tel:` 連結，旁邊就是「標記已聯絡」。不放圖表、不放趨勢，那些在報表。
 *
 * 傳入變數：
 * - $result     UAPPT_Customer::get_lapsed() 的回傳值（['items','total']）
 * - $filters    目前的篩選條件
 * - $days       超過幾天沒來
 * - $staff_list 全部人員（含停用），供篩選下拉使用
 *
 * @package Ultimate_Appointments
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$uappt_total = (int) $result['total'];

if ( ! function_exists( 'uappt_customer_filter_fields' ) ) {
	/**
	 * 把目前的篩選條件印成 hidden 欄位。
	 *
	 * 每一列的「標記已聯絡」都是獨立的表單，送去 admin-post.php 之後
	 * `$_GET` 就沒了——篩選條件要靠表單自己帶過去，`handle_*` 才轉得回同一個
	 * 畫面。少了這個，按一下就會跳回預設的 60 天、第一頁，正在處理到一半的
	 * 名單就散了。
	 *
	 * @param array $filters 目前的篩選條件。
	 * @return string HTML。
	 */
	function uappt_customer_filter_fields( $filters ) {
		$html = '';

		foreach ( array( 'days', 'staff_id', 'paged' ) as $key ) {
			if ( ! empty( $filters[ $key ] ) ) {
				$html .= sprintf(
					'<input type="hidden" name="%s" value="%d" />',
					esc_attr( $key ),
					(int) $filters[ $key ]
				);
			}
		}

		if ( ! empty( $filters['include_contacted'] ) ) {
			$html .= '<input type="hidden" name="include_contacted" value="1" />';
		}

		return $html;
	}
}
?>

	<p class="uappt-page-desc">
		<?php esc_html_e( '超過一段時間沒來、而且還沒約下一次的客人。這一頁是拿來打電話的：標記聯絡過之後那位客人會先從名單上收起來，一個月後如果還是沒來會再出現。', 'ultimate-appointments' ); ?>
	</p>
	<p class="uappt-page-desc">
		<?php esc_html_e( '⚠️ 這裡的「上次到訪」看的是服務日期，跟「WooCommerce → 顧客」那一頁的數字不會一樣，而且不該一樣——那邊看的是下單日期。客人今天下單、下個月才來，在那邊會被當成今天剛來過。', 'ultimate-appointments' ); ?>
	</p>

	<?php
	// 天數一律算一項：這一頁的名單完全由它決定（預設 60 天），把它收起來會
	// 讓人看不出「符合條件的有 N 位」是以幾天為準算出來的。
	$uappt_active = 1;
	if ( $filters['staff_id'] ) {
		$uappt_active++;
	}
	if ( $filters['include_contacted'] ) {
		$uappt_active++;
	}

	UAPPT_Admin::filters_open(
		array(
			'section' => 'customers',
			'active'  => $uappt_active,
		)
	);
	?>
		<?php UAPPT_Admin::field_open( __( '多久沒來', 'ultimate-appointments' ), 'uappt-filter-days' ); ?>
			<span class="uappt-field-row">
				<?php esc_html_e( '超過', 'ultimate-appointments' ); ?>
				<input type="number" id="uappt-filter-days" name="days" min="1" step="1" value="<?php echo esc_attr( $days ); ?>" class="uappt-input-num" />
				<?php esc_html_e( '天沒來', 'ultimate-appointments' ); ?>
			</span>
		<?php UAPPT_Admin::field_close(); ?>

		<?php UAPPT_Admin::field_open( __( '人員', 'ultimate-appointments' ), 'uappt-filter-staff' ); ?>
			<select id="uappt-filter-staff" name="staff_id">
				<option value=""><?php esc_html_e( '所有人員的客人', 'ultimate-appointments' ); ?></option>
				<?php foreach ( $staff_list as $uappt_staff ) : ?>
					<option value="<?php echo esc_attr( $uappt_staff['id'] ); ?>" <?php selected( $filters['staff_id'], (int) $uappt_staff['id'] ); ?>>
						<?php echo esc_html( $uappt_staff['name'] ); ?><?php echo 'active' !== $uappt_staff['status'] ? esc_html__( '（已停用）', 'ultimate-appointments' ) : ''; ?>
					</option>
				<?php endforeach; ?>
			</select>
		<?php UAPPT_Admin::field_close(); ?>

		<?php UAPPT_Admin::field_open( '', '', 'uappt-field-check' ); ?>
			<label class="uappt-compare-toggle">
				<input type="checkbox" name="include_contacted" value="1" <?php checked( $filters['include_contacted'] ); ?> />
				<?php esc_html_e( '連最近聯絡過的一起顯示', 'ultimate-appointments' ); ?>
			</label>
		<?php UAPPT_Admin::field_close(); ?>

		<?php UAPPT_Admin::field_open( '', '', 'uappt-field-actions' ); ?>
			<?php submit_button( __( '套用', 'ultimate-appointments' ), 'secondary', '', false ); ?>
		<?php UAPPT_Admin::field_close(); ?>
	<?php UAPPT_Admin::filters_close(); ?>

	<p class="description">
		<?php
		printf(
			/* translators: 1: 人數 2: 天數 */
			esc_html__( '符合條件的有 %1$d 位（超過 %2$d 天沒來、而且沒有已確認的未來預約）。', 'ultimate-appointments' ),
			$uappt_total,
			(int) $days
		);
		?>
		<?php if ( $filters['staff_id'] ) : ?>
			<?php esc_html_e( '篩選人員時，「有沒有約下一次」仍然看整間店——客人跟別位人員約了也是約了。', 'ultimate-appointments' ); ?>
		<?php endif; ?>
	</p>

	<table class="widefat striped uappt-table">
		<thead>
			<tr>
				<th><?php esc_html_e( '客人', 'ultimate-appointments' ); ?></th>
				<th><?php esc_html_e( '上次到訪', 'ultimate-appointments' ); ?></th>
				<th><?php esc_html_e( '上次做什麼', 'ultimate-appointments' ); ?></th>
				<th><?php esc_html_e( '到訪次數', 'ultimate-appointments' ); ?></th>
				<th><?php esc_html_e( '累計消費', 'ultimate-appointments' ); ?></th>
				<th><?php esc_html_e( '聯絡', 'ultimate-appointments' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php if ( empty( $result['items'] ) ) : ?>
				<tr>
					<td colspan="6">
						<?php esc_html_e( '目前沒有符合條件的客人——所有人不是最近來過，就是已經約好下一次了。', 'ultimate-appointments' ); ?>
					</td>
				</tr>
			<?php endif; ?>

			<?php foreach ( $result['items'] as $uappt_row ) : ?>
				<?php
				$uappt_key      = UAPPT_Customer::parse_key( $uappt_row['ckey'] );
				$uappt_name     = '' !== $uappt_row['customer_name'] ? $uappt_row['customer_name'] : __( '（未留姓名）', 'ultimate-appointments' );
				$uappt_snoozed  = ! empty( $uappt_row['contacted_at'] );
				$uappt_total_spend = $uappt_row['service_spend'] + $uappt_row['product_spend'];
				?>
				<tr class="<?php echo $uappt_snoozed ? 'uappt-row-snoozed' : ''; ?>">
					<td data-label="<?php esc_attr_e( '客人', 'ultimate-appointments' ); ?>">
						<strong><?php echo esc_html( $uappt_name ); ?></strong>
						<?php if ( 'customer' === $uappt_key['type'] ) : ?>
							<?php // 有會員帳號的連過去看訂單紀錄——資料各自管好，入口互通。 ?>
							<a class="uappt-customer-link" href="<?php echo esc_url( admin_url( 'user-edit.php?user_id=' . (int) $uappt_row['customer_id'] ) ); ?>">
								<?php esc_html_e( '會員資料', 'ultimate-appointments' ); ?>
							</a>
						<?php else : ?>
							<span class="uappt-badge uappt-badge-muted"><?php esc_html_e( '無帳號', 'ultimate-appointments' ); ?></span>
						<?php endif; ?>
						<?php if ( '' !== $uappt_row['customer_phone'] ) : ?>
							<br />
							<?php // tel: 連結——這一頁的目的就是打電話，手機上點一下就撥出去。 ?>
							<a href="tel:<?php echo esc_attr( preg_replace( '/[^0-9+]/', '', $uappt_row['customer_phone'] ) ); ?>">
								<?php echo esc_html( $uappt_row['customer_phone'] ); ?>
							</a>
						<?php else : ?>
							<br /><span class="description"><?php esc_html_e( '沒有留電話', 'ultimate-appointments' ); ?></span>
						<?php endif; ?>
					</td>

					<td data-label="<?php esc_attr_e( '上次到訪', 'ultimate-appointments' ); ?>">
						<strong><?php echo esc_html( substr( $uappt_row['last_visit'], 0, 10 ) ); ?></strong>
						<br />
						<span class="description">
							<?php
							printf(
								/* translators: %d: 天數 */
								esc_html__( '%d 天前', 'ultimate-appointments' ),
								(int) $uappt_row['days_since']
							);
							?>
						</span>
					</td>

					<td data-label="<?php esc_attr_e( '上次做什麼', 'ultimate-appointments' ); ?>">
						<?php echo esc_html( '' !== $uappt_row['last_service'] ? $uappt_row['last_service'] : '—' ); ?>
					</td>

					<td data-label="<?php esc_attr_e( '到訪次數', 'ultimate-appointments' ); ?>">
						<?php echo esc_html( $uappt_row['visits'] ); ?>
						<br />
						<span class="description">
							<?php
							printf(
								/* translators: %s: 日期 */
								esc_html__( '自 %s', 'ultimate-appointments' ),
								esc_html( substr( $uappt_row['first_visit'], 0, 10 ) )
							);
							?>
						</span>
					</td>

					<td data-label="<?php esc_attr_e( '累計消費', 'ultimate-appointments' ); ?>">
						<strong><?php echo wp_kses_post( wc_price( $uappt_total_spend ) ); ?></strong>
						<br />
						<span class="description">
							<?php
							printf(
								/* translators: 1: 服務消費 2: 產品消費 */
								esc_html__( '服務 %1$s ／ 產品 %2$s', 'ultimate-appointments' ),
								wp_strip_all_tags( wc_price( $uappt_row['service_spend'] ) ),
								wp_strip_all_tags( wc_price( $uappt_row['product_spend'] ) )
							);
							?>
						</span>
					</td>

					<td class="uappt-cell-block" data-label="<?php esc_attr_e( '聯絡', 'ultimate-appointments' ); ?>">
						<?php if ( $uappt_snoozed ) : ?>
							<span class="uappt-badge uappt-badge-success">
								<?php
								printf(
									/* translators: %s: 日期 */
									esc_html__( '%s 已聯絡', 'ultimate-appointments' ),
									esc_html( substr( $uappt_row['contacted_at'], 0, 10 ) )
								);
								?>
							</span>
							<?php if ( '' !== $uappt_row['contact_note'] ) : ?>
								<br /><span class="description"><?php echo esc_html( $uappt_row['contact_note'] ); ?></span>
							<?php endif; ?>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
								<input type="hidden" name="action" value="uappt_clear_customer_contacted" />
								<input type="hidden" name="ckey" value="<?php echo esc_attr( $uappt_row['ckey'] ); ?>" />
								<?php echo wp_kses_post( uappt_customer_filter_fields( $filters ) ); ?>
								<?php wp_nonce_field( 'uappt_clear_customer_contacted' ); ?>
								<button type="submit" class="button-link"><?php esc_html_e( '取消標記', 'ultimate-appointments' ); ?></button>
							</form>
						<?php else : ?>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="uappt-contact-form">
								<input type="hidden" name="action" value="uappt_mark_customer_contacted" />
								<input type="hidden" name="ckey" value="<?php echo esc_attr( $uappt_row['ckey'] ); ?>" />
								<?php echo wp_kses_post( uappt_customer_filter_fields( $filters ) ); ?>
								<?php wp_nonce_field( 'uappt_mark_customer_contacted' ); ?>
								<input type="text" name="note" class="uappt-contact-note" placeholder="<?php esc_attr_e( '備註（選填）', 'ultimate-appointments' ); ?>" />
								<button type="submit" class="button"><?php esc_html_e( '標記已聯絡', 'ultimate-appointments' ); ?></button>
							</form>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>

	<?php $uappt_total_pages = (int) ceil( $uappt_total / UAPPT_Admin::LAPSED_PER_PAGE ); ?>
	<?php if ( $uappt_total_pages > 1 ) : ?>
		<div class="tablenav">
			<div class="tablenav-pages">
				<?php
				echo wp_kses_post(
					paginate_links(
						array(
							'base'      => add_query_arg( 'paged', '%#%' ),
							'format'    => '',
							'current'   => max( 1, (int) $filters['paged'] ),
							'total'     => $uappt_total_pages,
							'prev_text' => __( '&laquo; 上一頁', 'ultimate-appointments' ),
							'next_text' => __( '下一頁 &raquo;', 'ultimate-appointments' ),
						)
					)
				);
				?>
			</div>
		</div>
	<?php endif; ?>

	<p class="description uappt-hint">
		<?php esc_html_e( '「累計消費」的兩個數字來源不同，這是刻意的：服務消費來自預約紀錄（不管有沒有開訂單，手動建單可以不開），產品消費來自已付款的訂單項目。所以兩者加起來不會剛好等於這位客人在 WooCommerce 的訂單總額——訂單總額還含運費與稅。', 'ultimate-appointments' ); ?>
	</p>
	<p class="description uappt-hint">
		<?php esc_html_e( '沒有會員帳號的客人用電話當識別；連電話都沒留的，每一筆預約會各自算成一位客人——同名同姓不敢合併，寧可重複出現，也不要把兩位不同的客人當成同一個人。', 'ultimate-appointments' ); ?>
	</p>
