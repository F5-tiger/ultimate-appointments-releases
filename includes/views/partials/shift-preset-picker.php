<?php
/**
 * Partial：班別下拉（排班表單填時間的捷徑）。
 *
 * 「批次排班」與「改這一天」兩張表單都要同一個下拉，而它們的時間欄位 name 不同。
 * 兩份各自長歪只是時間問題（month-grid.php 的註解就是為了同一個理由抽出來的），
 * 所以一開始就收成一份。
 *
 * 傳入變數（**全部必填、沒有預設值**，比照 month-grid.php 的慣例：漏設一個會是
 * 空白，而不是「看起來對、其實少一半」）：
 *
 * - $preset_target    string，要填的時間欄位 name 前綴（'batch_hours' / 'override_hours'）
 * - $preset_set_type  bool，選了之後要不要順便把「類型」切成「自訂時段」。
 *                     「改這一天」要（它的類型預設是整天休假，不切的話你挑了早班
 *                     卻看不到時間欄位）；「批次排班」的預設類型本來就是自訂時段，
 *                     不需要。
 *
 * ⚠️ **這只是填欄位的捷徑。** 沒有 JS 時它不會有任何作用——而時間欄位本身仍然
 * 完全可用，所以這不是功能退化，只是少一個便利（跟 v2.81.0「點月曆」的取捨一樣：
 * 這段 JS 壞掉的最差情況是要自己打時間，不會變成存不進去）。
 *
 * 下拉刻意**沒有 `name`**，不會被送出：班別不寫進 `staff_overrides`，班表存的永遠
 * 是具體時段（見 `UAPPT_Shift_Preset` 的類別註解）。
 *
 * @package Ultimate_Appointments
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$uappt_presets = UAPPT_Shift_Preset::all();
?>
<p class="uappt-preset-pick-row">
	<?php if ( empty( $uappt_presets ) ) : ?>
		<span class="description">
			<?php
			printf(
				/* translators: %s: 設定頁連結 */
				wp_kses_post( __( '還沒有設定班別。在 %s 把「早班」「晚班」這些店裡本來就有的時段建起來，排班時就能一鍵套用，不用每次打時間。', 'ultimate-appointments' ) ),
				'<a href="' . esc_url( UAPPT_Admin::url( 'staff', array( 'tab' => 'shifts' ) ) ) . '">' . esc_html__( '人員管理 ▸ 班別設定', 'ultimate-appointments' ) . '</a>'
			);
			?>
		</span>
	<?php else : ?>
		<label>
			<span class="uappt-preset-pick-label"><?php esc_html_e( '套用班別', 'ultimate-appointments' ); ?></span>
			<select class="uappt-preset-pick"
				data-uappt-preset-target="<?php echo esc_attr( $preset_target ); ?>"
				data-uappt-preset-set-type="<?php echo $preset_set_type ? '1' : '0'; ?>">
				<option value=""><?php esc_html_e( '— 自己填時間 —', 'ultimate-appointments' ); ?></option>
				<?php foreach ( $uappt_presets as $uappt_pi => $uappt_preset ) : ?>
					<option value="<?php echo (int) $uappt_pi; ?>"
						data-uappt-ranges="<?php echo esc_attr( wp_json_encode( $uappt_preset['ranges'] ) ); ?>">
						<?php
						printf(
							/* translators: 1: 班別名稱 2: 時段，例如「09:00–13:00」 */
							esc_html__( '%1$s（%2$s）', 'ultimate-appointments' ),
							esc_html( $uappt_preset['name'] ),
							esc_html( UAPPT_Shift_Preset::format_ranges( $uappt_preset['ranges'] ) )
						);
						?>
					</option>
				<?php endforeach; ?>
			</select>
		</label>
		<span class="description"><?php esc_html_e( '選了就把下面的時間填好，之後還是可以自己改。', 'ultimate-appointments' ); ?></span>
	<?php endif; ?>
</p>
