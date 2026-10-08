<?php
/**
 * View：耗材管理（庫存／配方／異動紀錄三個頁籤）。
 *
 * 「盤點」v2.32.0 起不是獨立頁籤，而是在庫存表上直接填實際數量——見
 * UAPPT_Admin::consumable_tabs()。
 *
 * ⚠️ **頁籤跟設定頁的頁籤不是同一種東西**：設定頁把五個頁籤的欄位全部留在
 * DOM 裡、只用 CSS 藏（因為儲存時會無條件寫入每一個 option，少印一個欄位就
 * 會把它重設成預設值）。這裡每一頁各自查詢、各自是獨立的表單，用真正的分頁，
 * 只渲染目前這一頁。
 *
 * 傳入變數（由 UAPPT_Admin::render_consumables_page() 依頁籤準備，用不到的是空的）：
 * - $tabs / $current_tab
 * - $consumables     耗材清單
 * - $low_stock       低於警示量的（庫存頁）
 * - $moves           ['items','total']（異動紀錄頁）
 * - $move_filters    目前的篩選條件（異動紀錄頁）
 * - $move_names      id => 耗材資料，供異動列查名稱（異動紀錄頁）
 * - $recipe_targets  配方可以掛的對象清單（配方頁）
 * - $recipe_value    目前選的對象字串（配方頁）
 * - $recipe_rows     這個對象已經設定的配方列（配方頁）
 * - $recipe_inherited 這一頁沒設定、但實際上仍會扣的來源（配方頁）：
 *                     ['global' => rows, 'product' => rows]
 *
 * @package Ultimate_Appointments
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$uappt_base_url = UAPPT_Admin::url( 'consumables' );
$uappt_new_url  = add_query_arg( 'action', 'new', $uappt_base_url );
?>

	<?php UAPPT_Admin::render_tabs( $tabs, $current_tab, $uappt_base_url ); ?>


	<?php if ( 'stock' === $current_tab ) : ?>

		<p class="uappt-page-desc">
			<?php esc_html_e( '服務過程會用掉的東西（凝膠、面膜、拋棄式用品）。設定好「配方」之後，預約被標記為「完成」時系統會自動扣用；標記錯了還原回「已確認」時會自動回沖。', 'ultimate-appointments' ); ?>
		</p>
		<p class="uappt-page-desc">
			<?php esc_html_e( '單位可以填 ml、片、支，也可以直接填「次」——例如一罐凝膠大約做 30 次，就把單位設成「次」、庫存填 30，每次服務扣 1。不想精算用量的話後者比較實際。', 'ultimate-appointments' ); ?>
		</p>

		<?php if ( ! empty( $low_stock ) ) : ?>
			<div class="notice notice-warning inline">
				<p>
					<strong><?php esc_html_e( '以下耗材已經低於警示量：', 'ultimate-appointments' ); ?></strong>
					<?php
					$uappt_low_labels = array();
					foreach ( $low_stock as $uappt_low ) {
						$uappt_low_labels[] = $uappt_low['name'] . '（' . UAPPT_Consumable::format_qty( $uappt_low['stock'], $uappt_low['unit'] ) . '）';
					}
					echo esc_html( implode( '、', $uappt_low_labels ) );
					?>
				</p>
			</div>
		<?php endif; ?>

		<?php
		// 只有啟用中的耗材能盤點（跟舊的「盤點」頁籤一樣——停用的品項不該出現
		// 在盤點清單裡）。這個數字同時決定下面的送出區塊要不要畫出來。
		$uappt_active_count = 0;
		foreach ( $consumables as $uappt_c ) {
			if ( UAPPT_Consumable::STATUS_ACTIVE === $uappt_c['status'] ) {
				$uappt_active_count++;
			}
		}
		?>

		<?php // 盤點的表單「外殼」刻意放在表格外面、本身不含任何欄位：表格每一列
		// 已經有自己的「進貨／報廢」<form>，HTML 不能把表單疊在表單裡。改用
		// HTML5 的 form 屬性讓散在表格裡的 input 指回這張表單，兩組欄位就能
		// 各自送到各自的 handler，互不干擾。 ?>
		<?php if ( $uappt_active_count > 0 ) : ?>
			<form
				id="uappt-stocktake-form"
				method="post"
				action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
			>
				<input type="hidden" name="action" value="uappt_consumable_stocktake" />
				<?php wp_nonce_field( 'uappt_consumable_stocktake' ); ?>
			</form>
		<?php endif; ?>

		<table class="widefat striped uappt-table">
			<thead>
				<tr>
					<th><?php esc_html_e( '名稱', 'ultimate-appointments' ); ?></th>
					<th><?php esc_html_e( '目前庫存', 'ultimate-appointments' ); ?></th>
					<th><?php esc_html_e( '實際數量', 'ultimate-appointments' ); ?></th>
					<th><?php esc_html_e( '警示量', 'ultimate-appointments' ); ?></th>
					<th><?php esc_html_e( '單位成本', 'ultimate-appointments' ); ?></th>
					<th><?php esc_html_e( '狀態', 'ultimate-appointments' ); ?></th>
					<th><?php esc_html_e( '進貨／報廢', 'ultimate-appointments' ); ?></th>
					<th><?php esc_html_e( '操作', 'ultimate-appointments' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $consumables ) ) : ?>
					<tr>
						<td colspan="8">
							<?php esc_html_e( '還沒有建立任何耗材。', 'ultimate-appointments' ); ?>
							<a href="<?php echo esc_url( $uappt_new_url ); ?>"><?php esc_html_e( '立即新增一項', 'ultimate-appointments' ); ?></a>
						</td>
					</tr>
				<?php endif; ?>

				<?php foreach ( $consumables as $uappt_item ) : ?>
					<?php
					$uappt_edit_url = add_query_arg(
						array(
							'action'        => 'edit',
							'consumable_id' => $uappt_item['id'],
						),
						$uappt_base_url
					);
					$uappt_is_low = ( null !== $uappt_item['low_stock_threshold'] )
						&& ( (float) $uappt_item['stock'] <= (float) $uappt_item['low_stock_threshold'] );
					?>
					<tr>
						<td data-label="<?php esc_attr_e( '名稱', 'ultimate-appointments' ); ?>">
							<strong><a href="<?php echo esc_url( $uappt_edit_url ); ?>"><?php echo esc_html( $uappt_item['name'] ); ?></a></strong>
							<?php if ( '' !== (string) $uappt_item['sku'] ) : ?>
								<br /><span class="description"><?php echo esc_html( $uappt_item['sku'] ); ?></span>
							<?php endif; ?>
						</td>
						<td data-label="<?php esc_attr_e( '目前庫存', 'ultimate-appointments' ); ?>">
							<?php // 負數代表扣用扣過頭了（帳沒跟上實際），標紅提醒去盤點。 ?>
							<strong class="<?php echo ( (float) $uappt_item['stock'] < 0 ) ? 'uappt-text-error' : ''; ?>">
								<?php echo esc_html( UAPPT_Consumable::format_qty( $uappt_item['stock'], $uappt_item['unit'] ) ); ?>
							</strong>
							<?php if ( $uappt_is_low ) : ?>
								<span class="uappt-badge uappt-badge-warning"><?php esc_html_e( '低量', 'ultimate-appointments' ); ?></span>
							<?php endif; ?>
						</td>
						<td data-label="<?php esc_attr_e( '實際數量', 'ultimate-appointments' ); ?>">
							<?php // 緊跟在帳面數字後面，一眼就比得出差多少；「進貨／報廢」則
							// 留在最右邊。兩個都是數量欄位，刻意隔開——一邊填的是差額、
							// 一邊填的是絕對值，填錯格子的後果完全不同。 ?>
							<?php if ( UAPPT_Consumable::STATUS_ACTIVE === $uappt_item['status'] ) : ?>
								<input
									type="number"
									form="uappt-stocktake-form"
									id="uappt-stocktake-<?php echo esc_attr( $uappt_item['id'] ); ?>"
									name="actual[<?php echo esc_attr( $uappt_item['id'] ); ?>]"
									step="0.001"
									class="uappt-input-num"
									placeholder="<?php esc_attr_e( '未盤', 'ultimate-appointments' ); ?>"
								/>
							<?php else : ?>
								<span class="description">—</span>
							<?php endif; ?>
						</td>
						<td data-label="<?php esc_attr_e( '警示量', 'ultimate-appointments' ); ?>">
							<?php echo null === $uappt_item['low_stock_threshold'] ? '—' : esc_html( UAPPT_Consumable::format_qty( $uappt_item['low_stock_threshold'], $uappt_item['unit'] ) ); ?>
						</td>
						<td data-label="<?php esc_attr_e( '單位成本', 'ultimate-appointments' ); ?>">
							<?php echo null === $uappt_item['unit_cost'] ? '—' : wp_kses_post( wc_price( (float) $uappt_item['unit_cost'] ) ); ?>
						</td>
						<td data-label="<?php esc_attr_e( '狀態', 'ultimate-appointments' ); ?>">
							<span class="uappt-badge uappt-badge-<?php echo UAPPT_Consumable::STATUS_ACTIVE === $uappt_item['status'] ? 'success' : 'muted'; ?>">
								<?php echo UAPPT_Consumable::STATUS_ACTIVE === $uappt_item['status'] ? esc_html__( '啟用中', 'ultimate-appointments' ) : esc_html__( '已停用', 'ultimate-appointments' ); ?>
							</span>
						</td>
						<td data-label="<?php esc_attr_e( '進貨／報廢', 'ultimate-appointments' ); ?>">
							<?php // 數量一律填正數，方向由按下哪顆按鈕決定——讓使用者自己記得 ?>
							<?php // 報廢要打負號，遲早會有人打成正的、把報廢記成進貨。 ?>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="uappt-inline-move">
								<input type="hidden" name="action" value="uappt_consumable_move" />
								<input type="hidden" name="consumable_id" value="<?php echo esc_attr( $uappt_item['id'] ); ?>" />
								<?php wp_nonce_field( 'uappt_consumable_move' ); ?>
								<input type="number" name="qty" step="0.001" min="0" class="uappt-input-num" placeholder="<?php esc_attr_e( '數量', 'ultimate-appointments' ); ?>" required />
								<button type="submit" name="move_type" value="<?php echo esc_attr( UAPPT_Consumable::MOVE_RESTOCK ); ?>" class="button"><?php esc_html_e( '進貨', 'ultimate-appointments' ); ?></button>
								<button type="submit" name="move_type" value="<?php echo esc_attr( UAPPT_Consumable::MOVE_WASTE ); ?>" class="button"><?php esc_html_e( '報廢', 'ultimate-appointments' ); ?></button>
							</form>
						</td>
						<td class="uappt-cell-block" data-label="<?php esc_attr_e( '操作', 'ultimate-appointments' ); ?>">
							<a href="<?php echo esc_url( $uappt_edit_url ); ?>"><?php esc_html_e( '編輯', 'ultimate-appointments' ); ?></a>
							&nbsp;|&nbsp;
							<a href="<?php echo esc_url( add_query_arg( array( 'tab' => 'moves', 'consumable_id' => $uappt_item['id'] ), $uappt_base_url ) ); ?>"><?php esc_html_e( '異動紀錄', 'ultimate-appointments' ); ?></a>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<?php if ( $uappt_active_count > 0 ) : ?>
			<div class="uappt-panel">
				<?php UAPPT_Admin::panel_head( 'clipboard-list', __( '送出盤點', 'ultimate-appointments' ) ); ?>
				<?php // 說明文字在上、欄位在下——跟設定頁的模組面板同一種組合，
				// 說明要包一層 .uappt-panel-body 才有左右內距，不然會直接貼齊卡片
				// 邊框，跟底下 form-table 的 20px 內距對不齊（這裡漏包過一次）。 ?>
				<div class="uappt-panel-body">
					<p class="uappt-page-desc">
						<?php esc_html_e( '實際數一次現場還剩多少，填進上面那一欄的「實際數量」再送出。系統會自己算出差額並寫成一筆「盤點校正」異動——帳面數字不會被直接覆蓋掉，差額會留在紀錄裡，之後才看得出耗損率。', 'ultimate-appointments' ); ?>
					</p>
					<p class="uappt-page-desc">
						<?php esc_html_e( '這次沒盤到的品項留空就好，留空的不會被動到（留空不等於盤到 0）。', 'ultimate-appointments' ); ?>
					</p>
				</div>
				<table class="form-table">
					<tr>
						<th><label for="uappt-stocktake-note"><?php esc_html_e( '這次盤點的備註', 'ultimate-appointments' ); ?></label></th>
						<td>
							<input
								type="text"
								form="uappt-stocktake-form"
								id="uappt-stocktake-note"
								name="note"
								class="regular-text"
								placeholder="<?php esc_attr_e( '例如：2026 年 9 月月底盤點', 'ultimate-appointments' ); ?>"
							/>
							<p class="description"><?php esc_html_e( '留空的話系統會自動寫成「盤點校正：帳面 X → 實際 Y」。', 'ultimate-appointments' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button( __( '送出盤點', 'ultimate-appointments' ), 'primary', 'submit', true, array( 'form' => 'uappt-stocktake-form' ) ); ?>
			</div>
		<?php endif; ?>

		<?php if ( ! empty( $consumables ) ) : ?>
			<p class="description uappt-hint">
				<?php esc_html_e( '庫存數字是「結存快照」，真正的事實來源是異動紀錄。如果曾經直接改過資料庫、或匯入過舊資料導致兩者對不上，可以用下面這顆按鈕依異動紀錄重算一次。正常使用不需要用到它。', 'ultimate-appointments' ); ?>
			</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="uappt_recalculate_consumable_stock" />
				<?php wp_nonce_field( 'uappt_recalculate_consumable_stock' ); ?>
				<button type="submit" class="button"><?php esc_html_e( '依異動紀錄重算結存', 'ultimate-appointments' ); ?></button>
			</form>
		<?php endif; ?>

	<?php elseif ( 'recipes' === $current_tab ) : ?>

		<p class="uappt-page-desc">
			<?php esc_html_e( '設定「做這個服務會用掉什麼」。預約被標記為「完成」時就照這裡的設定自動扣庫存。', 'ultimate-appointments' ); ?>
		</p>
		<p class="uappt-page-desc">
			<?php esc_html_e( '三個層級：「全部服務」是每位客人都會用到的東西（毛巾、手套），一定會加上去；商品層級是這個服務的預設；方案層級可以再覆寫商品層級。方案沒有設定任何耗材時，就沿用商品層級的設定。', 'ultimate-appointments' ); ?>
		</p>

		<?php
		// 只有一格而且它是「現在在編哪一組配方」——收起來等於把這一頁在編什麼
		// 藏起來，所以不收合。
		UAPPT_Admin::filters_open(
			array(
				'section'     => 'consumables',
				'hidden'      => array( 'tab' => 'recipes' ),
				'collapsible' => false,
			)
		);
		?>
			<?php UAPPT_Admin::field_open( __( '設定對象', 'ultimate-appointments' ), 'uappt-filter-target', 'uappt-field-wide' ); ?>
				<select id="uappt-filter-target" name="target">
					<?php foreach ( $recipe_targets as $uappt_target ) : ?>
						<option value="<?php echo esc_attr( $uappt_target['value'] ); ?>" <?php selected( $recipe_value, $uappt_target['value'] ); ?>>
							<?php echo esc_html( $uappt_target['label'] ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			<?php UAPPT_Admin::field_close(); ?>

			<?php UAPPT_Admin::field_open( '', '', 'uappt-field-actions' ); ?>
				<?php submit_button( __( '切換', 'ultimate-appointments' ), 'secondary', '', false ); ?>
			<?php UAPPT_Admin::field_close(); ?>
		<?php UAPPT_Admin::filters_close(); ?>

		<?php if ( empty( $consumables ) ) : ?>
			<p>
				<?php esc_html_e( '還沒有任何啟用中的耗材，先去「庫存」頁籤建立幾項再回來設定配方。', 'ultimate-appointments' ); ?>
			</p>
		<?php else : ?>
			<?php
			// 已設定的量攤平成 id => row，表單才好一列一列填回去。
			$uappt_existing = array();
			foreach ( $recipe_rows as $uappt_row ) {
				$uappt_existing[ (int) $uappt_row['consumable_id'] ] = $uappt_row;
			}
			?>
			<?php if ( ! empty( $recipe_inherited ) ) : ?>
				<?php
				// 「這一頁沒設定、但實際上還是會扣」的東西。不講出來的話管理者
				// 會問「我明明沒設定毛巾，為什麼毛巾一直在少」。
				$uappt_inherited_names = UAPPT_Consumable::get_many(
					array_merge(
						wp_list_pluck( isset( $recipe_inherited['global'] ) ? $recipe_inherited['global'] : array(), 'consumable_id' ),
						wp_list_pluck( isset( $recipe_inherited['product'] ) ? $recipe_inherited['product'] : array(), 'consumable_id' )
					)
				);
				$uappt_describe_rows = function ( $rows ) use ( $uappt_inherited_names ) {
					$parts = array();
					foreach ( (array) $rows as $uappt_r ) {
						$uappt_rid = (int) $uappt_r['consumable_id'];
						if ( ! isset( $uappt_inherited_names[ $uappt_rid ] ) ) {
							continue;
						}
						$parts[] = $uappt_inherited_names[ $uappt_rid ]['name'] . ' ×' . UAPPT_Consumable::format_qty( $uappt_r['qty_per_service'], $uappt_inherited_names[ $uappt_rid ]['unit'] );
					}
					return $parts;
				};
				$uappt_global_parts  = $uappt_describe_rows( isset( $recipe_inherited['global'] ) ? $recipe_inherited['global'] : array() );
				$uappt_product_parts = $uappt_describe_rows( isset( $recipe_inherited['product'] ) ? $recipe_inherited['product'] : array() );
				?>
				<?php if ( $uappt_global_parts || $uappt_product_parts ) : ?>
					<div class="notice notice-info inline">
						<?php if ( $uappt_global_parts ) : ?>
							<p>
								<strong><?php esc_html_e( '除了下面設定的以外，這個服務還會扣「全部服務」的：', 'ultimate-appointments' ); ?></strong>
								<?php echo esc_html( implode( '、', $uappt_global_parts ) ); ?>
							</p>
						<?php endif; ?>
						<?php if ( $uappt_product_parts ) : ?>
							<p>
								<strong><?php esc_html_e( '這個方案目前沒有自己的設定，沿用商品層級的：', 'ultimate-appointments' ); ?></strong>
								<?php echo esc_html( implode( '、', $uappt_product_parts ) ); ?>
								<br />
								<span class="description"><?php esc_html_e( '在下面填任何一個數量並儲存，這個方案就會改用自己的設定，不再沿用商品層級。', 'ultimate-appointments' ); ?></span>
							</p>
						<?php endif; ?>
					</div>
				<?php endif; ?>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="uappt-form">
				<input type="hidden" name="action" value="uappt_save_consumable_recipe" />
				<input type="hidden" name="target" value="<?php echo esc_attr( $recipe_value ); ?>" />
				<?php wp_nonce_field( 'uappt_save_consumable_recipe' ); ?>

				<div class="uappt-panel">
					<?php UAPPT_Admin::panel_head( 'package', __( '每次服務的用量', 'ultimate-appointments' ) ); ?>
					<table class="widefat striped uappt-table">
						<thead>
							<tr>
								<th><?php esc_html_e( '耗材', 'ultimate-appointments' ); ?></th>
								<th><?php esc_html_e( '每次用量', 'ultimate-appointments' ); ?></th>
								<th><?php esc_html_e( '隨人數倍增', 'ultimate-appointments' ); ?></th>
								<th><?php esc_html_e( '目前庫存', 'ultimate-appointments' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $consumables as $uappt_item ) : ?>
								<?php
								$uappt_id  = (int) $uappt_item['id'];
								$uappt_has = isset( $uappt_existing[ $uappt_id ] );
								?>
								<tr>
									<td data-label="<?php esc_attr_e( '耗材', 'ultimate-appointments' ); ?>">
										<label for="uappt-recipe-<?php echo esc_attr( $uappt_id ); ?>"><?php echo esc_html( $uappt_item['name'] ); ?></label>
									</td>
									<td data-label="<?php esc_attr_e( '每次用量', 'ultimate-appointments' ); ?>">
										<?php // 留空或 0 ＝ 這個服務不會用到它，儲存時會被丟掉。 ?>
										<input
											type="number"
											id="uappt-recipe-<?php echo esc_attr( $uappt_id ); ?>"
											name="qty[<?php echo esc_attr( $uappt_id ); ?>]"
											step="0.001"
											min="0"
											class="uappt-input-num"
											value="<?php echo $uappt_has ? esc_attr( UAPPT_Consumable::format_qty( $uappt_existing[ $uappt_id ]['qty_per_service'] ) ) : ''; ?>"
										/>
										<?php if ( '' !== (string) $uappt_item['unit'] ) : ?>
											<span class="description"><?php echo esc_html( $uappt_item['unit'] ); ?></span>
										<?php endif; ?>
									</td>
									<td data-label="<?php esc_attr_e( '隨人數倍增', 'ultimate-appointments' ); ?>">
										<label>
											<input type="checkbox" name="per_unit[<?php echo esc_attr( $uappt_id ); ?>]" value="1" <?php checked( $uappt_has && ! empty( $uappt_existing[ $uappt_id ]['per_unit'] ) ); ?> />
											<?php esc_html_e( '團體預約時乘上人數', 'ultimate-appointments' ); ?>
										</label>
									</td>
									<td data-label="<?php esc_attr_e( '目前庫存', 'ultimate-appointments' ); ?>">
										<?php echo esc_html( UAPPT_Consumable::format_qty( $uappt_item['stock'], $uappt_item['unit'] ) ); ?>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>

					<div class="uappt-panel-body">
						<p class="description">
							<?php esc_html_e( '「隨人數倍增」用在一人一份的東西（拋棄式毛巾、手套）；一罐凝膠不會因為同時來了三個人就用掉三倍，那種就不要勾。', 'ultimate-appointments' ); ?>
						</p>
						<p class="description">
							<?php esc_html_e( '留空或填 0 代表這個服務不會用到該項耗材。之後改動配方不會回頭重算已經扣過的紀錄——歷史要反映當時實際發生的事。', 'ultimate-appointments' ); ?>
						</p>
					</div>

					<?php submit_button( __( '儲存配方', 'ultimate-appointments' ) ); ?>
				</div>
			</form>
		<?php endif; ?>

	<?php else : ?>

		<p class="uappt-page-desc">
			<?php esc_html_e( '每一次庫存變動都在這裡，包含服務自動扣用、進貨、報廢、盤點校正與回沖。庫存數字永遠是這份紀錄加總出來的結果。', 'ultimate-appointments' ); ?>
		</p>

		<?php
		$uappt_active = 0;
		if ( $move_filters['consumable_id'] ) {
			$uappt_active++;
		}
		if ( '' !== $move_filters['type'] ) {
			$uappt_active++;
		}
		if ( '' !== $move_filters['date_from'] || '' !== $move_filters['date_to'] ) {
			$uappt_active++;
		}

		UAPPT_Admin::filters_open(
			array(
				'section' => 'consumables',
				'hidden'  => array( 'tab' => 'moves' ),
				'active'  => $uappt_active,
			)
		);
		?>
			<?php UAPPT_Admin::field_open( __( '耗材', 'ultimate-appointments' ), 'uappt-filter-consumable' ); ?>
				<select id="uappt-filter-consumable" name="consumable_id">
					<option value=""><?php esc_html_e( '所有耗材', 'ultimate-appointments' ); ?></option>
					<?php foreach ( $consumables as $uappt_item ) : ?>
						<option value="<?php echo esc_attr( $uappt_item['id'] ); ?>" <?php selected( $move_filters['consumable_id'], (int) $uappt_item['id'] ); ?>>
							<?php echo esc_html( $uappt_item['name'] ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			<?php UAPPT_Admin::field_close(); ?>

			<?php UAPPT_Admin::field_open( __( '異動類型', 'ultimate-appointments' ), 'uappt-filter-type' ); ?>
				<select id="uappt-filter-type" name="type">
					<option value=""><?php esc_html_e( '所有類型', 'ultimate-appointments' ); ?></option>
					<?php foreach ( UAPPT_Consumable::move_types() as $uappt_type => $uappt_type_label ) : ?>
						<option value="<?php echo esc_attr( $uappt_type ); ?>" <?php selected( $move_filters['type'], $uappt_type ); ?>>
							<?php echo esc_html( $uappt_type_label ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			<?php UAPPT_Admin::field_close(); ?>

			<?php UAPPT_Admin::field_open( __( '異動日期', 'ultimate-appointments' ), '', 'uappt-field-range' ); ?>
				<span class="uappt-field-row">
					<input type="date" name="date_from" value="<?php echo esc_attr( $move_filters['date_from'] ); ?>" aria-label="<?php esc_attr_e( '異動日期（起）', 'ultimate-appointments' ); ?>" />
					<span class="uappt-field-sep" aria-hidden="true">～</span>
					<input type="date" name="date_to" value="<?php echo esc_attr( $move_filters['date_to'] ); ?>" aria-label="<?php esc_attr_e( '異動日期（迄）', 'ultimate-appointments' ); ?>" />
				</span>
			<?php UAPPT_Admin::field_close(); ?>

			<?php UAPPT_Admin::field_open( '', '', 'uappt-field-actions' ); ?>
				<?php submit_button( __( '套用', 'ultimate-appointments' ), 'secondary', '', false ); ?>
				<?php // 只清掉篩選參數，`tab=moves` 要留著——清篩選不該把人踢回
				// 庫存頁。`paged` 也一起丟：條件變了之後，原本的第 8 頁多半
				// 已經不存在，留著會看到空表格。寫法跟預約列表、月／日檢視
				// 的「清除篩選」一致。 ?>
				<a href="<?php echo esc_url( UAPPT_Admin::url( 'consumables', array( 'tab' => 'moves' ) ) ); ?>" class="button-link">
					<?php esc_html_e( '清除篩選', 'ultimate-appointments' ); ?>
				</a>
			<?php UAPPT_Admin::field_close(); ?>
		<?php UAPPT_Admin::filters_close(); ?>

		<table class="widefat striped uappt-table">
			<thead>
				<tr>
					<th><?php esc_html_e( '時間', 'ultimate-appointments' ); ?></th>
					<th><?php esc_html_e( '耗材', 'ultimate-appointments' ); ?></th>
					<th><?php esc_html_e( '類型', 'ultimate-appointments' ); ?></th>
					<th><?php esc_html_e( '數量', 'ultimate-appointments' ); ?></th>
					<th><?php esc_html_e( '異動後結存', 'ultimate-appointments' ); ?></th>
					<th><?php esc_html_e( '成本', 'ultimate-appointments' ); ?></th>
					<th><?php esc_html_e( '說明', 'ultimate-appointments' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $moves['items'] ) ) : ?>
					<tr><td colspan="7"><?php esc_html_e( '這個條件下沒有任何異動紀錄。', 'ultimate-appointments' ); ?></td></tr>
				<?php endif; ?>

				<?php foreach ( $moves['items'] as $uappt_move ) : ?>
					<?php
					$uappt_cid  = (int) $uappt_move['consumable_id'];
					$uappt_name = isset( $move_names[ $uappt_cid ] ) ? $move_names[ $uappt_cid ]['name'] : __( '（已刪除的耗材）', 'ultimate-appointments' );
					$uappt_unit = isset( $move_names[ $uappt_cid ] ) ? $move_names[ $uappt_cid ]['unit'] : '';
					$uappt_qty  = (float) $uappt_move['qty'];
					?>
					<tr>
						<td data-label="<?php esc_attr_e( '時間', 'ultimate-appointments' ); ?>"><?php echo esc_html( $uappt_move['created_at'] ); ?></td>
						<td data-label="<?php esc_attr_e( '耗材', 'ultimate-appointments' ); ?>"><?php echo esc_html( $uappt_name ); ?></td>
						<td data-label="<?php esc_attr_e( '類型', 'ultimate-appointments' ); ?>">
							<span class="uappt-badge uappt-badge-<?php echo $uappt_qty < 0 ? 'error' : 'success'; ?>">
								<?php echo esc_html( UAPPT_Consumable::move_type_label( $uappt_move['type'] ) ); ?>
							</span>
						</td>
						<td data-label="<?php esc_attr_e( '數量', 'ultimate-appointments' ); ?>">
							<?php // 正負號要留著：一眼分得出這筆是加進去還是扣掉。 ?>
							<?php echo esc_html( ( $uappt_qty > 0 ? '+' : '' ) . UAPPT_Consumable::format_qty( $uappt_qty, $uappt_unit ) ); ?>
						</td>
						<td data-label="<?php esc_attr_e( '異動後結存', 'ultimate-appointments' ); ?>">
							<?php echo esc_html( UAPPT_Consumable::format_qty( $uappt_move['balance_after'], $uappt_unit ) ); ?>
						</td>
						<td data-label="<?php esc_attr_e( '成本', 'ultimate-appointments' ); ?>">
							<?php echo null === $uappt_move['cost_total'] ? '—' : wp_kses_post( wc_price( (float) $uappt_move['cost_total'] ) ); ?>
						</td>
						<td data-label="<?php esc_attr_e( '說明', 'ultimate-appointments' ); ?>">
							<?php echo esc_html( (string) $uappt_move['note'] ); ?>
							<?php if ( ! empty( $uappt_move['booking_id'] ) ) : ?>
								<a href="<?php echo esc_url( add_query_arg( array( 'page' => UAPPT_Admin::PAGE_SLUG, 'section' => 'bookings', 'action' => 'edit', 'booking_id' => $uappt_move['booking_id'] ), admin_url( 'admin.php' ) ) ); ?>">
									<?php
									printf(
										/* translators: %d: 預約 ID */
										esc_html__( '（預約 #%d）', 'ultimate-appointments' ),
										(int) $uappt_move['booking_id']
									);
									?>
								</a>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<?php $uappt_total_pages = (int) ceil( $moves['total'] / max( 1, (int) $move_filters['per_page'] ) ); ?>
		<?php if ( $uappt_total_pages > 1 ) : ?>
			<div class="tablenav">
				<div class="tablenav-pages">
					<?php
					echo wp_kses_post(
						paginate_links(
							array(
								'base'      => add_query_arg( 'paged', '%#%' ),
								'format'    => '',
								'current'   => max( 1, (int) $move_filters['paged'] ),
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

	<?php endif; ?>
