<?php
/**
 * Partial：報表「收入結構」頁籤（v2.60.0）。
 *
 * 一個問題、幾種切法：**誰付的**（新客／回頭客）、**賣什麼**（服務分類 →
 * 服務項目）、**還賣了什麼**（零售商品）、**怎麼收的**（收款方式）。舊版散在
 * 「營收」頁尾、「總覽」的客人組成、以及獨立的「收款」頁籤裡，要回答「這個月
 * 多出來的錢是新客帶來的、還是熟客加購？」得在三個地方之間來回切換再心算。
 *
 * 順序刻意由粗到細：老闆先問「客人哪來的」，再問「他們買了什麼」，最後才是
 * 「錢怎麼進來的」。拆解方式的四個選項也是同一個邏輯（分類 → 單品 → 零售 →
 * 收款）。
 *
 * 傳入變數：$report（主表格，由 $view 決定是哪一張）、$uappt_pct、$uappt_base_url、
 * $current_tab、$preset、$export_url。
 *
 * @package Ultimate_Appointments
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$uappt_income_view = isset( $report['extra']['view'] ) ? $report['extra']['view'] : '';
$uappt_by_type     = isset( $report['extra']['customers'] ) ? $report['extra']['customers'] : null;
?>

<?php // ---------- 一、誰付的：新客 vs 回頭客 ---------- ?>
<?php if ( $uappt_by_type ) : ?>
	<?php
	$uappt_new       = $uappt_by_type['new'];
	$uappt_returning = $uappt_by_type['returning'];
	$uappt_type_all  = $uappt_new['revenue'] + $uappt_returning['revenue'];
	?>
	<div class="uappt-panel">
		<?php UAPPT_Admin::panel_head( 'users', __( '新客與回頭客', 'ultimate-appointments' ) ); ?>
		<div class="uappt-panel-body">
			<?php
			// ⚠️ 這一段的重點是**金額**，不是人數。舊版只有「新客 16 位、回頭客
			// 35 位」，但老闆要決定的是「預算放在拉新還是做回訪」，而那取決於錢
			// ——16 位新客帶來 12 萬跟帶來 3 萬，結論完全相反。所以人數降級成
			// 副標，金額與佔比才是主角。
			$uappt_bar_items = array(
				'new'       => __( '新客', 'ultimate-appointments' ),
				'returning' => __( '回頭客', 'ultimate-appointments' ),
			);
			$uappt_bar_counts = array(
				'new'       => $uappt_new['revenue'],
				'returning' => $uappt_returning['revenue'],
			);
			$uappt_bar_money  = true;
			require UAPPT_PLUGIN_DIR . 'includes/views/partials/report-bars.php';
			$uappt_bar_money = false;
			?>
			<div class="uappt-dash-cards">
				<?php
				foreach ( array(
					array( __( '新客', 'ultimate-appointments' ), $uappt_new ),
					array( __( '回頭客', 'ultimate-appointments' ), $uappt_returning ),
				) as $uappt_type ) :
					list( $uappt_type_label, $uappt_type_data ) = $uappt_type;
					?>
					<div class="uappt-dash-card is-static">
						<span class="uappt-dash-card-value"><?php echo wp_kses_post( wc_price( $uappt_type_data['revenue'] ) ); ?></span>
						<span class="uappt-dash-card-label">
							<?php
							printf(
								/* translators: 1: 新客／回頭客 2: 佔總業績的百分比 */
								esc_html__( '%1$s業績（佔 %2$s）', 'ultimate-appointments' ),
								esc_html( $uappt_type_label ),
								esc_html( $uappt_type_all > 0 ? $uappt_pct( $uappt_type_data['revenue'] / $uappt_type_all ) : '—' )
							);
							?>
						</span>
						<span class="uappt-dash-card-sub">
							<?php
							printf(
								/* translators: 1: 人數 2: 平均每位客人的消費 */
								esc_html__( '%1$d 位 ／ 人均 %2$s', 'ultimate-appointments' ),
								(int) $uappt_type_data['count'],
								$uappt_type_data['count'] > 0
									? wp_strip_all_tags( wc_price( $uappt_type_data['revenue'] / $uappt_type_data['count'] ) )
									: esc_html__( '—', 'ultimate-appointments' )
							);
							?>
						</span>
					</div>
				<?php endforeach; ?>
			</div>
			<p class="description">
				<?php esc_html_e( '「人均」比「人數」更值得看：回頭客的人均通常是新客的好幾倍，這個差距就是回訪經營的價值。新客人均偏低是正常的（第一次多半先試便宜的項目），但如果連續幾個月都沒往上走，代表新客沒有被留下來。', 'ultimate-appointments' ); ?>
			</p>
		</div>
	</div>
<?php endif; ?>

<?php // ---------- 二～四、賣什麼／賣了哪些商品／怎麼收的 ---------- ?>
<?php
// 四種拆解共用主表格的位置，用連結切換。理由跟「顯示顆粒」一樣：一次看一張
// 才讀得下去，而且 CSV 匯出本來就一次一份——畫面上並排四張、匯出卻只有一張，
// 使用者會以為匯出壞了。
?>
<p class="uappt-period-presets uappt-period-presets--views">
	<span class="uappt-period-presets-label"><?php esc_html_e( '拆解方式', 'ultimate-appointments' ); ?></span>
	<?php foreach ( UAPPT_Admin::income_views() as $uappt_v => $uappt_v_label ) : ?>
		<a
			href="<?php echo esc_url( add_query_arg( array( 'tab' => 'income', 'preset' => $preset, 'view' => $uappt_v ), $uappt_base_url ) ); ?>"
			class="button<?php echo $uappt_income_view === $uappt_v ? ' button-primary' : ''; ?>"
		><?php echo esc_html( $uappt_v_label ); ?></a>
	<?php endforeach; ?>
</p>

<?php if ( 'payments' === $uappt_income_view ) : ?>

	<?php
	// 佔比長條：「刷卡佔三成」看長條一眼就懂，看一欄百分比要先在心裡排序。
	// 兩種以上才畫——只有一種的話長條必然滿格，不帶任何資訊。
	$uappt_bar_items  = array();
	$uappt_bar_counts = array();
	foreach ( $report['rows'] as $uappt_pay_row ) {
		if ( $uappt_pay_row['revenue'] <= 0 ) {
			continue;
		}
		$uappt_bar_items[ $uappt_pay_row['payment_slug'] ]  = $uappt_pay_row['payment_name'];
		$uappt_bar_counts[ $uappt_pay_row['payment_slug'] ] = $uappt_pay_row['revenue'];
	}
	$uappt_bar_money = true;
	?>
	<?php if ( count( $uappt_bar_items ) > 1 ) : ?>
		<div class="uappt-panel">
			<?php UAPPT_Admin::panel_head( 'credit-card', __( '收款方式佔比', 'ultimate-appointments' ) ); ?>
			<div class="uappt-panel-body">
				<?php require UAPPT_PLUGIN_DIR . 'includes/views/partials/report-bars.php'; ?>
				<p class="description">
					<?php esc_html_e( '刷卡佔比越高，帳面上的業績跟實際入帳的時間差就越大（刷卡通常隔幾天才撥款），排現金流時要把這件事算進去。', 'ultimate-appointments' ); ?>
				</p>
			</div>
		</div>
		<?php $uappt_bar_money = false; ?>
	<?php endif; ?>

	<?php require UAPPT_PLUGIN_DIR . 'includes/views/partials/report-table.php'; ?>

	<?php
	// 「未指定」有多少。這是這張表最重要的品質指標：數字大就代表統計本身
	// 不可信，要先去補資料而不是先看佔比。
	$uappt_unspecified = 0.0;
	foreach ( $report['rows'] as $uappt_pay_row ) {
		if ( '' === $uappt_pay_row['payment_slug'] ) {
			$uappt_unspecified = (float) $uappt_pay_row['revenue'];
		}
	}
	?>
	<?php if ( $uappt_unspecified > 0 && $report['totals']['revenue'] > 0 ) : ?>
		<div class="notice notice-warning inline">
			<p>
				<?php
				printf(
					/* translators: 1: 未指定收款方式的業績 2: 佔總業績的百分比 */
					esc_html__( '有 %1$s（%2$s）的業績沒有記錄收款方式，這部分的手續費一律算 0。手動建單時請順手選一下；線上訂單則要到「設定 ▸ 收款設定」把金流對應到收款方式。', 'ultimate-appointments' ),
					wp_strip_all_tags( wc_price( $uappt_unspecified ) ),
					esc_html( $uappt_pct( $uappt_unspecified / $report['totals']['revenue'] ) )
				);
				?>
			</p>
		</div>
	<?php endif; ?>

	<?php if ( ! UAPPT_Payment::has_fees() ) : ?>
		<div class="notice notice-info inline">
			<p>
				<?php
				printf(
					/* translators: %s: 指向設定頁的連結 */
					esc_html__( '還沒有設定任何手續費率，所以手續費全部是 0。填入跟收單行或金流公司談定的費率之後，這一頁才會算出實收淨額：%s。', 'ultimate-appointments' ),
					'<a href="' . esc_url( UAPPT_Admin::url( 'settings', array( 'tab' => 'payment' ) ) ) . '">' . esc_html__( '設定 ▸ 收款設定', 'ultimate-appointments' ) . '</a>'
				);
				?>
			</p>
		</div>
	<?php endif; ?>

<?php elseif ( 'retail' === $uappt_income_view ) : ?>

	<?php
	// ⚠️ 這張表跟同一頁其他所有數字**不在同一條時間軸上**，一定要先講，
	// 不能只寫在頁尾的名詞解釋裡——不然使用者會直接把它加到服務業績上。
	?>
	<div class="notice notice-info inline">
		<p>
			<?php esc_html_e( '⚠️ 零售是以「訂單建立日期」統計的，這一頁其他數字則是以「服務日期」為準。客人今天買了保養品、下週才來做臉，兩筆會落在不同的期間裡——所以這張表的合計不能直接加到上面的業績上。另外零售不吃人員篩選：商品賣給誰算誰的業績目前沒有依據。', 'ultimate-appointments' ); ?>
		</p>
	</div>

	<?php require UAPPT_PLUGIN_DIR . 'includes/views/partials/report-table.php'; ?>

	<?php if ( empty( $report['rows'] ) ) : ?>
		<p class="description">
			<?php esc_html_e( '這段期間沒有賣出任何非預約的商品。要在同一張訂單裡加賣保養品，可以在後台訂單裡直接新增項目。', 'ultimate-appointments' ); ?>
		</p>
	<?php endif; ?>

<?php else : ?>

	<?php require UAPPT_PLUGIN_DIR . 'includes/views/partials/report-table.php'; ?>

	<?php if ( '' === $uappt_income_view ) : ?>
		<p class="description">
			<?php esc_html_e( '分類來自 WooCommerce 的商品分類。一個商品掛在多個分類時固定歸在第一個（依分類 ID 排序）——彙總表每一筆只能算一次，不然合計會超過業績本身。要調整歸屬請改商品的分類設定。', 'ultimate-appointments' ); ?>
		</p>
	<?php elseif ( count( $report['rows'] ) > 1 ) : ?>
		<p class="description">
			<?php esc_html_e( '「業績佔比」的分母是這段期間的服務業績總額（不含零售商品）。集中在一兩個項目時要留意：那個項目的師傅請假或材料斷貨，整月業績就會跟著掉。', 'ultimate-appointments' ); ?>
		</p>
	<?php endif; ?>

<?php endif; ?>
