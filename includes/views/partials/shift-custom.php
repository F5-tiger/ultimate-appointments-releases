<?php
/**
 * Partial：班別列上的「自訂時段…」（v2.100.0）。人員編輯頁的月曆與全店月排班表共用。
 *
 * 計畫見 docs/shift-ui-plan.md 的 D4。操作交給 assets/js/admin-shift-custom.js。
 *
 * ⚠️ **不是第二條排班的路。** 「套用」只是把時段填進最下面那顆藏起來的 `.uappt-shift`
 * 鈕、再替使用者按下去——兩邊頁面本來就會處理「按了班別鈕」：排上去、可以還原、只送
 * 改過的格子、離開前提醒。這裡多寫一份排班邏輯，遲早兩邊行為不一致。
 *
 * ⚠️ 「自訂時段…」這顆鈕**不能**掛 `.uappt-shift`：兩邊的班別列都把任何 `.uappt-shift`
 * 的點擊當成「排這個班」。
 *
 * 勾「存成班別」時，新的班別鈕立刻出現在班別列上（可以接著用），表單多帶幾個
 * `new_presets[]` 欄位，按「儲存這個月」時跟排好的日子一起存
 * （UAPPT_Admin::save_new_presets_from_post()）。
 *
 * @package Ultimate_Appointments
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<button type="button" class="uappt-shift-custom-toggle" aria-expanded="false"><?php esc_html_e( '自訂時段…', 'ultimate-appointments' ); ?></button>
<span class="uappt-shift-custom" hidden
	data-colors="<?php echo esc_attr( wp_json_encode( UAPPT_Shift_Preset::COLORS ) ); ?>"
	data-msg-time="<?php esc_attr_e( '請填開始與結束時間，例如 09:30 和 14:00。', 'ultimate-appointments' ); ?>"
	data-msg-same="<?php esc_attr_e( '開始和結束不能是同一個時間。', 'ultimate-appointments' ); ?>"
	data-msg-name="<?php esc_attr_e( '要存成班別的話，請幫它取個名字。', 'ultimate-appointments' ); ?>"
	data-msg-dup="<?php esc_attr_e( '已經有一個叫「%s」的班別了，換個名字吧。', 'ultimate-appointments' ); ?>">
	<span class="uappt-shift-custom-times">
		<label>
			<span class="screen-reader-text"><?php esc_html_e( '開始時間', 'ultimate-appointments' ); ?></span>
			<input type="text" inputmode="numeric" autocomplete="off" class="uappt-time-input uappt-custom-start" placeholder="09:00" />
		</label>
		<span aria-hidden="true">–</span>
		<label>
			<span class="screen-reader-text"><?php esc_html_e( '結束時間', 'ultimate-appointments' ); ?></span>
			<input type="text" inputmode="numeric" autocomplete="off" class="uappt-time-input uappt-custom-end" placeholder="18:00" />
		</label>
	</span>
	<label class="uappt-custom-save">
		<input type="checkbox" class="uappt-custom-save-check" />
		<?php esc_html_e( '存成班別', 'ultimate-appointments' ); ?>
	</label>
	<label class="uappt-custom-name-wrap" hidden>
		<span class="screen-reader-text"><?php esc_html_e( '班別名稱', 'ultimate-appointments' ); ?></span>
		<input type="text" class="uappt-custom-name" placeholder="<?php esc_attr_e( '班別名稱，例如：中班', 'ultimate-appointments' ); ?>" />
	</label>
	<button type="button" class="button button-primary uappt-custom-apply"><?php esc_html_e( '套用', 'ultimate-appointments' ); ?></button>
	<span class="uappt-custom-error" role="alert" hidden></span>
	<?php // 真正去排班的那顆：藏起來，JS 填好時段再按它。 ?>
	<button type="button" class="uappt-shift uappt-shift--hours uappt-custom-painter" hidden
		data-uappt-shift="hours" data-uappt-ranges="[]"><span class="uappt-shift-name"></span></button>
</span>
