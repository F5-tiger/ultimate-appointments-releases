<?php
/**
 * Partial：前台員工中心的月曆格線，「我的班表」與「本月明細」共用。
 *
 * 兩頁本來各自印一份幾乎一樣的月曆（同樣的週標題、同樣的格子 class 規則、
 * 同樣的休假／未排班徽章），只差在「格子能不能勾選」跟「日期數字連去哪」。
 * 兩份各自長歪只是時間問題——v2.23.0 把明細頁改成月曆式的時候，兩邊對「今天」
 * 的處理就已經開始不一致了，所以先收成一份。
 *
 * 傳入變數（呼叫端要先設好，全部必填，沒有預設值是刻意的：漏設一個會是空白
 * 而不是「看起來對、其實少一半」）：
 *
 * - $calendar            UAPPT_Staff_Portal::get_schedule_month() 的回傳值
 * - $grid_mode           'schedule'（我的班表）或 'detail'（本月明細）。兩頁問的是不同的
 *                        問題，格子裡該放什麼也就不一樣——見下面「兩種模式」的說明
 * - $grid_selectable     bool，格子裡要不要放勾選框（只有「我的班表」要，用來選日期送排班申請）
 * - $grid_day_url        string，格子要連去哪（空字串＝不連結）。實際連結會再帶上 uappt_date
 * - $grid_linkable       array|null，只有這些日期要變成連結；null＝所有當月的日子都連
 * - $grid_selected_date  string，要標示成「目前選取」的日期（Y-m-d），空字串＝沒有
 * - $grid_amounts        array，date => 當日業績小計；空陣列＝不顯示金額（見設定「讓員工看到自己的業績金額」）
 * - $grid_paintable      bool，格子要不要帶「這一天要存成什麼」的 hidden input（只有後台
 *                        人員編輯頁的月曆塗抹要）。⚠️ **印出來的 input 一律是 `disabled`**
 *                        ——disabled 的欄位不會被送出，所以「有沒有 disabled」就是這一天
 *                        的 dirty 旗標本身，不用另外發明一個。沒有 JS 時全部維持 disabled，
 *                        按下儲存等於沒有任何變更，不會把整個月原封不動重寫一次（那會把
 *                        核准排班申請留下的 source／request_id 憑證洗掉）
 * - $grid_editable       bool，格子要不要變成「點一下就編輯這一天」（只有後台人員編輯頁要）。
 *                        為 true 時每一格會多帶 data-uappt-* 描述這天目前的狀態，給
 *                        admin-staff.js 拿去填那張**既有的**單日調整表單——不是 AJAX，
 *                        寫入仍然走 admin-post + nonce（設計紀律 #8）
 *
 * **兩種模式**（v2.26.0）：
 *
 * | | `schedule`（我的班表） | `detail`（本月明細） |
 * | --- | --- | --- |
 * | 要回答的問題 | 這個月我怎麼上班 | 哪天幾點有客人 |
 * | 格子內容 | 上班時段、休假／未排班、待審核 | **預約時間**、筆數、業績 |
 * | 底色 | 有班／休假／未排班 | **當天忙不忙**（預約筆數的濃淡階梯） |
 * | 怎麼點 | 勾選框選日期（數字連到明細） | **整格都可以點** |
 *
 * `detail` 模式刻意**不印上班時間、未排班與待審核**：那些都是排班的事，放在這裡
 * 只會把真正要看的預約時間擠掉（手機上一格只有幾十像素）。休假保留，因為它解釋了
 * 「這天為什麼是空的」。
 *
 * **格子分得出來的五種狀態**（v2.84.0）：上班（綠底＋時段）／例休（灰底「例休」）／
 * 請假（灰底「請假」）／未排班（灰底「未排班」，**沒有那一列**）／店休（紅底）。
 * 前三者都有 override 列，第四者沒有——而「有沒有那一列」正是之後「班表排到哪一天」
 * 要量的東西，所以例休一定要寫一列，不能用「不寫」來表達。
 *
 * @package Ultimate_Appointments
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 標題列。**一定要跟格線的起算日一致。**
 *
 * ⚠️ 這裡原本寫的是「日一二三四五六」，而 `UAPPT_Staff::get_schedule_month()` 的
 * `$grid_from` 是用 ISO 星期算的（`uappt_local_weekday_iso()`，週一＝1），每一列
 * 都**從週一開始**——結果是整張月曆每一格都掛在錯一格的星期底下：週一的日子
 * 印在「日」那一欄，週日的印在「六」。
 *
 * 三個呼叫端（後台人員編輯、前台我的班表、本月明細）全都受影響，而且不會有任何
 * 地方報錯：日期數字本身是對的，只有頭上那一排字是錯的，光看畫面很容易以為是
 * 自己數錯。v2.85.0 做「點星期標題排整欄」時才被逼出來——點「日」結果整排週一
 * 被塗掉。
 *
 * 選擇改標題而不是改 `$grid_from`：外掛其他地方的星期順序一律是週一起算
 * （人員編輯頁的每週班表就是「週一 → 週日」），所以週一起算才是這裡的慣例，
 * 錯的是這一行。
 */
$uappt_grid_weekday_labels = array( '一', '二', '三', '四', '五', '六', '日' );

/**
 * 跟標題列同一個順序的星期鍵。
 *
 * ⚠️ **不要用 `UAPPT_Staff::WEEKDAY_KEYS`**：那一份是 `sun` 開頭（它對應的是
 * PHP `w` 的 0–6，用在資料面），跟這張表的欄位順序不一樣。混用就是上面那個 bug
 * 的來源。
 */
$uappt_grid_weekday_keys = array( 'mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun' );
$uappt_grid_is_detail      = ( 'detail' === $grid_mode );

/**
 * 「這天忙不忙」的濃淡等級（0–3），只有 detail 模式用得到。
 *
 * 門檻刻意寫死，**不是**照當月最大值換算成相對比例：相對比例會讓一個很閒的
 * 月份看起來跟旺季一樣滿，色深在月與月之間就失去意義了。固定門檻的代價是
 * 大店家可能整片都是深色，但那也是一個誠實的訊號。
 *
 * @param int $count 當天的預約／時段佔用筆數。
 * @return int
 */
$uappt_grid_busy_level = function ( $count ) {
	if ( $count <= 0 ) {
		return 0;
	}
	if ( $count <= 2 ) {
		return 1;
	}
	return $count <= 4 ? 2 : 3;
};
?>
<div class="uappt-cal uappt-cal--<?php echo esc_attr( $uappt_grid_is_detail ? 'detail' : 'schedule' ); ?>">
	<div class="uappt-cal-weekday-head">
		<?php foreach ( $uappt_grid_weekday_labels as $uappt_grid_wi => $uappt_grid_wd ) : ?>
			<?php if ( ! empty( $grid_paintable ) ) : ?>
				<?php
				// 整欄一次塗完（「每週日都排休」）。⚠️ type="button" 不能省——這個
				// 標題列在塗抹模式下位於表單裡面，預設的 submit 會直接把表單送出。
				//
				// 觸控裝置上這是**唯一**的批次手段：拖曳塗抹只綁滑鼠事件，因為在
				// 手機上拖曳會跟捲動打架（見 admin-staff.js）。所以它不是桌機的
				// 快捷鍵，是手機的必需品。
				?>
				<?php
				// ⚠️ 欄位索引**由 PHP 直接帶出去**，JS 不要自己維護第二份星期順序
				// ——那份複本跟這裡不一致，就是上面那個「點『日』塗到週一」的 bug。
				// data-weekday 只是給人看的（除錯時知道這欄是星期幾）。
				?>
				<button type="button" class="uappt-cal-weekday-paint"
					data-column="<?php echo (int) $uappt_grid_wi; ?>"
					data-weekday="<?php echo esc_attr( $uappt_grid_weekday_keys[ $uappt_grid_wi ] ); ?>">
					<?php echo esc_html( $uappt_grid_wd ); ?>
				</button>
			<?php else : ?>
				<span><?php echo esc_html( $uappt_grid_wd ); ?></span>
			<?php endif; ?>
		<?php endforeach; ?>
	</div>

	<?php foreach ( $calendar['weeks'] as $uappt_grid_week ) : ?>
		<div class="uappt-cal-week">
			<?php foreach ( $uappt_grid_week as $uappt_grid_day ) : ?>
				<?php
				$uappt_grid_date      = $uappt_grid_day['date'];
				$uappt_grid_closed    = $uappt_grid_day['override'] && ! empty( $uappt_grid_day['override']['is_closed'] );
				// 例休（排定不上班）跟請假（本來要上班、臨時不上）在班表上都是整天
				// 不開放，所以**底色共用 is-closed**，不另外開一種灰——真正要分的是
				// 徽章上那兩個字。底色再多一階只會讓三種灰疊在一起更難分。
				$uappt_grid_reason    = $uappt_grid_closed && isset( $uappt_grid_day['override']['closed_reason'] )
					? (string) $uappt_grid_day['override']['closed_reason']
					: '';
				$uappt_grid_off       = ( UAPPT_Staff::CLOSED_OFF === $uappt_grid_reason );
				$uappt_grid_off_label = $uappt_grid_closed ? UAPPT_Staff::closed_reason_label( $uappt_grid_reason ) : '';
				// 全店公休（v2.80.0）。跟「這位人員請假」是兩回事，要分得出來——
				// 看到灰底卻不知道是「他請假」還是「店沒開」，會跑去改錯的地方。
				$uappt_grid_shop      = UAPPT_Shop_Closure::reason( $uappt_grid_date );
				$uappt_grid_shop_off  = ( '' !== $uappt_grid_shop );
				$uappt_grid_has_hours = ! empty( $uappt_grid_day['ranges'] );
				$uappt_grid_bookings  = $uappt_grid_day['bookings'];
				$uappt_grid_count     = count( $uappt_grid_bookings );

				$uappt_grid_class  = 'uappt-cal-day';
				$uappt_grid_class .= $uappt_grid_day['is_in_month'] ? '' : ' is-outside';
				$uappt_grid_class .= $uappt_grid_day['is_today'] ? ' is-today' : '';

				if ( $uappt_grid_is_detail ) {
					// 底色表達的是「這天忙不忙」，不是「有沒有班」——所以刻意不發
					// is-open／is-unset 這兩個 class，班表模式的綠底／灰底規則就完全
					// 不會套到這一頁來。休假保留：它解釋了這天為什麼是空的。
					$uappt_grid_class .= $uappt_grid_closed ? ' is-closed' : '';
					$uappt_grid_class .= ' is-busy-' . $uappt_grid_busy_level( $uappt_grid_count );
				} else {
					if ( $uappt_grid_shop_off ) {
						$uappt_grid_class .= ' is-shop-closed';
					} else {
						$uappt_grid_class .= $uappt_grid_closed ? ' is-closed' : ( $uappt_grid_has_hours ? ' is-open' : ' is-unset' );
						// 班別顏色（v2.99.0）：時段剛好等於某個班別時，格子用那個班別的顏色。
						// 樣式只寫在後台的 admin.css，前台員工中心的月曆拿到這幾個 class 也
						// 不會變色。
						if ( ! $uappt_grid_closed && $uappt_grid_has_hours ) {
							$uappt_grid_color  = UAPPT_Shift_Preset::color_class( UAPPT_Shift_Preset::color_for_ranges( $uappt_grid_day['ranges'] ) );
							$uappt_grid_class .= '' !== $uappt_grid_color ? ' ' . $uappt_grid_color : '';
						}
					}
				}

				$uappt_grid_class .= ( '' !== $grid_selected_date && $uappt_grid_date === $grid_selected_date ) ? ' is-selected' : '';

				// 勾選框只在「未來的日子 + 有送出申請的權限」時出現，跟改版前一樣；
				// 沒有勾選框的格子就不該是 <label>（點下去什麼都不會發生的 label 是
				// 一個假的可點擊提示）。
				$uappt_grid_cell_selectable = $grid_selectable && ! $uappt_grid_day['is_past'];

				$uappt_grid_is_link = ( '' !== $grid_day_url )
					&& $uappt_grid_day['is_in_month']
					&& ( null === $grid_linkable || isset( $grid_linkable[ $uappt_grid_date ] ) );

				// **整格都可以點**（只在沒有勾選框的模式下）。點一個小小的日期數字
				// 在手機上很難瞄準，而整格是一個 46px 以上的目標；用 <a> 而不是 JS
				// 綁 click，鍵盤 Tab、右鍵開新分頁、長按預覽全部免費拿到。
				//
				// 有勾選框時**不能**整格變連結：那一頁點格子的意思是「選這天送排班
				// 申請」，變成連結會直接跳走。那一頁維持只有日期數字是連結。
				$uappt_grid_whole_cell_link = $uappt_grid_is_link && ! $uappt_grid_cell_selectable;

				if ( $uappt_grid_cell_selectable ) {
					$uappt_grid_tag = 'label';
				} elseif ( $uappt_grid_whole_cell_link ) {
					$uappt_grid_tag = 'a';
				} else {
					$uappt_grid_tag = 'div';
				}

				$uappt_grid_href = $uappt_grid_is_link
					? add_query_arg( 'uappt_date', $uappt_grid_date, $grid_day_url )
					: '';

				// 格子裡是一堆零碎的數字與時間，螢幕閱讀器逐項唸出來會聽不懂是哪天
				// 的什麼，所以整格連結自己帶一句完整的描述。
				$uappt_grid_dt    = date_create( $uappt_grid_date . ' 00:00:00', wp_timezone() );
				$uappt_grid_label = $uappt_grid_dt ? wp_date( 'n 月 j 日', $uappt_grid_dt->getTimestamp() ) : $uappt_grid_date;
				if ( $uappt_grid_count > 0 ) {
					$uappt_grid_label .= '，' . sprintf(
						/* translators: %d: 預約筆數 */
						__( '%d 筆預約', 'ultimate-appointments' ),
						$uappt_grid_count
					);
				} elseif ( $uappt_grid_closed ) {
					$uappt_grid_label .= '，' . $uappt_grid_off_label;
				} elseif ( $uappt_grid_shop_off ) {
					$uappt_grid_label .= '，' . $uappt_grid_shop;
				} else {
					$uappt_grid_label .= '，' . __( '沒有預約', 'ultimate-appointments' );
				}
				?>
				<?php
				// 可編輯模式：把這一天「目前長怎樣」原樣帶出去，讓 JS 能把單日
				// 表單填成現況（而不是每次都從空白開始重填）。ranges 用的是
				// **解析後**的結果（範本＋覆蓋＋店休都算進去了），跟格子上印出來
				// 的數字必然一致——另外算一份遲早會跟畫面對不起來。
				$uappt_grid_data = '';
				if ( ! empty( $grid_editable ) ) {
					// ⚠️ `off` 從「整天不開放」收窄成「例休」，另外多一個 `leave`。
					// admin-staff.js 用這個值決定單日表單要預選哪一個類型——兩者混在
					// 一起的話，點一個請假的日子會被預選成例休，一存檔請假紀錄就沒了。
					$uappt_grid_state = $uappt_grid_shop_off
						? 'shop'
						: ( $uappt_grid_closed
							? ( $uappt_grid_off ? 'off' : 'leave' )
							: ( $uappt_grid_day['override'] ? 'custom' : 'default' ) );
					// role/tabindex：格子在這個情境下不是連結（$grid_day_url 是空的），
					// 不補的話鍵盤使用者按不到——它是可操作的東西，就要能被 Tab 到。
					$uappt_grid_data  = sprintf(
						' role="button" tabindex="0" data-uappt-date="%1$s" data-uappt-state="%2$s" data-uappt-ranges="%3$s" data-uappt-note="%4$s"',
						esc_attr( $uappt_grid_date ),
						esc_attr( $uappt_grid_state ),
						esc_attr( wp_json_encode( array_values( (array) $uappt_grid_day['ranges'] ) ) ),
						esc_attr( $uappt_grid_day['override'] && isset( $uappt_grid_day['override']['note'] ) ? (string) $uappt_grid_day['override']['note'] : '' )
					);
					$uappt_grid_class .= ' is-editable';
				}

				// 塗抹模式：這一天「要存成什麼」。只有**當月、非過去**的日子能塗——
				// 月曆前後補滿整週的鄰月日子不屬於這次要排的範圍（跟舊的「全選本月」
				// 同一條規則），過去的日子改了也沒有意義。
				$uappt_grid_cell_paintable = ! empty( $grid_paintable )
					&& $uappt_grid_day['is_in_month']
					&& ! $uappt_grid_day['is_past'];
				if ( $uappt_grid_cell_paintable ) {
					$uappt_grid_class .= ' is-paintable';
				}
				?>
				<<?php echo esc_html( $uappt_grid_tag ); ?>
					class="<?php echo esc_attr( $uappt_grid_class ); ?>"
					<?php echo $uappt_grid_data; // phpcs:ignore WordPress.Security.EscapingOutput.OutputNotEscaped -- 上面每一段都已經 esc_attr()。 ?>
					<?php if ( 'a' === $uappt_grid_tag ) : ?>
						href="<?php echo esc_url( $uappt_grid_href ); ?>"
						aria-label="<?php echo esc_attr( $uappt_grid_label ); ?>"
						<?php echo ( '' !== $grid_selected_date && $uappt_grid_date === $grid_selected_date ) ? 'aria-current="date"' : ''; ?>
					<?php endif; ?>
				>
					<?php if ( $uappt_grid_cell_selectable ) : ?>
						<input
							type="checkbox"
							name="dates[]"
							value="<?php echo esc_attr( $uappt_grid_date ); ?>"
							class="uappt-cal-checkbox"
							data-weekday="<?php echo esc_attr( $uappt_grid_day['weekday'] ); ?>"
						/>
					<?php endif; ?>

					<?php if ( $uappt_grid_cell_paintable ) : ?>
						<?php
						// ⚠️ **欄位名稱由 PHP 印出來，JS 只改 value。** 讓 JS 動態組
						// `days[2027-04-06][type]` 這種字串的話，表單結構一改就會悄悄
						// 失效（v2.80.0 為了同一個理由，才把三個時段一律印進 DOM 而不是
						// 用 JS 組）。
						//
						// ⚠️ **一律 disabled。** disabled 的欄位不會被送出，所以它同時
						// 就是 dirty 旗標：JS 塗到哪一天才把那一天 enable。沒有 JS 時全部
						// 維持 disabled，按下儲存就是「沒有任何變更」——而不是把整個月
						// 原封不動重寫一次（那會把核准排班申請留下的 source／request_id
						// 洗成主管手動）。
						$uappt_grid_paint_type = $uappt_grid_closed
							? ( $uappt_grid_off ? UAPPT_Staff::CLOSED_OFF : UAPPT_Staff::CLOSED_LEAVE )
							: ( $uappt_grid_day['override'] ? 'hours' : 'clear' );
						?>
						<input type="hidden" disabled
							name="days[<?php echo esc_attr( $uappt_grid_date ); ?>][type]"
							value="<?php echo esc_attr( $uappt_grid_paint_type ); ?>"
							data-uappt-paint="type" />
						<input type="hidden" disabled
							name="days[<?php echo esc_attr( $uappt_grid_date ); ?>][ranges]"
							value="<?php echo esc_attr( wp_json_encode( array_values( (array) $uappt_grid_day['ranges'] ) ) ); ?>"
							data-uappt-paint="ranges" />
					<?php endif; ?>

					<span class="uappt-cal-day-num">
						<?php if ( $uappt_grid_is_link && ! $uappt_grid_whole_cell_link ) : ?>
							<?php // 整格已經是連結時不能再包一層 <a>（巢狀連結是無效的 HTML）。 ?>
							<a href="<?php echo esc_url( $uappt_grid_href ); ?>"><?php echo esc_html( (int) substr( $uappt_grid_date, 8, 2 ) ); ?></a>
						<?php else : ?>
							<?php echo esc_html( (int) substr( $uappt_grid_date, 8, 2 ) ); ?>
						<?php endif; ?>
						<?php
						// 「今天」小標（v2.97.0），只在後台排班月曆：那裡「今天」原本是藍框，
						// 跟選取的藍框撞在一起。前台員工中心的月曆有自己的今天樣式，不動。
						if ( $uappt_grid_day['is_today'] && ! empty( $grid_paintable ) ) :
							?>
							<span class="uappt-cal-today"><?php esc_html_e( '今天', 'ultimate-appointments' ); ?></span>
						<?php endif; ?>
					</span>

					<span class="uappt-cal-day-body">
						<?php if ( $uappt_grid_is_detail ) : ?>

							<?php if ( $uappt_grid_count > 0 ) : ?>
								<?php
								// **這一頁的主角是預約時間**，不是上班時間。桌機印前三筆，
								// 手機用 CSS 只留第一筆（46px 高的格子放不下更多），筆數
								// 徽章兩邊都印，所以「還有幾筆沒顯示」永遠說得出來。
								$uappt_grid_shown = array_slice( $uappt_grid_bookings, 0, 3 );
								?>
								<span class="uappt-cal-times">
									<?php foreach ( $uappt_grid_shown as $uappt_grid_i => $uappt_grid_booking ) : ?>
										<?php $uappt_grid_start = date_create( $uappt_grid_booking['service_start'], wp_timezone() ); ?>
										<span class="uappt-cal-time<?php echo 0 === $uappt_grid_i ? ' is-first' : ''; ?>">
											<?php echo esc_html( $uappt_grid_start ? $uappt_grid_start->format( 'H:i' ) : '' ); ?>
										</span>
									<?php endforeach; ?>
								</span>
								<span class="uappt-cal-count">
									<?php
									printf(
										/* translators: %d: 這天的預約／時段佔用筆數 */
										esc_html__( '%d 筆', 'ultimate-appointments' ),
										$uappt_grid_count
									);
									?>
								</span>
							<?php elseif ( $uappt_grid_shop_off ) : ?>
								<span class="uappt-badge uappt-badge-error" title="<?php echo esc_attr( $uappt_grid_shop ); ?>"><?php esc_html_e( '店休', 'ultimate-appointments' ); ?></span>
							<?php elseif ( $uappt_grid_closed ) : ?>
								<span class="uappt-badge uappt-badge-muted"><?php echo esc_html( $uappt_grid_off_label ); ?></span>
							<?php endif; ?>

						<?php else : ?>

							<?php if ( $uappt_grid_shop_off ) : ?>
								<?php // 店休排在最前面：這天不論人員怎麼排都不開放，先講結論。 ?>
								<span class="uappt-badge uappt-badge-error" title="<?php echo esc_attr( $uappt_grid_shop ); ?>"><?php esc_html_e( '店休', 'ultimate-appointments' ); ?></span>
							<?php elseif ( $uappt_grid_closed ) : ?>
								<span class="uappt-badge uappt-badge-muted"><?php echo esc_html( $uappt_grid_off_label ); ?></span>
							<?php elseif ( $uappt_grid_has_hours ) : ?>
								<?php foreach ( $uappt_grid_day['ranges'] as $uappt_grid_range ) : ?>
									<span class="uappt-cal-range"><?php echo esc_html( $uappt_grid_range[0] . '–' . $uappt_grid_range[1] ); ?></span>
								<?php endforeach; ?>
								<?php
								// 手機版的短標籤（v2.89.0）。桌機隱藏、手機顯示，跟
								// .uappt-cal-range 剛好相反。
								//
								// ⚠️ **手機版原本「只留日期數字」的理由已經不成立了。**
								// 當初的註解寫著「要看某天幾點上班，下面的清單本來就
								// 列著」——而那份清單現在排除了樣板列，v4 階段 4 還要
								// 整個拿掉。結果是手機上**完全看不出一天是早班還是
								// 晚班**，而這張月曆已經是排班的主畫面。
								//
								// 班別名（「早班」）兩個字就放得下；對不上任何班別時
								// 退回開始時間（「09:00」），那是五個字裡資訊量最高的
								// 一個——「幾點上班」。
								$uappt_grid_short = UAPPT_Shift_Preset::label_for_ranges( $uappt_grid_day['ranges'] );
								if ( '' === $uappt_grid_short && isset( $uappt_grid_day['ranges'][0][0] ) ) {
									$uappt_grid_short = $uappt_grid_day['ranges'][0][0];
								}
								?>
								<?php if ( '' !== $uappt_grid_short ) : ?>
									<span class="uappt-cal-short"><?php echo esc_html( $uappt_grid_short ); ?></span>
								<?php endif; ?>
							<?php else : ?>
								<span class="uappt-badge uappt-badge-muted"><?php esc_html_e( '未排班', 'ultimate-appointments' ); ?></span>
							<?php endif; ?>

							<?php
							// ⚠️ **只有「說得出資訊」的來源才印徽章。**
							//
							// 這個標記存在的意義是「這天為什麼跟平常不一樣」。v2.88.0
							// 以後每一天都有資料列了：樣板產生的是全部日子、而主管塗完
							// 一個月之後那整個月都是 manual——兩者照單全印的話，整張月曆
							// 每一格都掛著同一句話，等於沒有標記，還把真正有意義的
							// 「已核准調整」淹掉。
							//
							// 留下來的兩種都回答得出一個具體的問題：
							// - 已核准調整 → 這天是**員工申請**來的，不是你排的
							// - 批次匯入   → 這天是從檔案進來的，不是在這個畫面排的
							$uappt_grid_src    = $uappt_grid_day['override'] ? $uappt_grid_day['override']['source'] : '';
							$uappt_grid_src_tx = '';
							if ( 'shift_request' === $uappt_grid_src ) {
								$uappt_grid_src_tx = __( '已核准調整', 'ultimate-appointments' );
							} elseif ( 'import' === $uappt_grid_src ) {
								$uappt_grid_src_tx = __( '批次匯入', 'ultimate-appointments' );
							}
							?>
							<?php if ( '' !== $uappt_grid_src_tx ) : ?>
								<span class="uappt-cal-source"><?php echo esc_html( $uappt_grid_src_tx ); ?></span>
							<?php endif; ?>

							<?php if ( $uappt_grid_count > 0 ) : ?>
								<span class="uappt-cal-count">
									<?php
									printf(
										/* translators: %d: 這天的預約／時段佔用筆數 */
										esc_html__( '%d 筆', 'ultimate-appointments' ),
										$uappt_grid_count
									);
									?>
								</span>
							<?php endif; ?>

							<?php if ( ! empty( $uappt_grid_day['pending'] ) ) : ?>
								<span class="uappt-badge uappt-badge-warning"><?php esc_html_e( '待審核', 'ultimate-appointments' ); ?></span>
							<?php endif; ?>

						<?php endif; ?>

						<?php if ( isset( $grid_amounts[ $uappt_grid_date ] ) && $grid_amounts[ $uappt_grid_date ] > 0 ) : ?>
							<span class="uappt-cal-amount"><?php echo wp_kses_post( wc_price( $grid_amounts[ $uappt_grid_date ] ) ); ?></span>
						<?php endif; ?>
					</span>
				</<?php echo esc_html( $uappt_grid_tag ); ?>>
			<?php endforeach; ?>
		</div>
	<?php endforeach; ?>
</div>
