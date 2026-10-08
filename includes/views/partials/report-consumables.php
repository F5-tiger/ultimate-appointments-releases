<?php
/**
 * Partial：報表「耗材」頁籤。
 *
 * 這一頁的欄位型別跟其他頁籤差太多（數量帶單位、-1 是哨兵值），沒有共用
 * report-table.php——硬套共用表格會讓那支多出一堆只有這一頁用得到的特例。
 *
 * 傳入變數：$report。
 *
 * @package Ultimate_Appointments
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<p class="description">
	<?php esc_html_e( '「服務用量」是這段期間因為服務而扣掉的量（被還原的預約已經扣回去，不會重複計算）。「盤點差異」是帳面與實際的落差：負數代表實際比帳面少，那就是耗損、漏記或被拿走。', 'ultimate-appointments' ); ?>
</p>
<p class="description">
	<?php esc_html_e( '⚠️ 這一頁是以「異動發生的時間」計算（庫存什麼時候進出），其他頁籤的「材料成本」則是以「服務發生的時間」計算（那一天的成本對應那一天的營收）。平常兩者是同一天——扣帳就發生在按下「完成」的當下；只有事後才補標記完成的預約，兩邊才會落在不同的日子。', 'ultimate-appointments' ); ?>
</p>

<div class="uappt-table-scroll">
<table class="widefat striped uappt-table uappt-table--matrix">
	<thead>
		<tr>
			<?php foreach ( $report['columns'] as $uappt_col ) : ?>
				<th><?php echo esc_html( $uappt_col ); ?></th>
			<?php endforeach; ?>
		</tr>
	</thead>
	<tbody>
		<?php if ( empty( $report['rows'] ) ) : ?>
			<tr><td colspan="<?php echo count( $report['columns'] ); ?>"><?php esc_html_e( '這段期間沒有任何耗材異動。還沒建立耗材的話，先到「耗材管理」設定。', 'ultimate-appointments' ); ?></td></tr>
		<?php endif; ?>

		<?php foreach ( $report['rows'] as $uappt_row ) : ?>
			<tr>
				<td data-label="<?php esc_attr_e( '耗材', 'ultimate-appointments' ); ?>"><?php echo esc_html( $uappt_row['consumable_name'] ); ?></td>
				<td data-label="<?php esc_attr_e( '單位', 'ultimate-appointments' ); ?>"><?php echo esc_html( $uappt_row['unit'] ); ?></td>
				<td data-label="<?php esc_attr_e( '服務用量', 'ultimate-appointments' ); ?>"><?php echo esc_html( UAPPT_Consumable::format_qty( $uappt_row['used'] ) ); ?></td>
				<td data-label="<?php esc_attr_e( '材料成本', 'ultimate-appointments' ); ?>"><?php echo wp_kses_post( wc_price( $uappt_row['cost'] ) ); ?></td>
				<td data-label="<?php esc_attr_e( '進貨', 'ultimate-appointments' ); ?>"><?php echo esc_html( UAPPT_Consumable::format_qty( $uappt_row['restocked'] ) ); ?></td>
				<td data-label="<?php esc_attr_e( '報廢', 'ultimate-appointments' ); ?>"><?php echo esc_html( UAPPT_Consumable::format_qty( $uappt_row['wasted'] ) ); ?></td>
				<td data-label="<?php esc_attr_e( '盤點差異', 'ultimate-appointments' ); ?>">
					<span class="<?php echo $uappt_row['adjusted'] < 0 ? 'uappt-text-error' : ''; ?>">
						<?php echo esc_html( ( $uappt_row['adjusted'] > 0 ? '+' : '' ) . UAPPT_Consumable::format_qty( $uappt_row['adjusted'] ) ); ?>
					</span>
				</td>
				<td data-label="<?php esc_attr_e( '目前結存', 'ultimate-appointments' ); ?>">
					<span class="<?php echo $uappt_row['stock'] < 0 ? 'uappt-text-error' : ''; ?>">
						<?php echo esc_html( UAPPT_Consumable::format_qty( $uappt_row['stock'] ) ); ?>
					</span>
				</td>
				<td data-label="<?php esc_attr_e( '預估可用天數', 'ultimate-appointments' ); ?>">
					<?php
					// -1 是「這段期間沒用過，推不出來」的哨兵值，不是 0 天。
					if ( $uappt_row['days_left'] < 0 ) {
						echo '—';
					} else {
						printf(
							/* translators: %s: 天數 */
							esc_html__( '約 %s 天', 'ultimate-appointments' ),
							esc_html( number_format( $uappt_row['days_left'], 0 ) )
						);
					}
					?>
				</td>
			</tr>
		<?php endforeach; ?>
	</tbody>
</table>
</div>
