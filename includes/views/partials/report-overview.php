<?php
/**
 * Partial：報表「總覽」頁籤的營運指標與每日走勢。
 *
 * v2.60.0 把「新客／回頭客／回頭率」那三張卡片搬去「收入結構」了——那一頁
 * 講的就是「錢從哪來」，而且那邊連**金額**一起講（人數相同、金額差四倍是
 * 常態，只看人數會做出相反的決策）。這裡留下的是純粹描述「這段期間怎麼運轉」
 * 的三個指標。
 *
 * 傳入變數：$report、$uappt_totals、$uappt_pct。
 *
 * @package Ultimate_Appointments
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

?>
<div class="uappt-panel">
	<?php UAPPT_Admin::panel_head( 'clock', __( '這段期間怎麼運轉', 'ultimate-appointments' ) ); ?>
	<div class="uappt-panel-body">
		<div class="uappt-dash-cards">
			<div class="uappt-dash-card is-static">
				<span class="uappt-dash-card-value"><?php echo esc_html( $uappt_pct( $uappt_totals['utilization'] ) ); ?></span>
				<span class="uappt-dash-card-label"><?php esc_html_e( '時段利用率', 'ultimate-appointments' ); ?></span>
				<span class="uappt-dash-card-sub"><?php esc_html_e( '已服務時數 ÷ 可排時數', 'ultimate-appointments' ); ?></span>
			</div>
			<div class="uappt-dash-card is-static">
				<span class="uappt-dash-card-value"><?php echo esc_html( $uappt_totals['person_count'] ); ?></span>
				<span class="uappt-dash-card-label"><?php esc_html_e( '服務人次', 'ultimate-appointments' ); ?></span>
				<span class="uappt-dash-card-sub"><?php esc_html_e( '團體預約一次算多位', 'ultimate-appointments' ); ?></span>
			</div>
			<div class="uappt-dash-card is-static">
				<span class="uappt-dash-card-value"><?php echo esc_html( $uappt_pct( $uappt_totals['request_rate'] ) ); ?></span>
				<span class="uappt-dash-card-label"><?php esc_html_e( '指定人員比率', 'ultimate-appointments' ); ?></span>
				<span class="uappt-dash-card-sub"><?php esc_html_e( '客人自己挑了服務人員', 'ultimate-appointments' ); ?></span>
			</div>
		</div>
		<p class="description">
			<?php esc_html_e( '「時段利用率」的分母是人員的營業時間 × 同時可服務人數，所以它低不一定是生意差——也可能是班排得太滿。指定人員比率高代表客人跟師傅綁定得深，回訪穩定但人員請假的衝擊也大。', 'ultimate-appointments' ); ?>
		</p>
	</div>
</div>

<?php
// 每日趨勢。刻意用純 CSS 的長條，不引入圖表套件：這裡要回答的只是「哪幾天
// 比較旺、哪幾天是空的」，一排高低不同的長條就夠了，為此多載一個 JS 套件
// （還要處理它的無障礙與列印）不划算。
// ⚠️ 用 extra['daily'] 而不是 $report['rows']：顆粒切到「每週」「每月」時
// rows 就不是逐日的了，長條會變成 13 根或 3 根——而這張圖存在的意義就是看
// 「哪幾天旺、哪幾天是空的」那個形狀，三根長條沒有形狀可言。
$uappt_daily = isset( $report['extra']['daily'] ) ? $report['extra']['daily'] : $report['rows'];

$uappt_max_revenue = 0.0;
foreach ( $uappt_daily as $uappt_row ) {
	$uappt_max_revenue = max( $uappt_max_revenue, $uappt_row['revenue'] );
}
?>
<?php if ( $uappt_max_revenue > 0 ) : ?>
	<div class="uappt-panel">
		<?php UAPPT_Admin::panel_head( 'pie-chart', __( '每日業績走勢', 'ultimate-appointments' ) ); ?>
		<div class="uappt-panel-body">
			<div class="uappt-trend">
				<?php foreach ( $uappt_daily as $uappt_row ) : ?>
					<?php
					$uappt_height = (int) round( ( $uappt_row['revenue'] / $uappt_max_revenue ) * 100 );
					$uappt_title  = sprintf(
						/* translators: 1: 日期 2: 業績 3: 操作筆數 */
						__( '%1$s：%2$s，%3$d 筆', 'ultimate-appointments' ),
						$uappt_row['period'],
						wp_strip_all_tags( wc_price( $uappt_row['revenue'] ) ),
						$uappt_row['op_count']
					);
					?>
					<span class="uappt-trend-bar" title="<?php echo esc_attr( $uappt_title ); ?>">
						<span class="uappt-trend-fill" style="height: <?php echo esc_attr( max( 2, $uappt_height ) ); ?>%;"></span>
						<span class="uappt-trend-label"><?php echo esc_html( (int) substr( $uappt_row['period'], 8, 2 ) ); ?></span>
					</span>
				<?php endforeach; ?>
			</div>
			<p class="description">
				<?php esc_html_e( '長條高度是當日業績相對於期間最高值的比例，滑鼠移上去看確切數字。上面的表格可以切換成每週或每月。', 'ultimate-appointments' ); ?>
			</p>
		</div>
	</div>
<?php endif; ?>
