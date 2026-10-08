<?php
/**
 * Partial：一組水平長條（分布圖）。
 *
 * 取消前置時間與預約前置期共用。純 CSS，沿用「不為了這種圖多載一個 JS 套件」
 * 的既有判斷（見 CLAUDE.md 的每日走勢那段）。
 *
 * 傳入變數（**刻意用自己的名字，不借用呼叫端既有的變數**：同一頁用了兩次，
 * 借用的話第二次會把第一次的資料蓋掉，而那種 bug 只在「剛好順序對」的時候
 * 看不出來）：
 *
 * - $uappt_bar_items  要印的項目（key => 標籤），順序就是顯示順序
 * - $uappt_bar_counts 每個 key 的數量
 * - $uappt_bar_money  選配，true ＝ 數值是金額（印成貨幣而不是「N 筆」）
 *
 * @package Ultimate_Appointments
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$uappt_bar_data  = isset( $uappt_bar_counts ) ? (array) $uappt_bar_counts : array();
$uappt_bar_total = array_sum( $uappt_bar_data );
$uappt_bar_is_money = ! empty( $uappt_bar_money );
?>
<ul class="uappt-bars">
	<?php foreach ( $uappt_bar_items as $uappt_bar_key => $uappt_bar_label ) : ?>
		<?php
		$uappt_bar_n     = isset( $uappt_bar_data[ $uappt_bar_key ] ) ? ( $uappt_bar_is_money ? (float) $uappt_bar_data[ $uappt_bar_key ] : (int) $uappt_bar_data[ $uappt_bar_key ] ) : 0;
		$uappt_bar_ratio = $uappt_bar_total > 0 ? $uappt_bar_n / $uappt_bar_total : 0;
		?>
		<?php // 全部是 0 的項目不印：一排空長條只是雜訊。 ?>
		<?php if ( $uappt_bar_n <= 0 ) : ?>
			<?php continue; ?>
		<?php endif; ?>
		<li class="uappt-bar-row">
			<span class="uappt-bar-label"><?php echo esc_html( $uappt_bar_label ); ?></span>
			<span class="uappt-bar-track">
				<span class="uappt-bar-fill" style="width: <?php echo esc_attr( round( $uappt_bar_ratio * 100, 1 ) ); ?>%;"></span>
			</span>
			<span class="uappt-bar-value">
				<?php if ( $uappt_bar_is_money ) : ?>
					<?php
					printf(
						/* translators: 1: 金額 2: 佔比 */
						esc_html__( '%1$s（%2$s）', 'ultimate-appointments' ),
						wp_strip_all_tags( wc_price( $uappt_bar_n ) ),
						esc_html( round( $uappt_bar_ratio * 100, 1 ) . '%' )
					);
					?>
				<?php else : ?>
					<?php
					printf(
						/* translators: 1: 筆數 2: 佔比 */
						esc_html__( '%1$d 筆（%2$s）', 'ultimate-appointments' ),
						$uappt_bar_n,
						esc_html( round( $uappt_bar_ratio * 100, 1 ) . '%' )
					);
					?>
				<?php endif; ?>
			</span>
		</li>
	<?php endforeach; ?>
</ul>
