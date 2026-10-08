<?php
/**
 * View：全店月排班表（v2.94.0）。人員 × 日期一張表。
 *
 * 傳入變數：$roster（UAPPT_Admin::render_roster_page() 組好的）
 * - month／prev_month／next_month  Y-m
 * - dates  date => {day, weekday（週一＝0）, week（第幾週，0 起）, is_past, today, shop（公休原因，空字串＝有開）}
 * - weeks／initial_week  這個月有幾週、週檢視一進來看哪一週（v2.95.0）
 * - rows   [{staff, plan, prev, template, requests}]，plan 是 UAPPT_Staff::get_range_plan() 的回傳值；
 *          prev／template 是兩顆產生器的資料（null＝這個人不適用，見 render_roster_page()）；
 *          requests 是這個人這個月的待審申請，日期 => {id, type, ranges, long, short, note}（v2.96.0）
 *
 * 計畫見 docs/staff-roster-plan.md 的 D5。操作跟人員編輯頁的月曆**同一套**：點格子
 * 選起來 → 下面點班別。不用重學。
 *
 * ⚠️ **格子不帶任何 name 的 input。** 改過的格子由 admin-roster.js 在送出時打包成
 * 一個 JSON 欄位（`roster`），理由見 UAPPT_Admin::handle_save_roster() 的 ⚠️
 * （max_input_vars）。沒有 JS 時格子點不動、送出的是空的，伺服器回「沒有變更」。
 *
 * @package Ultimate_Appointments
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$uappt_r_weekdays = array( '一', '二', '三', '四', '五', '六', '日' );
$uappt_r_shifts   = UAPPT_Shift_Preset::all();
$uappt_r_base     = UAPPT_Admin::url( 'staff', array( 'tab' => 'roster' ) );
$uappt_r_month_dt = date_create( $roster['month'] . '-01', wp_timezone() );
$uappt_r_month_tx = $uappt_r_month_dt ? wp_date( 'Y 年 n 月', $uappt_r_month_dt->getTimestamp() ) : $roster['month'];

// 每天幾個人上班（初始值；改了格子之後 JS 會重算同一個數字）。
// 24 小時人員沒有逐日的列，get_range_plan() 對他們回 clear——他們其實是全天
// 都在，要算進去，不然店裡只有一位 24 小時師傅時整排都是 0。
$uappt_r_counts = array_fill_keys( array_keys( $roster['dates'] ), 0 );
foreach ( $roster['rows'] as $uappt_r_row ) {
	foreach ( $uappt_r_row['plan'] as $uappt_r_date => $uappt_r_day ) {
		$uappt_r_allday = ! empty( $uappt_r_row['staff']['is_24h'] ) && 'clear' === $uappt_r_day['t'];
		if ( isset( $uappt_r_counts[ $uappt_r_date ] ) && ( 'hours' === $uappt_r_day['t'] || $uappt_r_allday ) ) {
			$uappt_r_counts[ $uappt_r_date ]++;
		}
	}
}

$uappt_r_has_template = false;
$uappt_r_requests     = 0;
foreach ( $roster['rows'] as $uappt_r_row ) {
	if ( null !== $uappt_r_row['template'] ) {
		$uappt_r_has_template = true;
	}
	$uappt_r_requests += count( $uappt_r_row['requests'] );
}
?>

<p class="uappt-page-desc">
	<?php esc_html_e( '全店這個月誰哪天上班，一張表看完。點格子選起來（可以連點，點人名選他整個月、點日期選那天所有人），再點下面的班別。', 'ultimate-appointments' ); ?>
</p>

<p class="uappt-cal-nav">
	<a class="button" href="<?php echo esc_url( add_query_arg( 'month', $roster['prev_month'], $uappt_r_base ) ); ?>">&laquo; <?php esc_html_e( '上個月', 'ultimate-appointments' ); ?></a>
	<strong class="uappt-cal-nav-label"><?php echo esc_html( $uappt_r_month_tx ); ?></strong>
	<a class="button" href="<?php echo esc_url( add_query_arg( 'month', $roster['next_month'], $uappt_r_base ) ); ?>"><?php esc_html_e( '下個月', 'ultimate-appointments' ); ?> &raquo;</a>
</p>

<?php if ( empty( $roster['rows'] ) ) : ?>
	<p>
		<?php esc_html_e( '還沒有任何啟用中的人員。', 'ultimate-appointments' ); ?>
		<a href="<?php echo esc_url( UAPPT_Admin::url( 'staff', array( 'action' => 'new' ) ) ); ?>"><?php esc_html_e( '新增一位', 'ultimate-appointments' ); ?></a>
	</p>
	<?php
	return;
endif;
?>

<?php if ( empty( $uappt_r_shifts ) ) : ?>
	<p class="description uappt-hint">
		<?php
		printf(
			/* translators: %s: 設定頁連結 */
			wp_kses_post( __( '還沒有設定班別，所以選了格子之後只排得出「例休」「請假」。到 %s 建好「早班」「晚班」，排班就只要點格子、再點班別。', 'ultimate-appointments' ) ),
			'<a href="' . esc_url( UAPPT_Admin::url( 'staff', array( 'tab' => 'shifts' ) ) ) . '">' . esc_html__( '班別設定', 'ultimate-appointments' ) . '</a>'
		);
		?>
	</p>
<?php endif; ?>

<?php if ( $uappt_r_requests > 0 ) : ?>
	<?php
	// 待審申請一進來就要看得到有幾筆（v2.96.0）。工讀生的流程是「員工說他能上 →
	// 主管湊人力」，這一行是那個流程的入口。
	?>
	<p class="uappt-roster-req-hint">
		<?php
		printf(
			/* translators: %d: 待審申請筆數 */
			esc_html__( '這個月有 %d 筆員工申請等你處理（虛線框的格子）。選起來，在下面按「接受申請」或「不接受」。', 'ultimate-appointments' ),
			(int) $uappt_r_requests
		);
		?>
		<a href="<?php echo esc_url( UAPPT_Admin::url( 'staff', array( 'tab' => 'requests' ) ) ); ?>"><?php esc_html_e( '看申請明細', 'ultimate-appointments' ); ?></a>
	</p>
<?php endif; ?>

<?php
// 圖例。這張表一格只放得下兩三個字，顏色得自己說話——而顏色的意思不能靠猜。
?>
<p class="uappt-roster-legend" aria-hidden="true">
	<?php
	// 班別各自的顏色（v2.99.0）排在最前面——這張表最常要認的就是「這格是哪個班」。
	// 「上班」那一格留著：時段對不上任何班別的日子仍然是淡綠。
	foreach ( $uappt_r_shifts as $uappt_r_legend ) :
		?>
		<span class="<?php echo esc_attr( UAPPT_Shift_Preset::color_class( $uappt_r_legend['color'] ) ); ?>"><?php echo esc_html( $uappt_r_legend['name'] ); ?></span>
	<?php endforeach; ?>
	<span><span class="uappt-roster-swatch is-open"></span><?php echo esc_html( $uappt_r_shifts ? __( '其他時段', 'ultimate-appointments' ) : __( '上班', 'ultimate-appointments' ) ); ?></span>
	<span><span class="uappt-roster-swatch is-closed"></span><?php esc_html_e( '例休／請假', 'ultimate-appointments' ); ?></span>
	<span><span class="uappt-roster-swatch is-unset"></span><?php esc_html_e( '未排班', 'ultimate-appointments' ); ?></span>
	<span><span class="uappt-roster-swatch is-shop-closed"></span><?php esc_html_e( '店休', 'ultimate-appointments' ); ?></span>
	<?php if ( $uappt_r_requests > 0 ) : ?>
		<span><span class="uappt-roster-swatch has-request"></span><?php esc_html_e( '員工申請（待審）', 'ultimate-appointments' ); ?></span>
	<?php endif; ?>
</p>

<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="uappt-roster-form">
	<input type="hidden" name="action" value="uappt_save_roster" />
	<input type="hidden" name="month" value="<?php echo esc_attr( $roster['month'] ); ?>" />
	<input type="hidden" name="roster" value="" class="uappt-roster-payload" />
	<?php // 待審申請的決定（申請 ID => approve｜reject），送出時由 JS 填。 ?>
	<input type="hidden" name="roster_requests" value="" class="uappt-roster-requests-payload" />
	<?php // 週檢視時由 JS 填目前那一週，存檔後回到同一週。 ?>
	<input type="hidden" name="week" value="" class="uappt-roster-week-input" />
	<?php wp_nonce_field( 'uappt_save_roster' ); ?>

	<?php
	// 工具列（v2.95.0）：產生器、整月／一週、切週。三樣都只有 JS 才有作用，所以
	// 整列預設 hidden、由 admin-roster.js 打開——沒有 JS 的人不會看到一排點了沒
	// 反應的鈕。
	?>
	<div class="uappt-roster-toolbar" hidden>
		<span class="uappt-generators">
			<span class="uappt-generators-label"><?php esc_html_e( '一次排整個月', 'ultimate-appointments' ); ?></span>
			<button type="button" class="button uappt-roster-gen" data-gen="prev"><?php esc_html_e( '複製上個月', 'ultimate-appointments' ); ?></button>
			<?php if ( $uappt_r_has_template ) : ?>
				<button type="button" class="button uappt-roster-gen" data-gen="template"><?php esc_html_e( '套用固定班樣板', 'ultimate-appointments' ); ?></button>
			<?php endif; ?>
		</span>

		<span class="uappt-roster-view" role="group" aria-label="<?php esc_attr_e( '檢視方式', 'ultimate-appointments' ); ?>">
			<button type="button" class="uappt-roster-view-btn" data-view="month" aria-pressed="false"><?php esc_html_e( '整月', 'ultimate-appointments' ); ?></button>
			<button type="button" class="uappt-roster-view-btn" data-view="week" aria-pressed="false"><?php esc_html_e( '一週', 'ultimate-appointments' ); ?></button>
		</span>

		<span class="uappt-roster-weeknav" hidden>
			<button type="button" class="button uappt-roster-week-step" data-step="-1" aria-label="<?php esc_attr_e( '上一週', 'ultimate-appointments' ); ?>">&lsaquo;</button>
			<strong class="uappt-roster-week-label" aria-live="polite"></strong>
			<button type="button" class="button uappt-roster-week-step" data-step="1" aria-label="<?php esc_attr_e( '下一週', 'ultimate-appointments' ); ?>">&rsaquo;</button>
		</span>
	</div>

	<div class="uappt-roster-scroll">
		<table class="uappt-roster" data-view="month"
			data-weeks="<?php echo (int) $roster['weeks']; ?>"
			data-show-week="<?php echo (int) $roster['initial_week']; ?>">
			<thead>
				<tr>
					<th scope="col" class="uappt-roster-corner"><?php esc_html_e( '人員', 'ultimate-appointments' ); ?></th>
					<?php $uappt_r_col = 0; ?>
					<?php foreach ( $roster['dates'] as $uappt_r_date => $uappt_r_info ) : ?>
						<?php
						$uappt_r_head_class = 'uappt-roster-day';
						$uappt_r_head_class .= $uappt_r_info['weekday'] >= 5 ? ' is-weekend' : '';
						$uappt_r_head_class .= $uappt_r_info['today'] ? ' is-today' : '';
						$uappt_r_head_class .= '' !== $uappt_r_info['shop'] ? ' is-shop-closed' : '';
						?>
						<th scope="col" class="<?php echo esc_attr( $uappt_r_head_class ); ?>"
							data-week="<?php echo (int) $uappt_r_info['week']; ?>"
							data-short="<?php echo esc_attr( (int) substr( $uappt_r_date, 5, 2 ) . '/' . (int) $uappt_r_info['day'] ); ?>"
							<?php echo '' !== $uappt_r_info['shop'] ? 'title="' . esc_attr( $uappt_r_info['shop'] ) . '"' : ''; ?>>
							<?php
							// 點日期＝選那天所有人。⚠️ type="button" 不能省：這一列在表單
							// 裡面，預設的 submit 會直接把表單送出。
							?>
							<button type="button" class="uappt-roster-col" data-col="<?php echo (int) $uappt_r_col; ?>"
								aria-label="<?php echo esc_attr( sprintf( /* translators: %s: 日期 */ __( '選取 %s 所有人', 'ultimate-appointments' ), $uappt_r_date ) ); ?>">
								<span class="uappt-roster-day-num"><?php echo (int) $uappt_r_info['day']; ?></span>
								<span class="uappt-roster-day-wd"><?php echo esc_html( $uappt_r_weekdays[ $uappt_r_info['weekday'] ] ); ?></span>
							</button>
						</th>
						<?php $uappt_r_col++; ?>
					<?php endforeach; ?>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $roster['rows'] as $uappt_r_row ) : ?>
					<?php
					$uappt_r_staff = $uappt_r_row['staff'];
					$uappt_r_24h   = ! empty( $uappt_r_staff['is_24h'] );
					$uappt_r_edit  = UAPPT_Admin::url(
						'staff',
						array(
							'action'   => 'edit',
							'staff_id' => $uappt_r_staff['id'],
							'month'    => $roster['month'],
						)
					) . '#uappt-month-schedule';
					?>
					<tr data-staff="<?php echo (int) $uappt_r_staff['id']; ?>" data-edit="<?php echo esc_url( $uappt_r_edit ); ?>"
						<?php if ( null !== $uappt_r_row['prev'] ) : ?>data-prev="<?php echo esc_attr( wp_json_encode( $uappt_r_row['prev'] ) ); ?>"<?php endif; ?>
						<?php if ( null !== $uappt_r_row['template'] ) : ?>data-template="<?php echo esc_attr( wp_json_encode( $uappt_r_row['template'] ) ); ?>"<?php endif; ?>>
						<th scope="row" class="uappt-roster-name">
							<?php if ( $uappt_r_24h ) : ?>
								<?php // 24 小時人員整列不能排（見 handle_save_roster() 的 ⚠️），名字就不是按鈕。 ?>
								<span class="uappt-roster-name-text"><?php echo esc_html( $uappt_r_staff['name'] ); ?></span>
							<?php else : ?>
								<button type="button" class="uappt-roster-row"
									aria-label="<?php echo esc_attr( sprintf( /* translators: %s: 人員姓名 */ __( '選取 %s 整個月', 'ultimate-appointments' ), $uappt_r_staff['name'] ) ); ?>">
									<?php echo esc_html( $uappt_r_staff['name'] ); ?>
								</button>
							<?php endif; ?>
							<span class="uappt-roster-kind"><?php echo esc_html( UAPPT_Staff::schedule_kind_label( $uappt_r_staff ) ); ?></span>
						</th>
						<?php $uappt_r_col = 0; ?>
						<?php foreach ( $roster['dates'] as $uappt_r_date => $uappt_r_info ) : ?>
							<?php
							$uappt_r_day = isset( $uappt_r_row['plan'][ $uappt_r_date ] )
								? $uappt_r_row['plan'][ $uappt_r_date ]
								: array( 't' => 'clear', 'r' => array(), 'l' => '', 's' => '' );
							$uappt_r_type   = $uappt_r_day['t'];
							$uappt_r_allday = $uappt_r_24h && 'clear' === $uappt_r_type;
							$uappt_r_shop   = '' !== $uappt_r_info['shop'];

							if ( $uappt_r_allday ) {
								// 不寫「全天」：班別名稱是店家自己取的，「全天」很常見（這個
								// 站就有一個），兩者印成同一個字就分不出是哪一種。
								$uappt_r_state = 'is-open';
								$uappt_r_label = __( '24h', 'ultimate-appointments' );
								$uappt_r_long  = __( '24 小時', 'ultimate-appointments' );
							} elseif ( 'hours' === $uappt_r_type ) {
								$uappt_r_state = 'is-open';
								$uappt_r_label = $uappt_r_day['s'];
								$uappt_r_long  = $uappt_r_day['l'];
							} elseif ( 'clear' === $uappt_r_type ) {
								// 店休日沒排＝店休，不是「忘了排」：顏色要分得出來（理由同
								// month-grid.php 的店休註解）。
								$uappt_r_state = $uappt_r_shop ? 'is-shop-closed' : 'is-unset';
								$uappt_r_label = '';
								$uappt_r_long  = $uappt_r_shop ? $uappt_r_info['shop'] : $uappt_r_day['l'];
							} else {
								$uappt_r_state = 'is-closed';
								$uappt_r_label = $uappt_r_day['l'];
								$uappt_r_long  = $uappt_r_day['l'];
							}

							// 只有「當月、非過去、不是 24 小時」的格子能排——跟人員編輯頁的
							// 月曆同一條規則（過去的日子改了沒有意義）。
							$uappt_r_paintable = ! $uappt_r_24h && ! $uappt_r_info['is_past'];

							$uappt_r_class  = 'uappt-roster-cell ' . $uappt_r_state;
							// 班別顏色（v2.99.0），由 get_range_plan() 的 'c' 帶過來。
							if ( 'hours' === $uappt_r_type && ! empty( $uappt_r_day['c'] ) ) {
								$uappt_r_class .= ' ' . UAPPT_Shift_Preset::color_class( $uappt_r_day['c'] );
							}
							$uappt_r_class .= $uappt_r_paintable ? ' is-paintable' : '';
							$uappt_r_class .= $uappt_r_info['is_past'] ? ' is-past' : '';
							$uappt_r_class .= $uappt_r_info['today'] ? ' is-today' : '';
							$uappt_r_class .= $uappt_r_info['weekday'] >= 5 ? ' is-weekend' : '';

							// 這一天有沒有待審申請（v2.96.0）。有的話格子加虛線框、下面多一行
							// 申請內容；可以排的格子才能接受／不接受（過去的日子只是顯示）。
							$uappt_r_req = isset( $uappt_r_row['requests'][ $uappt_r_date ] ) ? $uappt_r_row['requests'][ $uappt_r_date ] : null;
							if ( $uappt_r_req ) {
								$uappt_r_class .= ' has-request';
							}

							// 給人看的完整描述（滑鼠停留、螢幕閱讀器）。「誰、哪天、排什麼」
							// 三樣都要有——一格裡只看得到兩個字。
							$uappt_r_head = sprintf(
								/* translators: 1: 人員姓名 2: 月/日 3: 星期 */
								__( '%1$s %2$s（%3$s）', 'ultimate-appointments' ),
								$uappt_r_staff['name'],
								(int) substr( $uappt_r_date, 5, 2 ) . '/' . (int) $uappt_r_info['day'],
								$uappt_r_weekdays[ $uappt_r_info['weekday'] ]
							);
							?>
							<td class="<?php echo esc_attr( $uappt_r_class ); ?>"
								data-col="<?php echo (int) $uappt_r_col; ?>"
								data-week="<?php echo (int) $uappt_r_info['week']; ?>"
								data-date="<?php echo esc_attr( $uappt_r_date ); ?>"
								data-type="<?php echo esc_attr( $uappt_r_allday ? 'allday' : $uappt_r_type ); ?>"
								data-ranges="<?php echo esc_attr( wp_json_encode( array_values( (array) $uappt_r_day['r'] ) ) ); ?>"
								data-head="<?php echo esc_attr( $uappt_r_head ); ?>"
								<?php if ( $uappt_r_shop ) : ?>data-shop="1"<?php endif; ?>
								<?php if ( $uappt_r_paintable ) : ?>role="button" tabindex="0"<?php endif; ?>
								<?php if ( $uappt_r_req ) : ?>data-request="<?php echo esc_attr( wp_json_encode( $uappt_r_req ) ); ?>"<?php endif; ?>
								title="<?php
								echo esc_attr(
									trim( $uappt_r_head . ' ' . $uappt_r_long )
									. ( $uappt_r_req
										? '｜' . sprintf(
											/* translators: %s: 申請內容 */
											__( '員工申請：%s', 'ultimate-appointments' ),
											$uappt_r_req['long']
										) . ( '' !== $uappt_r_req['note'] ? '（' . $uappt_r_req['note'] . '）' : '' )
										: '' )
								);
								?>">
								<span class="uappt-roster-label"><?php echo esc_html( $uappt_r_label ); ?></span>
								<?php if ( $uappt_r_req ) : ?>
									<span class="uappt-roster-req"><?php echo esc_html( $uappt_r_req['short'] ); ?></span>
								<?php endif; ?>
							</td>
							<?php $uappt_r_col++; ?>
						<?php endforeach; ?>
					</tr>
				<?php endforeach; ?>
			</tbody>
			<tfoot>
				<tr>
					<th scope="row" class="uappt-roster-name"><?php esc_html_e( '上班人數', 'ultimate-appointments' ); ?></th>
					<?php $uappt_r_col = 0; ?>
					<?php foreach ( $roster['dates'] as $uappt_r_date => $uappt_r_info ) : ?>
						<?php
						// 0 人在店休日是正常的，印「—」；在營業日是該注意的，標出來。
						$uappt_r_n     = $uappt_r_counts[ $uappt_r_date ];
						$uappt_r_shop  = '' !== $uappt_r_info['shop'];
						?>
						<td class="uappt-roster-count<?php echo ( 0 === $uappt_r_n && ! $uappt_r_shop ) ? ' is-empty' : ''; ?>"
							data-col="<?php echo (int) $uappt_r_col; ?>"
							data-week="<?php echo (int) $uappt_r_info['week']; ?>"
							<?php if ( $uappt_r_shop ) : ?>data-shop="1"<?php endif; ?>>
							<?php echo ( 0 === $uappt_r_n && $uappt_r_shop ) ? '—' : (int) $uappt_r_n; ?>
						</td>
						<?php $uappt_r_col++; ?>
					<?php endforeach; ?>
				</tr>
			</tfoot>
		</table>
	</div>

	<?php
	// 動作列黏在畫面底部：表一長，選了上面的格子之後下面的班別鈕已經捲出畫面，
	// 使用者會以為「選了沒反應」。人員編輯頁的月曆只有一個人、不會這麼長，不需要。
	?>
	<div class="uappt-roster-actions">
		<p class="uappt-shift-pick" hidden>
			<?php // 樣板字串交給 JS 代入，翻譯留在 PHP 這一側。 ?>
			<span class="uappt-shift-pick-label"
				data-one="<?php esc_attr_e( '%s 要排什麼？', 'ultimate-appointments' ); ?>"
				data-many="<?php esc_attr_e( '已選 %s 格，要排什麼？', 'ultimate-appointments' ); ?>"></span>

			<?php
			// 接受／不接受申請（v2.96.0）。只有選到的格子裡有待審申請時才出現，數字
			// 是「會處理幾筆」——選了一整列 30 格、其中 6 格有申請，按下去處理的是
			// 那 6 筆，不是 30 格。
			//
			// 排在班別**前面**：選到有申請的格子時，主要的決定就是准不准，不該夾在
			// 一排班別中間（手機上會被擠到第二、三排）。
			?>
			<button type="button" class="uappt-shift uappt-roster-decide uappt-roster-decide--approve" data-decision="approve" hidden
				data-template="<?php esc_attr_e( '接受 %d 筆申請', 'ultimate-appointments' ); ?>"></button>
			<button type="button" class="uappt-shift uappt-roster-decide uappt-roster-decide--reject" data-decision="reject" hidden
				data-template="<?php esc_attr_e( '不接受 %d 筆', 'ultimate-appointments' ); ?>"></button>

			<?php foreach ( $uappt_r_shifts as $uappt_r_shift ) : ?>
				<button type="button" class="uappt-shift uappt-shift--hours <?php echo esc_attr( UAPPT_Shift_Preset::color_class( $uappt_r_shift['color'] ) ); ?>"
					data-uappt-color="<?php echo esc_attr( $uappt_r_shift['color'] ); ?>"
					data-uappt-shift="hours"
					data-uappt-ranges="<?php echo esc_attr( wp_json_encode( $uappt_r_shift['ranges'] ) ); ?>"
					data-uappt-long="<?php echo esc_attr( $uappt_r_shift['name'] . ' ' . UAPPT_Shift_Preset::format_ranges( $uappt_r_shift['ranges'] ) ); ?>">
					<span class="uappt-shift-name"><?php echo esc_html( $uappt_r_shift['name'] ); ?></span>
					<span class="uappt-shift-time"><?php echo esc_html( UAPPT_Shift_Preset::format_ranges( $uappt_r_shift['ranges'] ) ); ?></span>
				</button>
			<?php endforeach; ?>

			<?php require UAPPT_PLUGIN_DIR . 'includes/views/partials/shift-custom.php'; // 自訂時段（v2.100.0） ?>
			<button type="button" class="uappt-shift uappt-shift--off" data-uappt-shift="off" data-uappt-ranges="[]">
				<span class="uappt-shift-name"><?php esc_html_e( '例休', 'ultimate-appointments' ); ?></span>
			</button>
			<button type="button" class="uappt-shift uappt-shift--leave" data-uappt-shift="leave" data-uappt-ranges="[]">
				<span class="uappt-shift-name"><?php esc_html_e( '請假', 'ultimate-appointments' ); ?></span>
			</button>
			<button type="button" class="uappt-shift uappt-shift--clear" data-uappt-shift="clear" data-uappt-ranges="[]"
				data-uappt-long="<?php esc_attr_e( '未排班', 'ultimate-appointments' ); ?>">
				<span class="uappt-shift-name"><?php esc_html_e( '清除', 'ultimate-appointments' ); ?></span>
			</button>

			<?php
			// 只選一格時：非標準時段、備註要到那個人的頁面改（全店表不做備註，見計畫
			// D5 的「明確不做」）。是連結不是表單，未儲存的變更會被離開頁面的提示攔下。
			?>
			<a class="button-link uappt-roster-detail" href="#" hidden><?php esc_html_e( '其他時段、備註…', 'ultimate-appointments' ); ?></a>
			<button type="button" class="button-link uappt-shift-cancel"><?php esc_html_e( '取消選取', 'ultimate-appointments' ); ?></button>
			<?php // 「管理班別」（v2.98.0），理由同人員編輯頁的同一顆。 ?>
			<a class="button-link uappt-manage-shifts" href="<?php echo esc_url( UAPPT_Admin::url( 'staff', array( 'tab' => 'shifts' ) ) ); ?>"><?php esc_html_e( '管理班別', 'ultimate-appointments' ); ?></a>
		</p>

		<p class="uappt-paint-actions">
			<?php submit_button( __( '儲存這個月', 'ultimate-appointments' ), 'primary', '', false ); ?>
			<button type="button" class="button uappt-roster-reset" hidden><?php esc_html_e( '還原未儲存的變更', 'ultimate-appointments' ); ?></button>
			<span class="uappt-paint-dirty uappt-roster-dirty" data-template="<?php esc_attr_e( '未儲存 %d 格', 'ultimate-appointments' ); ?>" hidden></span>
		</p>
	</div>

	<?php
	UAPPT_Admin::help(
		array(
			__( '按「儲存這個月」才會寫進去；儲存之前重新整理就會回到原狀，可以放心先排排看。', 'ultimate-appointments' ),
			__( '只有改過的格子會被送出——沒碰過的日子不會被重寫，員工排班申請核准後留下的紀錄也就不會被洗掉。原本的備註也會保留。', 'ultimate-appointments' ),
			__( '「複製上個月」：每個人照上個月同一週、同一個星期幾排過來（班跟著星期走，不是跟著日期）。上個月一天都沒排的人不動。', 'ultimate-appointments' ),
			__( '「套用固定班樣板」：只動固定班的人，彈性班不動。手動改過的日子會被排回樣板的樣子。', 'ultimate-appointments' ),
			__( '「上班人數」是當天有上班時段的人數，排的時候會跟著變。', 'ultimate-appointments' ),
			__( '24 小時的人員整列不能排；過去的日子只能看。', 'ultimate-appointments' ),
			__( '虛線框是員工送來、還沒處理的排班申請。「接受申請」會照員工申請的排上去；「不接受」會通知員工沒有核准。兩種都要按「儲存這個月」才會生效，而且每位員工只會收到一封總結信。', 'ultimate-appointments' ),
			__( '在有申請的格子上直接排別的班，申請會留著等你另外處理（虛線框不會消失）。', 'ultimate-appointments' ),
			__( '會影響已經成立的預約的申請不會被核准，存檔後會告訴你是哪幾筆。時段佔用的申請不顯示在這張表上，請到「排班申請」處理。', 'ultimate-appointments' ),
		)
	);
	?>
</form>
