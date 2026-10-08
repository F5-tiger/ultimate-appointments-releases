<?php
/**
 * View：新增／編輯單一耗材。
 *
 * 傳入變數：$consumable（編輯時的既有資料；新增時是 null）。
 *
 * ⚠️ **這張表單沒有「庫存」欄位**，這是刻意的：庫存只能透過異動紀錄變動
 * （見 UAPPT_Consumable 開頭的規則 1），不然流水帳跟結存就對不起來。新增時
 * 的「期初庫存」會被寫成一筆「進貨」異動，不是直接塞進欄位；之後要調整
 * 數量請用庫存頁的進貨／報廢，或盤點頁的校正。
 *
 * @package Ultimate_Appointments
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$uappt_is_new   = ( null === $consumable );
$uappt_value    = function ( $key, $default = '' ) use ( $consumable ) {
	return ( $consumable && null !== $consumable[ $key ] ) ? $consumable[ $key ] : $default;
};
?>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="uappt-form">
		<input type="hidden" name="action" value="uappt_save_consumable" />
		<input type="hidden" name="consumable_id" value="<?php echo esc_attr( $uappt_is_new ? 0 : $consumable['id'] ); ?>" />
		<?php wp_nonce_field( 'uappt_save_consumable' ); ?>

		<div class="uappt-panel">
			<?php UAPPT_Admin::panel_head( 'package', __( '基本資料', 'ultimate-appointments' ) ); ?>
			<table class="form-table">
				<tr>
					<th><label for="uappt-consumable-name"><?php esc_html_e( '名稱', 'ultimate-appointments' ); ?></label></th>
					<td>
						<input type="text" id="uappt-consumable-name" name="name" class="regular-text" value="<?php echo esc_attr( $uappt_value( 'name' ) ); ?>" required />
						<p class="description"><?php esc_html_e( '例如：卸甲液、凝膠（透明）、拋棄式毛巾。', 'ultimate-appointments' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><label for="uappt-consumable-unit"><?php esc_html_e( '單位', 'ultimate-appointments' ); ?></label></th>
					<td>
						<input type="text" id="uappt-consumable-unit" name="unit" class="regular-text" value="<?php echo esc_attr( $uappt_value( 'unit' ) ); ?>" placeholder="<?php esc_attr_e( '例如：ml、片、支、次', 'ultimate-appointments' ); ?>" />
						<p class="description">
							<?php esc_html_e( '想精算就填 ml、片、支；不想精算就填「次」——一罐凝膠大約做 30 次，庫存填 30、每次服務扣 1，一樣管得住，而且不用真的去量。', 'ultimate-appointments' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th><label for="uappt-consumable-sku"><?php esc_html_e( '品號（選填）', 'ultimate-appointments' ); ?></label></th>
					<td>
						<input type="text" id="uappt-consumable-sku" name="sku" class="regular-text" value="<?php echo esc_attr( $uappt_value( 'sku' ) ); ?>" />
						<p class="description"><?php esc_html_e( '自己的料號或廠商的品號，方便叫貨時對照。', 'ultimate-appointments' ); ?></p>
					</td>
				</tr>
				<?php if ( $uappt_is_new ) : ?>
					<tr>
						<th><label for="uappt-consumable-opening"><?php esc_html_e( '期初庫存', 'ultimate-appointments' ); ?></label></th>
						<td>
							<input type="number" id="uappt-consumable-opening" name="opening_stock" step="0.001" min="0" class="uappt-input-num" value="" />
							<p class="description"><?php esc_html_e( '現在手上有多少。這會被記成一筆「進貨」異動——庫存只能透過異動變動，這樣帳才對得起來。之後要調整數量請用清單頁的進貨／報廢，或盤點頁的校正。', 'ultimate-appointments' ); ?></p>
						</td>
					</tr>
				<?php else : ?>
					<tr>
						<th><?php esc_html_e( '目前庫存', 'ultimate-appointments' ); ?></th>
						<td>
							<strong><?php echo esc_html( UAPPT_Consumable::format_qty( $consumable['stock'], $consumable['unit'] ) ); ?></strong>
							<p class="description"><?php esc_html_e( '庫存不在這裡改：要進貨或報廢請回清單頁，盤點差額請用盤點頁——那些都會留下異動紀錄，直接改欄位不會。', 'ultimate-appointments' ); ?></p>
						</td>
					</tr>
				<?php endif; ?>
			</table>
		</div>

		<div class="uappt-panel">
			<?php UAPPT_Admin::panel_head( 'bell', __( '警示與成本', 'ultimate-appointments' ) ); ?>
			<table class="form-table">
				<tr>
					<th><label for="uappt-consumable-threshold"><?php esc_html_e( '低量警示', 'ultimate-appointments' ); ?></label></th>
					<td>
						<input type="number" id="uappt-consumable-threshold" name="low_stock_threshold" step="0.001" min="0" class="uappt-input-num" value="<?php echo esc_attr( $consumable && null !== $consumable['low_stock_threshold'] ? UAPPT_Consumable::format_qty( $consumable['low_stock_threshold'] ) : '' ); ?>" />
						<p class="description"><?php esc_html_e( '低於或等於這個數量時，清單頁會標「低量」、後台選單會出現紅點，每天也會推播一次給店家。留空＝不提醒（跟填 0 不一樣：0 是「歸零才提醒」）。', 'ultimate-appointments' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><label for="uappt-consumable-cost"><?php esc_html_e( '單位成本', 'ultimate-appointments' ); ?></label></th>
					<td>
						<input type="number" id="uappt-consumable-cost" name="unit_cost" step="0.01" min="0" class="uappt-input-amount" value="<?php echo esc_attr( $consumable && null !== $consumable['unit_cost'] ? $consumable['unit_cost'] : '' ); ?>" />
						<p class="description"><?php esc_html_e( '一個單位多少錢，用來算材料成本與毛利。每次扣用會把「當下」的單價記進異動紀錄，之後漲價不會回頭改到過去的成本。留空就不計算成本。', 'ultimate-appointments' ); ?></p>
					</td>
				</tr>
			</table>
		</div>

		<div class="uappt-panel">
			<?php UAPPT_Admin::panel_head( 'settings', __( '其他', 'ultimate-appointments' ) ); ?>
			<table class="form-table">
				<tr>
					<th><label for="uappt-consumable-status"><?php esc_html_e( '狀態', 'ultimate-appointments' ); ?></label></th>
					<td>
						<select id="uappt-consumable-status" name="status">
							<option value="<?php echo esc_attr( UAPPT_Consumable::STATUS_ACTIVE ); ?>" <?php selected( $uappt_value( 'status', UAPPT_Consumable::STATUS_ACTIVE ), UAPPT_Consumable::STATUS_ACTIVE ); ?>><?php esc_html_e( '啟用中', 'ultimate-appointments' ); ?></option>
							<option value="<?php echo esc_attr( UAPPT_Consumable::STATUS_INACTIVE ); ?>" <?php selected( $uappt_value( 'status', UAPPT_Consumable::STATUS_ACTIVE ), UAPPT_Consumable::STATUS_INACTIVE ); ?>><?php esc_html_e( '已停用', 'ultimate-appointments' ); ?></option>
						</select>
						<p class="description"><?php esc_html_e( '停用的耗材不會出現在配方設定裡，但過去的異動紀錄與成本都查得到。不再使用的品項請用停用，不要刪除。', 'ultimate-appointments' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><label for="uappt-consumable-sort"><?php esc_html_e( '排序', 'ultimate-appointments' ); ?></label></th>
					<td>
						<input type="number" id="uappt-consumable-sort" name="sort_order" step="1" class="uappt-input-num" value="<?php echo esc_attr( (int) $uappt_value( 'sort_order', 0 ) ); ?>" />
						<p class="description"><?php esc_html_e( '數字小的排前面，相同時照名稱排。常用的耗材給小一點的數字，盤點時比較好找。', 'ultimate-appointments' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><label for="uappt-consumable-note"><?php esc_html_e( '備註', 'ultimate-appointments' ); ?></label></th>
					<td>
						<textarea id="uappt-consumable-note" name="note" class="large-text" rows="3"><?php echo esc_textarea( $uappt_value( 'note' ) ); ?></textarea>
						<p class="description"><?php esc_html_e( '例如廠商、規格、保存期限，只有後台看得到。', 'ultimate-appointments' ); ?></p>
					</td>
				</tr>
			</table>
		</div>

		<?php submit_button( $uappt_is_new ? __( '建立耗材', 'ultimate-appointments' ) : __( '儲存變更', 'ultimate-appointments' ) ); ?>
	</form>

	<?php if ( ! $uappt_is_new ) : ?>
		<div class="uappt-panel">
			<?php UAPPT_Admin::panel_head( 'settings', __( '刪除', 'ultimate-appointments' ) ); ?>
			<div class="uappt-panel-body">
				<p class="description">
					<?php esc_html_e( '已經有異動紀錄的耗材不能刪除——刪掉會讓歷史用量與成本憑空少一塊。不再使用的品項請改成「已停用」：之後不會再被扣用、配方頁也不再列出，但過去的紀錄查得到。', 'ultimate-appointments' ); ?>
				</p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php echo esc_js( __( '確定要刪除這項耗材嗎？此動作無法復原。', 'ultimate-appointments' ) ); ?>');">
					<input type="hidden" name="action" value="uappt_delete_consumable" />
					<input type="hidden" name="consumable_id" value="<?php echo esc_attr( $consumable['id'] ); ?>" />
					<?php wp_nonce_field( 'uappt_delete_consumable' ); ?>
					<button type="submit" class="button button-link-delete"><?php esc_html_e( '刪除這項耗材', 'ultimate-appointments' ); ?></button>
				</form>
			</div>
		</div>
	<?php endif; ?>
