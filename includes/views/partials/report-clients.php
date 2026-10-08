<?php
/**
 * Partial：報表「客人」頁籤。
 *
 * 四個區塊：**客源結構**（新客／回頭客／該聯絡）、**留客指標**（回店預約率、
 * 回訪週期）、**零售佔比**（產品對服務的營收比重）、**消費貢獻排行**（Top 20）。
 *
 * ⚠️ 第一塊刻意不叫「客人來源」：階段 4 會加「預約來源」（線上自助 vs 電話／
 * 現場建單），兩個「來源」在同一份報表裡撞名會讓人分不清誰是誰。「客源結構」
 * 講的是新客與回頭客的組成，是美業／零售管理的標準用詞。
 *
 * 傳入變數：$report、$uappt_pct、$date_from、$date_to。
 *
 * @package Ultimate_Appointments
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$uappt_extra     = $report['extra'];
$uappt_customers = $uappt_extra['customers'];
$uappt_rebook    = $uappt_extra['rebooking'];
$uappt_gaps      = $uappt_extra['gaps'];
$uappt_total_new = $uappt_customers['new'] + $uappt_customers['returning'];

$uappt_service_rev = (float) $uappt_extra['service_revenue'];
$uappt_product_rev = (float) $uappt_extra['product_revenue'];
$uappt_rev_total   = $uappt_service_rev + $uappt_product_rev;
?>
<div class="uappt-panel">
	<?php UAPPT_Admin::panel_head( 'users', __( '客源結構', 'ultimate-appointments' ) ); ?>
	<div class="uappt-panel-body">
		<div class="uappt-dash-cards">
			<?php // 「不重複客人」在頁面上方的共用卡片列已經有一張同名同值的，
			// 這裡不再重複一次（v2.60.0）。 ?>
			<div class="uappt-dash-card is-static">
				<span class="uappt-dash-card-value"><?php echo esc_html( $uappt_customers['new'] ); ?></span>
				<span class="uappt-dash-card-label"><?php esc_html_e( '新客', 'ultimate-appointments' ); ?></span>
			</div>
			<div class="uappt-dash-card is-static">
				<span class="uappt-dash-card-value"><?php echo esc_html( $uappt_customers['returning'] ); ?></span>
				<span class="uappt-dash-card-label"><?php esc_html_e( '回頭客', 'ultimate-appointments' ); ?></span>
			</div>
			<div class="uappt-dash-card is-static">
				<span class="uappt-dash-card-value"><?php echo esc_html( $uappt_total_new > 0 ? $uappt_pct( $uappt_customers['returning'] / $uappt_total_new ) : '—' ); ?></span>
				<span class="uappt-dash-card-label"><?php esc_html_e( '回頭率', 'ultimate-appointments' ); ?></span>
			</div>
			<div class="uappt-dash-card is-static <?php echo $uappt_extra['lapsed'] > 0 ? 'is-warning' : ''; ?>">
				<span class="uappt-dash-card-value"><?php echo esc_html( $uappt_extra['lapsed'] ); ?></span>
				<span class="uappt-dash-card-label">
					<?php // 連到「回訪管理」的入口：那個模組關著的時候整個區塊不存在，
					// 連過去只會被 current_section() 默默退回今日營運。 ?>
					<?php if ( UAPPT_Modules::enabled( 'customer_followup' ) ) : ?>
					<a href="<?php echo esc_url( UAPPT_Admin::url( 'customers' ) ); ?>">
						<?php esc_html_e( '目前該聯絡 →', 'ultimate-appointments' ); ?>
					</a>
					<?php endif; ?>
				</span>
			</div>
		</div>
		<p class="description">
			<?php esc_html_e( '「新客」是這位客人在本站的第一筆預約就落在這段期間內。篩選了特定人員時，「第一次」仍然以整間店為準——不然老客人換一位服務人員就會被算成新客，回頭率會完全失真。', 'ultimate-appointments' ); ?>
		</p>
		<p class="description">
			<?php esc_html_e( '⚠️ 「目前該聯絡」是「到今天為止」的快照，不受上面的期間影響——流失是持續的狀態，不是某段期間發生的事。點進去就是回訪管理的名單。', 'ultimate-appointments' ); ?>
		</p>
	</div>
</div>

<div class="uappt-panel">
	<?php UAPPT_Admin::panel_head( 'calendar', __( '留客指標', 'ultimate-appointments' ) ); ?>
	<div class="uappt-panel-body">
		<div class="uappt-dash-cards">
			<div class="uappt-dash-card is-static">
				<span class="uappt-dash-card-value"><?php echo esc_html( $uappt_pct( $uappt_rebook['rate'] ) ); ?></span>
				<span class="uappt-dash-card-label">
					<?php
					printf(
						/* translators: 1: 回店筆數 2: 完成筆數 */
						esc_html__( '回店預約率（%1$d／%2$d）', 'ultimate-appointments' ),
						(int) $uappt_rebook['rebooked'],
						(int) $uappt_rebook['completed']
					);
					?>
				</span>
			</div>
			<div class="uappt-dash-card is-static">
				<span class="uappt-dash-card-value"><?php echo esc_html( $uappt_pct( $uappt_rebook['rate_same_day'] ) ); ?></span>
				<span class="uappt-dash-card-label"><?php esc_html_e( '其中當天就約好', 'ultimate-appointments' ); ?></span>
			</div>
			<div class="uappt-dash-card is-static">
				<span class="uappt-dash-card-value">
					<?php
					echo $uappt_gaps['samples'] > 0
						? esc_html( $uappt_gaps['median'] )
						: '—';
					?>
				</span>
				<span class="uappt-dash-card-label"><?php esc_html_e( '回訪週期中位數（天）', 'ultimate-appointments' ); ?></span>
			</div>

			<?php
			// 新客的首購 → 回購。放在「回訪週期中位數」旁邊，因為兩者長得像
			// 但問的不是同一件事：上面那個是**熟客**撐出來的（他們本來就會
			// 回來），這個才是「第一次上門的人有沒有第二次」——留客真正的
			// 那道關卡。
			$uappt_new_repeat = isset( $uappt_extra['new_repeat'] ) ? $uappt_extra['new_repeat'] : null;
			?>
			<?php if ( $uappt_new_repeat && $uappt_new_repeat['total'] > 0 ) : ?>
				<div class="uappt-dash-card is-static">
					<span class="uappt-dash-card-value"><?php echo esc_html( $uappt_pct( $uappt_new_repeat['rate'] ) ); ?></span>
					<span class="uappt-dash-card-label">
						<?php
						printf(
							/* translators: 1: 已回第二次的人數 2: 這段期間的新客總數 */
							esc_html__( '新客回購率（%1$d／%2$d）', 'ultimate-appointments' ),
							(int) $uappt_new_repeat['repeated'],
							(int) $uappt_new_repeat['total']
						);
						?>
					</span>
					<?php if ( $uappt_new_repeat['repeated'] > 0 ) : ?>
						<span class="uappt-dash-card-sub">
							<?php
							printf(
								/* translators: %s: 天數 */
								esc_html__( '首購→回購中位數 %s 天', 'ultimate-appointments' ),
								esc_html( rtrim( rtrim( number_format( (float) $uappt_new_repeat['median'], 1 ), '0' ), '.' ) )
							);
							?>
						</span>
					<?php endif; ?>
				</div>
			<?php endif; ?>
			<div class="uappt-dash-card is-static">
				<span class="uappt-dash-card-value">
					<?php echo $uappt_gaps['samples'] > 0 ? esc_html( $uappt_gaps['average'] ) : '—'; ?>
				</span>
				<span class="uappt-dash-card-label">
					<?php
					printf(
						/* translators: %d: 樣本數 */
						esc_html__( '平均（天，%d 組間隔）', 'ultimate-appointments' ),
						(int) $uappt_gaps['samples']
					);
					?>
				</span>
			</div>
		</div>
		<p class="description">
			<?php esc_html_e( '「回店預約率」是這段期間做完的服務裡，客人在服務開始之後 7 天內又訂了下一次的比例。這是美業最重要的領先指標——業績是回頭看，這個是往前看：客人離店時有沒有約下一次，直接決定下個月的營收底線。', 'ultimate-appointments' ); ?>
		</p>
		<p class="description">
			<?php esc_html_e( '⚠️ 條件是「什麼時候訂的」不是「什麼時候來」。所以客人一次把兩次都預約掉的情況不會算進來——那也是好事，但屬於另一種行為，混在一起這個數字就沒辦法拿來檢討櫃檯的話術了。', 'ultimate-appointments' ); ?>
		</p>
		<p class="description">
			<?php esc_html_e( '「新客回購率」跟上面的「回店預約率」問的不是同一件事：回店預約率看的是所有客人（含熟客）離店前有沒有約下一次；新客回購率看的是第一次上門的人到底有沒有第二次——留客真正的那道關卡。⚠️ 沒回來的那幾位不等於流失：期間快結束才第一次上門的人本來就還沒機會回來，而且第二次不限期間，這個月的新客下個月才回來一樣算。', 'ultimate-appointments' ); ?>
		</p>
		<p class="description">
			<?php esc_html_e( '回訪週期**看中位數不要看平均**：少數隔半年才回來一次的客人會把平均拉爆，照平均去設提醒會晚一個月才發。只計算間隔一年以內的——超過一年的是流失客，不是回頭客。', 'ultimate-appointments' ); ?>
		</p>
	</div>
</div>

<div class="uappt-panel">
	<?php UAPPT_Admin::panel_head( 'tag', __( '零售佔比', 'ultimate-appointments' ) ); ?>
	<div class="uappt-panel-body">
		<div class="uappt-dash-cards">
			<div class="uappt-dash-card is-static">
				<span class="uappt-dash-card-value"><?php echo wp_kses_post( wc_price( $uappt_service_rev ) ); ?></span>
				<span class="uappt-dash-card-label"><?php esc_html_e( '服務業績', 'ultimate-appointments' ); ?></span>
			</div>
			<div class="uappt-dash-card is-static">
				<span class="uappt-dash-card-value"><?php echo wp_kses_post( wc_price( $uappt_product_rev ) ); ?></span>
				<span class="uappt-dash-card-label"><?php esc_html_e( '產品業績', 'ultimate-appointments' ); ?></span>
			</div>
			<div class="uappt-dash-card is-static">
				<span class="uappt-dash-card-value">
					<?php echo esc_html( $uappt_rev_total > 0 ? $uappt_pct( $uappt_product_rev / $uappt_rev_total ) : '—' ); ?>
				</span>
				<span class="uappt-dash-card-label"><?php esc_html_e( '產品佔總營收', 'ultimate-appointments' ); ?></span>
			</div>
		</div>

		<?php if ( $uappt_rev_total > 0 ) : ?>
			<div class="uappt-ratio-bar" role="img" aria-label="<?php echo esc_attr( sprintf( /* translators: 1: 服務佔比 2: 產品佔比 */ __( '服務 %1$s、產品 %2$s', 'ultimate-appointments' ), $uappt_pct( $uappt_service_rev / $uappt_rev_total ), $uappt_pct( $uappt_product_rev / $uappt_rev_total ) ) ); ?>">
				<span class="uappt-ratio-service" style="width: <?php echo esc_attr( round( $uappt_service_rev / $uappt_rev_total * 100, 1 ) ); ?>%;"></span>
				<span class="uappt-ratio-product" style="width: <?php echo esc_attr( round( $uappt_product_rev / $uappt_rev_total * 100, 1 ) ); ?>%;"></span>
			</div>
		<?php endif; ?>

		<p class="description">
			<?php esc_html_e( '美業的標準指標之一：客人來做服務時，有沒有順便帶走居家保養品。比例偏低通常不是產品不好，是現場沒有推薦的習慣。', 'ultimate-appointments' ); ?>
		</p>
		<p class="description">
			<?php esc_html_e( '⚠️ 兩個數字的時間基準不一樣，而且沒辦法一樣：服務業績以「服務日期」為準，產品業績以「下單日期」為準（訂單沒有服務日期這種東西）。月底那幾天可能跨月，其餘時間影響很小。另外產品業績算的是已付款訂單裡的非預約項目，不含運費與稅。', 'ultimate-appointments' ); ?>
		</p>
		<p class="description">
			<?php esc_html_e( '⚠️ 篩選特定人員時，產品業績不會跟著變——產品賣給誰記在誰頭上目前沒有依據。要做到那個需要靠「同一張訂單上的服務人員」去分攤，那是後面的階段。', 'ultimate-appointments' ); ?>
		</p>
	</div>
</div>

<div class="uappt-panel">
	<?php UAPPT_Admin::panel_head( 'user-check', __( '消費貢獻排行', 'ultimate-appointments' ) ); ?>
	<?php
	// 前 20 名加總沒有意義（不是全體，只是被截斷的一段），關掉合計列。
	$uappt_no_totals = true;
	require UAPPT_PLUGIN_DIR . 'includes/views/partials/report-table.php';
	?>
	<div class="uappt-panel-body">
		<p class="description">
			<?php esc_html_e( '只列前 20 位。「服務消費」來自預約紀錄（不管有沒有開訂單），「產品消費」來自這位客人已付款訂單裡的非預約項目——沒有會員帳號的客人查不到訂單，產品消費會是 0。', 'ultimate-appointments' ); ?>
		</p>
	</div>
</div>
