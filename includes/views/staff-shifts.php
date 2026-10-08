<?php
/**
 * View：人員管理 ▸ 班別設定（v2.98.0，從「設定 ▸ 預約規則」搬過來）。
 *
 * 傳入變數：$shift_presets（UAPPT_Shift_Preset::all()）。
 *
 * 計畫見 docs/shift-ui-plan.md 的 D2。編輯器的欄位與清洗規則跟搬家前完全一樣
 * （同一支 UAPPT_Shift_Preset::save()），只換了位置與送出的 handler：原本跟著「設定」
 * 整頁表單一起存，現在有自己的 admin-post action。
 *
 * @package Ultimate_Appointments
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>

<p class="uappt-page-desc">
	<?php esc_html_e( '店裡排班常用的班——「早班」「晚班」「全天」——在這裡設定好，排班時點一下就排好時間，不用每次打。班別是整間店共用一組。', 'ultimate-appointments' ); ?>
</p>

<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="uappt-form uappt-shifts-form">
	<input type="hidden" name="action" value="uappt_save_shift_presets" />
	<?php wp_nonce_field( 'uappt_save_shift_presets' ); ?>

	<div class="uappt-panel">
		<?php UAPPT_Admin::panel_head( 'clock', __( '班別', 'ultimate-appointments' ) ); ?>
		<div class="uappt-panel-body">
				<table class="uappt-table uappt-presets-table">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( '班別名稱與顏色', 'ultimate-appointments' ); ?></th>
							<th scope="col"><?php esc_html_e( '時段', 'ultimate-appointments' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php
						// ⚠️ **補到三列空白，不是一列。** 公休日那張表只多印一列，因為
						// 指定日期公休是「想到一個加一個」；班別相反，典型的店一開始就
						// 要填 2～4 個（早／晚／全天），只給一列空白等於要存三次檔才設
						// 定得完。代價只是表格高一點。
						//
						// 沒有 JS 時這幾列就是唯一的新增方式（頁面沒有「＋加一列」）。
						$uappt_preset_rows = $shift_presets;
						for ( $uappt_pad = 0; $uappt_pad < 3; $uappt_pad++ ) {
							$uappt_preset_rows[] = array( 'name' => '', 'ranges' => array(), 'color' => '' );
						}
						$uappt_color_labels = UAPPT_Shift_Preset::color_labels();
						// 範例文字只印在第一列空白：三列都印的話，一眼看過去像是多了三個
						// 「早班」（v2.99.0）。
						$uappt_first_blank = count( $shift_presets );
						?>
						<?php foreach ( $uappt_preset_rows as $uappt_pi => $uappt_preset ) : ?>
							<tr>
								<td data-label="<?php esc_attr_e( '班別名稱', 'ultimate-appointments' ); ?>">
									<input type="text" class="regular-text"
										name="shift_presets[<?php echo (int) $uappt_pi; ?>][name]"
										value="<?php echo esc_attr( $uappt_preset['name'] ); ?>"
										placeholder="<?php echo $uappt_pi === $uappt_first_blank ? esc_attr__( '例如：早班', 'ultimate-appointments' ) : ''; ?>" />
									<?php
									// 班別顏色（v2.99.0）放在名稱底下，不另開一欄：名稱與顏色合起來才是「這個
									// 班別長什麼樣」，而另開一欄會把三段時段擠成兩行。8 色固定色盤，不給自由調色（理由見
									// UAPPT_Shift_Preset::COLORS）。空白列不預選——存檔時自動配一個
									// 還沒被用掉的顏色。
									?>
									<fieldset class="uappt-color-pick">
										<legend class="screen-reader-text"><?php esc_html_e( '班別顏色', 'ultimate-appointments' ); ?></legend>
										<?php foreach ( $uappt_color_labels as $uappt_ck => $uappt_cl ) : ?>
											<label class="uappt-color-swatch uappt-shift-c-<?php echo esc_attr( $uappt_ck ); ?>" title="<?php echo esc_attr( $uappt_cl ); ?>">
												<input type="radio"
													name="shift_presets[<?php echo (int) $uappt_pi; ?>][color]"
													value="<?php echo esc_attr( $uappt_ck ); ?>"
													aria-label="<?php echo esc_attr( $uappt_cl ); ?>"
													<?php checked( isset( $uappt_preset['color'] ) ? $uappt_preset['color'] : '', $uappt_ck ); ?> />
												<span aria-hidden="true"></span>
											</label>
										<?php endforeach; ?>
									</fieldset>
								</td>
								<td data-label="<?php esc_attr_e( '時段', 'ultimate-appointments' ); ?>">
									<?php for ( $uappt_ri = 0; $uappt_ri < UAPPT_Shift_Preset::MAX_RANGES; $uappt_ri++ ) : ?>
										<?php
										$uappt_range = isset( $uappt_preset['ranges'][ $uappt_ri ] ) ? $uappt_preset['ranges'][ $uappt_ri ] : array( '', '' );
										?>
										<span class="uappt-range">
											<input type="text" inputmode="numeric" autocomplete="off" class="uappt-time-input"
												aria-label="<?php printf( esc_attr__( '時段%d 開始', 'ultimate-appointments' ), (int) $uappt_ri + 1 ); ?>"
												name="shift_presets[<?php echo (int) $uappt_pi; ?>][hours][<?php echo (int) $uappt_ri; ?>][start]"
												value="<?php echo esc_attr( $uappt_range[0] ); ?>"
												placeholder="<?php echo ( 0 === $uappt_ri && $uappt_pi === $uappt_first_blank ) ? '09:00' : ''; ?>" />
											<span class="uappt-range-dash" aria-hidden="true">–</span>
											<input type="text" inputmode="numeric" autocomplete="off" class="uappt-time-input"
												aria-label="<?php printf( esc_attr__( '時段%d 結束', 'ultimate-appointments' ), (int) $uappt_ri + 1 ); ?>"
												name="shift_presets[<?php echo (int) $uappt_pi; ?>][hours][<?php echo (int) $uappt_ri; ?>][end]"
												value="<?php echo esc_attr( $uappt_range[1] ); ?>"
												placeholder="<?php echo ( 0 === $uappt_ri && $uappt_pi === $uappt_first_blank ) ? '13:00' : ''; ?>" />
										</span>
									<?php endfor; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<p class="description">
				<?php esc_html_e( '要刪掉一個班別，把它的名稱與時段都清空再儲存。最下面的空白列是給你新增用的；新的班別沒選顏色的話，會自動配一個還沒用過的顏色。', 'ultimate-appointments' ); ?>
			</p>
			<?php
			UAPPT_Admin::help(
				array(
					sprintf(
						/* translators: %d: 一個班別最多幾段 */
						__( '一個班別最多 %d 段（例如中午休息、深夜加開一段）。時間可以直接打字（輸入「930」會自動變成「09:30」）；結束時間填得比開始早代表上到隔天。', 'ultimate-appointments' ),
						(int) UAPPT_Shift_Preset::MAX_RANGES
					),
					__( '⚠️ 班別只是「填時間的捷徑」，不會跟已經排好的日子連動。之後改了早班的時間，已經排成早班的那些日子維持原本的時間不變——那些是已經排定的事實。要一起改請重新排那幾天。', 'ultimate-appointments' ),
					__( '班別是整間店共用一組，不是某一位人員的屬性：排班的人想的是「小明早班、小美晚班」，做成逐人的話同一個早班要維護好幾份。', 'ultimate-appointments' ),
					__( '名稱或時段只填一半的那一列不會存，儲存後會告訴你。', 'ultimate-appointments' ),
				)
			);
			?>
		</div>
	</div>

	<p class="submit uappt-settings-submit">
		<?php submit_button( __( '儲存班別', 'ultimate-appointments' ), 'primary', 'submit', false ); ?>
	</p>
</form>
