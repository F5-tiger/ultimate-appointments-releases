<?php
/**
 * View：報表頁（總覽／營收／人員／耗材）。
 *
 * ⚠️ **頁籤跟設定頁的頁籤不是同一種東西**：設定頁把所有欄位都留在 DOM 裡、
 * 只用 CSS 藏（因為儲存時會無條件寫入每一個 option）。這裡每一頁各自查詢、
 * 各自匯出，用真正的分頁——跟耗材管理頁同一個模式。
 *
 * **畫面上的數字與 CSV 匯出的數字都來自同一份 `$report['rows']`**
 * （`UAPPT_Admin::build_report()`），兩邊只是呈現方式不同。要加欄位就加在
 * builder 的 `columns`／`fields` 裡，不要在這裡另外算一個數字出來。
 *
 * 傳入變數：
 * - $date_from / $date_to    目前查詢的期間 (Y-m-d)
 * - $staff_id                篩選的人員 ID；0 代表全部
 * - $staff_list              全部人員（含停用），供篩選下拉使用
 * - $tabs / $current_tab     頁籤
 * - $presets / $preset       期間快捷鍵
 * - $unit                    「總覽」頁籤的時間顆粒（day／week／month）
 * - $view                    「收入結構」看的是哪一張表（''／'payments'）
 * - $report                  ['rows','columns','fields','totals','extra']
 * - $staff_services          人員 × 項目（只有人員頁有內容）
 * - $staff_commission        人員抽成試算（只有人員頁有內容）
 * - $staff_no_charge         未收費／招待明細（只有人員頁有內容）
 * - $compare / $previous     期間比較（['from','to','totals']）
 * - $orderby / $order        人員總表的排序狀態
 * - $period_days             期間天數（含頭尾）
 * - $export_url              匯出目前這一份的 CSV
 * - $export_url_all          一鍵匯出所有頁籤（zip，或退回單一 CSV）
 * - $export_url_service      匯出人員 × 項目的 CSV（只有人員頁用得到）
 *
 * @package Ultimate_Appointments
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$uappt_base_args = array(
	'page'    => UAPPT_Admin::PAGE_SLUG,
	'section' => 'reports',
	'date_from' => $date_from,
	'date_to'   => $date_to,
	'staff_id'  => $staff_id ? $staff_id : '',
	'compare'   => $compare ? '' : '0',
);
$uappt_base_url  = add_query_arg( $uappt_base_args, admin_url( 'admin.php' ) );

// 頁尾的名詞解釋收在一個可收合的區塊裡，不再是一疊琥珀色提示框。
//
// ⚠️ 這幾段**不是可有可無的裝飾**：「業績包含未到」「不重複客人怎麼去重」
// 這種定義，正是使用者拿我們的數字跟自己手上的帳對不起來時唯一的解答。所以
// 是收合、不是刪除，而且標題要講清楚裡面是什麼。
//
// 收合改用 <details>／<summary>（篩選器那邊用的是 checkbox ＋ label）：那邊
// 需要「桌面永遠展開、手機預設收合」兩種預設值，而 <details> 一旦是關的就
// 沒辦法只靠 CSS 打開；這裡兩個斷點的預設狀態一樣，就沒有那個限制，用語意
// 正確、免 JS、鍵盤與螢幕報讀器天生就會的原生元素即可。
$uappt_notes = array();

// ⚠️ 上方卡片與損益階梯讀的是「這段期間的服務統計」，不一定等於主表格的
// 合計：「收入結構 ▸ 依零售商品」那張表的合計是**商品銷售額**（而且時間軸
// 是訂單日期，不是服務日期）。builder 有給 period_totals 就以它為準。
$uappt_totals = isset( $report['extra']['period_totals'] ) ? $report['extra']['period_totals'] : $report['totals'];
$uappt_prev   = isset( $previous['totals'] ) ? $previous['totals'] : array();

/**
 * 百分比：報表上所有比率統一用這個印，免得有的地方一位小數、有的地方兩位。
 *
 * @param float $ratio 0–1 的比率。
 * @return string
 */
$uappt_pct = function ( $ratio ) {
	return round( (float) $ratio * 100, 1 ) . '%';
};

/**
 * 與上一期的變化。
 *
 * 上一期是 0 的時候**不印百分比**——從 0 成長到任何數字都是「∞%」，那個數字
 * 沒有意義而且會嚇到人；改成只標一個「新增」。
 *
 * @param string $field 欄位名稱。
 * @param bool   $lower_is_better 這個指標是越低越好嗎（未到率、取消率）。
 * @return string 已跳脫的 HTML；沒有比較資料時回傳空字串。
 */
$uappt_delta = function ( $field, $lower_is_better = false ) use ( $compare, $uappt_totals, $uappt_prev ) {
	if ( ! $compare || ! isset( $uappt_prev[ $field ] ) || ! isset( $uappt_totals[ $field ] ) ) {
		return '';
	}

	$now  = (float) $uappt_totals[ $field ];
	$then = (float) $uappt_prev[ $field ];

	// 上一期是 0 的時候不印百分比——從 0 成長到任何數字都是「∞%」，那個數字沒有
	// 意義而且會嚇到人。但**顏色還是要分好壞**：業績從 0 變 100 是好事，未到從
	// 0 變 5 是壞事，兩者不能都印成綠色。
	if ( 0.0 === $then ) {
		if ( $now <= 0 ) {
			return '';
		}
		return sprintf(
			'<span class="uappt-delta %s">%s</span>',
			esc_attr( $lower_is_better ? 'is-down' : 'is-up' ),
			esc_html__( '新增', 'ultimate-appointments' )
		);
	}

	$change = ( $now - $then ) / abs( $then );
	if ( abs( $change ) < 0.001 ) {
		return '<span class="uappt-delta is-flat">' . esc_html__( '持平', 'ultimate-appointments' ) . '</span>';
	}

	// 顏色代表「好／壞」不是「漲／跌」：未到率下降是好事，所以要用 is-up（綠）。
	$up    = $change > 0;
	$good  = $lower_is_better ? ! $up : $up;
	$arrow = $up ? '▲' : '▼';

	return sprintf(
		'<span class="uappt-delta %s">%s %s</span>',
		esc_attr( $good ? 'is-up' : 'is-down' ),
		esc_html( $arrow ),
		esc_html( round( abs( $change ) * 100, 1 ) . '%' )
	);
};
?>

	<?php UAPPT_Admin::render_tabs( $tabs, $current_tab, $uappt_base_url ); ?>


	<?php // 快捷鍵是連結不是表單欄位：按一下就換期間，不用再按「套用」。 ?>
	<p class="uappt-period-presets">
		<?php foreach ( $presets as $uappt_preset_key => $uappt_preset_label ) : ?>
			<a
				href="<?php echo esc_url( add_query_arg( array( 'tab' => $current_tab, 'unit' => $unit, 'view' => $view, 'preset' => $uappt_preset_key ), $uappt_base_url ) ); ?>"
				class="button<?php echo $preset === $uappt_preset_key ? ' button-primary' : ''; ?>"
			><?php echo esc_html( $uappt_preset_label ); ?></a>
		<?php endforeach; ?>
	</p>

	<?php
	// 期間一律算一項生效中的條件：報表本來就永遠帶著一段期間（上面那排快捷鍵
	// 會填進來），所以手機版的這一塊預設是展開的——把日期藏起來會讓人看不出
	// 現在的數字是哪一段期間的。
	$uappt_active = 1;
	if ( $staff_id ) {
		$uappt_active++;
	}
	if ( $compare ) {
		$uappt_active++;
	}

	UAPPT_Admin::filters_open(
		array(
			'section' => 'reports',
			'hidden'  => array(
				'tab'  => $current_tab,
				'unit' => $unit,
				// 收入結構正在看的是哪一張表也要帶著走：在「依收款方式」改了
				// 日期按套用，卻被丟回「依服務項目」，會讓人以為按錯了。
				'view' => $view,
			),
			'active'  => $uappt_active,
		)
	);
	?>

		<?php UAPPT_Admin::field_open( __( '統計區間', 'ultimate-appointments' ), '', 'uappt-field-range' ); ?>
			<span class="uappt-field-row">
				<input type="date" name="date_from" value="<?php echo esc_attr( $date_from ); ?>" aria-label="<?php esc_attr_e( '統計區間（起）', 'ultimate-appointments' ); ?>" />
				<span class="uappt-field-sep" aria-hidden="true">～</span>
				<input type="date" name="date_to" value="<?php echo esc_attr( $date_to ); ?>" aria-label="<?php esc_attr_e( '統計區間（迄）', 'ultimate-appointments' ); ?>" />
			</span>
		<?php UAPPT_Admin::field_close(); ?>

		<?php // 「耗材」頁籤問的是「店裡的東西夠不夠用」，庫存是整間店共用的， ?>
		<?php // 套人員篩選沒有意義，所以那一頁不顯示這個下拉。 ?>
		<?php if ( 'consumables' !== $current_tab ) : ?>
			<?php UAPPT_Admin::field_open( __( '人員', 'ultimate-appointments' ), 'uappt-filter-staff' ); ?>
				<select id="uappt-filter-staff" name="staff_id">
					<option value=""><?php esc_html_e( '所有人員', 'ultimate-appointments' ); ?></option>
					<?php foreach ( $staff_list as $uappt_staff ) : ?>
						<option value="<?php echo esc_attr( $uappt_staff['id'] ); ?>" <?php selected( $staff_id, (int) $uappt_staff['id'] ); ?>>
							<?php echo esc_html( $uappt_staff['name'] ); ?><?php echo 'active' !== $uappt_staff['status'] ? esc_html__( '（已停用）', 'ultimate-appointments' ) : ''; ?>
						</option>
					<?php endforeach; ?>
				</select>
			<?php UAPPT_Admin::field_close(); ?>

			<?php UAPPT_Admin::field_open( '', '', 'uappt-field-check' ); ?>
				<label class="uappt-compare-toggle">
					<?php // 沒勾時要送出 compare=0，不然「取消勾選」在 GET 表單裡等於沒送、 ?>
					<?php // 後端看不出使用者是想關掉還是第一次進來。 ?>
					<input type="hidden" name="compare" value="0" />
					<input type="checkbox" name="compare" value="1" <?php checked( $compare ); ?> />
					<?php esc_html_e( '與上期比較', 'ultimate-appointments' ); ?>
				</label>
			<?php UAPPT_Admin::field_close(); ?>
		<?php endif; ?>

		<?php UAPPT_Admin::field_open( '', '', 'uappt-field-actions' ); ?>
			<?php submit_button( __( '套用', 'ultimate-appointments' ), 'secondary', '', false ); ?>
			<a href="<?php echo esc_url( $export_url ); ?>" class="button"><?php esc_html_e( '匯出這一份', 'ultimate-appointments' ); ?></a>
			<?php
			// 「匯出全部」的說明寫在 title 上而不是旁邊多一行字：篩選列已經很滿，
			// 而這顆按鈕一個月大概只按一次（月結），不需要常駐的說明。
			$uappt_export_all_title = class_exists( 'ZipArchive' )
				? __( '把這段期間所有頁籤的報表打包成一個 zip（一份報表一個 CSV）', 'ultimate-appointments' )
				: __( '把這段期間所有頁籤的報表匯成一個 CSV（各段之間空一行分隔）。這台主機沒有 zip 功能，所以不是壓縮檔。', 'ultimate-appointments' );
			?>
			<a href="<?php echo esc_url( $export_url_all ); ?>" class="button" title="<?php echo esc_attr( $uappt_export_all_title ); ?>">
				<?php esc_html_e( '匯出全部', 'ultimate-appointments' ); ?>
			</a>
			<?php if ( 'staff' === $current_tab ) : ?>
				<a href="<?php echo esc_url( $export_url_service ); ?>" class="button"><?php esc_html_e( '匯出人員 × 項目', 'ultimate-appointments' ); ?></a>
			<?php endif; ?>
		<?php UAPPT_Admin::field_close(); ?>

	<?php UAPPT_Admin::filters_close(); ?>

	<p class="description">
		<?php
		printf(
			/* translators: 1: 起始日期 2: 結束日期 3: 天數 */
			esc_html__( '統計區間：%1$s ～ %2$s（共 %3$d 天）。只計入服務時間已經過去的預約。', 'ultimate-appointments' ),
			esc_html( $date_from ),
			esc_html( $date_to ),
			(int) $period_days
		);
		?>
		<?php if ( $compare && 'consumables' !== $current_tab ) : ?>
			<?php
			// ⚠️ 一定要印出實際比較的是哪一段。上一期是「同樣天數、緊鄰在前」，
			// 不是「上個月」——不寫出來的話，使用者會以為在跟上個月比，數字對不上
			// 就不信任整份報表了。
			printf(
				/* translators: 1: 上期起始日期 2: 上期結束日期 */
				esc_html__( '箭頭是與 %1$s ～ %2$s（同樣天數、緊鄰在前）相比。', 'ultimate-appointments' ),
				esc_html( $previous['from'] ),
				esc_html( $previous['to'] )
			);
			?>
		<?php endif; ?>
	</p>

	<?php if ( 'consumables' !== $current_tab ) : ?>

		<?php
		// 損益階梯。
		//
		// 舊版是七張權重一樣的卡片平鋪，看不出它們之間的關係——業績、材料成本、
		// 手續費、毛利其實是**同一條算式的四個階段**，會計的損益表就是這樣排的：
		// 每一行都從上一行推導下來。平鋪成卡片之後，「錢漏在哪」這個問題要在
		// 心裡自己重新組裝一次。
		//
		// 兩項扣除都沒有的店（沒開耗材、也沒設費率）不畫階梯——那會變成
		// 「營業收入 319,870 ＝ 淨毛利 319,870」，一條沒有內容的算式。
		// ⚠️ 階梯**只在「總覽」出現**。它是「這段期間賺了多少」的完整答案，
		// 而那正是總覽這個頁籤的工作。放在每一個頁籤上，等於每一頁都先推
		// 300px 的同一塊內容，才輪到那一頁自己要講的事——使用者點過去是想看
		// 人員、看客人，不是再看一次同樣的損益。其他頁籤留那排小卡片就夠了，
		// 它們只是「現在看的是哪一段期間」的提示。
		$uappt_has_cost = UAPPT_Modules::enabled( 'consumables' );
		$uappt_has_fee  = UAPPT_Payment::has_fees();
		$uappt_show_pl  = 'overview' === $current_tab && ( $uappt_has_cost || $uappt_has_fee );

		/**
		 * 印一列損益。
		 *
		 * @param string $label 名目。
		 * @param float  $value 金額。
		 * @param float  $base  分母（營業收入），用來算佔比。
		 * @param string $type  'income'／'deduct'／'result'。
		 * @param string $delta 已跳脫的漲跌 HTML。
		 */
		$uappt_pl_row = function ( $label, $value, $base, $type, $delta = '' ) {
			// number_format 而不是 round：round() 會把 1.0 印成「1」，那一欄就
			// 對不齊了（旁邊是 5.4%、93.6%）。
			$pct = $base > 0 ? number_format( abs( $value ) / $base * 100, 1 ) . '%' : '—';
			printf(
				'<div class="uappt-pl-row is-%1$s"><span class="uappt-pl-label">%2$s</span>'
				. '<span class="uappt-pl-value">%3$s%4$s</span>'
				. '<span class="uappt-pl-pct">%5$s</span>'
				. '<span class="uappt-pl-delta">%6$s</span></div>',
				esc_attr( $type ),
				esc_html( $label ),
				'deduct' === $type ? '−' : '',
				wp_kses_post( wc_price( abs( $value ) ) ),
				esc_html( $pct ),
				wp_kses_post( $delta )
			);
		};
		?>

		<?php if ( $uappt_show_pl ) : ?>
			<div class="uappt-panel uappt-pl-panel">
				<?php UAPPT_Admin::panel_head( 'wallet', __( '這段期間賺了多少', 'ultimate-appointments' ) ); ?>
				<div class="uappt-panel-body">
					<div class="uappt-pl">
						<?php $uappt_pl_row( __( '營業收入', 'ultimate-appointments' ), $uappt_totals['revenue'], $uappt_totals['revenue'], 'income', $uappt_delta( 'revenue' ) ); ?>
						<?php if ( $uappt_has_cost ) : ?>
							<?php $uappt_pl_row( __( '材料成本', 'ultimate-appointments' ), $uappt_totals['material_cost'], $uappt_totals['revenue'], 'deduct' ); ?>
						<?php endif; ?>
						<?php if ( $uappt_has_fee ) : ?>
							<?php // 手續費是越低越好，下降才是綠色。 ?>
							<?php $uappt_pl_row( __( '金流手續費', 'ultimate-appointments' ), $uappt_totals['fee'], $uappt_totals['revenue'], 'deduct', $uappt_delta( 'fee', true ) ); ?>
						<?php endif; ?>
						<?php $uappt_pl_row( __( '淨毛利', 'ultimate-appointments' ), $uappt_totals['net_profit'], $uappt_totals['revenue'], 'result' ); ?>
					</div>
					<p class="description">
						<?php esc_html_e( '右邊的百分比是佔營業收入的比例。「淨毛利」還沒有扣人事、房租這類固定費用——那些不隨單筆服務變動，不在這個外掛的記錄範圍內。', 'ultimate-appointments' ); ?>
					</p>
				</div>
			</div>
		<?php endif; ?>

		<div class="uappt-dash-cards">
			<?php // 沒有階梯時，業績仍然要有一個落腳的地方。 ?>
			<?php if ( ! $uappt_show_pl ) : ?>
				<div class="uappt-dash-card is-static">
					<span class="uappt-dash-card-value"><?php echo wp_kses_post( wc_price( $uappt_totals['revenue'] ) ); ?></span>
					<span class="uappt-dash-card-label"><?php esc_html_e( '業績', 'ultimate-appointments' ); ?> <?php echo wp_kses_post( $uappt_delta( 'revenue' ) ); ?></span>
				</div>
			<?php endif; ?>
			<div class="uappt-dash-card is-static">
				<span class="uappt-dash-card-value"><?php echo esc_html( $uappt_totals['op_count'] ); ?></span>
				<span class="uappt-dash-card-label"><?php esc_html_e( '操作筆數', 'ultimate-appointments' ); ?> <?php echo wp_kses_post( $uappt_delta( 'op_count' ) ); ?></span>
			</div>
			<div class="uappt-dash-card is-static">
				<span class="uappt-dash-card-value"><?php echo esc_html( $uappt_totals['customer_count'] ); ?></span>
				<span class="uappt-dash-card-label"><?php esc_html_e( '不重複客人', 'ultimate-appointments' ); ?> <?php echo wp_kses_post( $uappt_delta( 'customer_count' ) ); ?></span>
				<?php if ( isset( $report['extra']['customers'] ) ) : ?>
					<span class="uappt-dash-card-sub">
						<?php
						printf(
							/* translators: 1: 新客數 2: 回頭客數 */
							esc_html__( '新客 %1$d ／ 回頭 %2$d', 'ultimate-appointments' ),
							(int) $report['extra']['customers']['new'],
							(int) $report['extra']['customers']['returning']
						);
						?>
					</span>
				<?php endif; ?>
			</div>
			<div class="uappt-dash-card is-static">
				<span class="uappt-dash-card-value"><?php echo wp_kses_post( wc_price( $uappt_totals['avg_price'] ) ); ?></span>
				<span class="uappt-dash-card-label"><?php esc_html_e( '平均客單價', 'ultimate-appointments' ); ?> <?php echo wp_kses_post( $uappt_delta( 'avg_price' ) ); ?></span>
			</div>
			<div class="uappt-dash-card is-static <?php echo $uappt_totals['no_show_count'] > 0 ? 'is-warning' : ''; ?>">
				<span class="uappt-dash-card-value"><?php echo esc_html( $uappt_totals['no_show_count'] ); ?></span>
				<span class="uappt-dash-card-label">
					<?php esc_html_e( '未到', 'ultimate-appointments' ); ?>
					<?php // 未到是越低越好，所以下降才是綠色。 ?>
					<?php echo wp_kses_post( $uappt_delta( 'no_show_count', true ) ); ?>
				</span>
				<span class="uappt-dash-card-sub">
					<?php
					printf(
						/* translators: %s: 未到率百分比 */
						esc_html__( '佔 %s', 'ultimate-appointments' ),
						esc_html( $uappt_pct( $uappt_totals['no_show_rate'] ) )
					);
					?>
				</span>
			</div>
			<?php
			// 這一排刻意**不放時段利用率**：它只在「總覽」與「人員」有意義
			// （見 report-overview.php 的客人組成），放進共用的卡片列等於在
			// 「收款」那種頁籤上也塞一個跟該頁無關的指標，而且會跟總覽底下
			// 那張同名同值的卡片重複。
			?>
		</div>

		<?php
		// 往前看的那一段。刻意排在統計卡片**之後**、而且長得不一樣：上面每個
		// 數字講的都是「已經發生的事」，這一條講的是「從現在往後」，兩者混在
		// 同一排會讓人以為它也在統計區間裡。
		// 同理只在總覽：它是「賺了多少」之後的下一個問題（那接下來呢），
		// 跟人員頁、耗材頁要回答的事情無關。
		$uappt_ahead = 'overview' === $current_tab ? UAPPT_Booking::get_scheduled_ahead( 30 ) : array( 'count' => 0 );
		?>
		<?php if ( $uappt_ahead['count'] > 0 ) : ?>
			<p class="uappt-ahead">
				<span class="uappt-ahead-icon" aria-hidden="true"><?php echo UAPPT_Admin::icon( 'calendar' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
				<?php
				printf(
					/* translators: 1: 筆數 2: 金額 3: 日期 */
					esc_html__( '接下來 30 天已經排了 %1$d 筆，金額約 %2$s（排到 %3$s）。這一段還沒發生，不計入上面的統計。', 'ultimate-appointments' ),
					(int) $uappt_ahead['count'],
					wp_strip_all_tags( wc_price( $uappt_ahead['revenue'] ) ),
					esc_html( $uappt_ahead['until'] )
				);
				?>
			</p>
		<?php endif; ?>
	<?php endif; ?>

	<?php if ( 'overview' === $current_tab ) : ?>

		<?php // 顆粒切換也是連結，跟期間快捷鍵同一個道理：按一下就換，不用再套用。 ?>
		<p class="uappt-period-presets">
			<span class="uappt-period-presets-label"><?php esc_html_e( '顯示顆粒', 'ultimate-appointments' ); ?></span>
			<?php foreach ( UAPPT_Admin::report_units() as $uappt_unit_key => $uappt_unit_label ) : ?>
				<a
					href="<?php echo esc_url( add_query_arg( array( 'tab' => 'overview', 'preset' => $preset, 'unit' => $uappt_unit_key ), $uappt_base_url ) ); ?>"
					class="button<?php echo $unit === $uappt_unit_key ? ' button-primary' : ''; ?>"
				><?php echo esc_html( $uappt_unit_label ); ?></a>
			<?php endforeach; ?>
		</p>

		<?php require UAPPT_PLUGIN_DIR . 'includes/views/partials/report-table.php'; ?>

		<?php require UAPPT_PLUGIN_DIR . 'includes/views/partials/report-overview.php'; ?>

	<?php elseif ( 'staff' === $current_tab ) : ?>

		<?php
		// 業績長條：人員一多時，一片數字看不出「業績是集中在一兩個人還是平均」。
		// 重用階段 4 做的分布長條 partial，不另外寫一份。
		$uappt_bar_items  = array();
		$uappt_bar_counts = array();
		foreach ( $report['rows'] as $uappt_lb_row ) {
			if ( $uappt_lb_row['revenue'] <= 0 ) {
				continue;
			}
			$uappt_bar_items[ $uappt_lb_row['staff_id'] ]  = $uappt_lb_row['staff_name'];
			$uappt_bar_counts[ $uappt_lb_row['staff_id'] ] = $uappt_lb_row['revenue'];
		}
		$uappt_bar_money = true;
		?>
		<?php if ( count( $uappt_bar_items ) > 1 ) : ?>
			<div class="uappt-panel">
				<?php UAPPT_Admin::panel_head( 'pie-chart', __( '業績分布', 'ultimate-appointments' ) ); ?>
				<div class="uappt-panel-body">
					<?php require UAPPT_PLUGIN_DIR . 'includes/views/partials/report-bars.php'; ?>
					<p class="description">
						<?php esc_html_e( '長條的順序跟下面的表格一致（點表格標題可以換排序）。集中在一兩位人員身上時，要留意他請假或離職的風險；過度平均則可能表示客人沒有指定的習慣。', 'ultimate-appointments' ); ?>
					</p>
				</div>
			</div>
			<?php $uappt_bar_money = false; ?>
		<?php endif; ?>

		<?php
		// 表頭變成排序連結。base 網址要保留目前的頁籤與期間，但**不能帶
		// orderby／order**——那兩個由 add_query_arg() 在 partial 裡加上去。
		$uappt_sort_url = add_query_arg( array( 'tab' => $current_tab, 'preset' => $preset ), $uappt_base_url );
		$uappt_orderby  = $orderby;
		$uappt_order    = $order;
		require UAPPT_PLUGIN_DIR . 'includes/views/partials/report-table.php';
		?>
		<?php $uappt_notes[] = __( '人員總表點欄位標題可以換排序（預設依業績由高到低）。淡橘色的格子代表那個數字明顯偏離全店平均，滑鼠移上去看基準——只有筆數夠多的人員才會標，一兩筆的比率沒有參考價值。', 'ultimate-appointments' ); ?>

		<?php require UAPPT_PLUGIN_DIR . 'includes/views/partials/report-staff-services.php'; ?>

		<?php require UAPPT_PLUGIN_DIR . 'includes/views/partials/report-no-charge.php'; ?>

		<?php require UAPPT_PLUGIN_DIR . 'includes/views/partials/report-commission.php'; ?>

	<?php elseif ( 'clients' === $current_tab ) : ?>

		<?php require UAPPT_PLUGIN_DIR . 'includes/views/partials/report-clients.php'; ?>

	<?php elseif ( 'appointments' === $current_tab ) : ?>

		<?php require UAPPT_PLUGIN_DIR . 'includes/views/partials/report-appointments.php'; ?>

	<?php elseif ( 'income' === $current_tab ) : ?>

		<?php require UAPPT_PLUGIN_DIR . 'includes/views/partials/report-income.php'; ?>

		<?php
		$uappt_notes[] = __( '「平均費率」是實際算出來的手續費 ÷ 業績，不是設定值——每筆預約記的是成立當下的費率，之後調整費率不會回頭改動已經結束的月份，所以同一種收款方式底下可能混著幾種費率。', 'ultimate-appointments' );
		$uappt_notes[] = __( '手續費是「金額 × 費率」算出來的，不是金流公司實際請款的金額。分期與一般刷卡的費率通常差很多，要讓數字對得上對帳單，請在設定裡把它們建成各自的收款方式。機台月租這類固定費用不隨金額變動，不在這裡計算。', 'ultimate-appointments' );
		$uappt_notes[] = __( '「新客」是這位客人在本站的第一筆預約就落在這段期間內。篩選了特定人員時，「第一次」仍然以整間店為準——不然老客人換一位服務人員就會被算成新客，回頭率會完全失真。', 'ultimate-appointments' );
		?>

	<?php else : ?>

		<?php require UAPPT_PLUGIN_DIR . 'includes/views/partials/report-consumables.php'; ?>

	<?php endif; ?>

	<?php
	// 全頁籤共用的定義，排在各頁籤自己補的那幾段後面——先看跟眼前這張表
	// 有關的，再看通則。
	$uappt_notes[] = __( '「業績」納入已確認／已完成／未到——不管客人有沒有出現，錢通常都已經收了；「服務筆數」與「時段利用率」則不含未到，那個時段雖然被佔用，但沒有真的提供服務。「操作筆數」是已經有最終結果的筆數（服務 + 未到），也是平均客單價與未到率的分母。', 'ultimate-appointments' );
	$uappt_notes[] = __( '「不重複客人」的去重規則：有會員帳號用帳號，沒有就用電話（去掉符號與空白），兩者都沒有的純現場客人則每筆各算一位——同名同姓不敢合併，寧可高估也不要把兩位客人算成同一個人。「材料成本」來自耗材管理的扣用紀錄，沒有設定配方的服務不會有成本。', 'ultimate-appointments' );
	$uappt_notes[] = sprintf(
		/* translators: %d: 最長天數 */
		__( '「業績」是預約成立當下的金額快照，訂單成立後會用訂單項目的實際金額覆蓋；退款不會回頭更新這個數字（退款會把訂單轉為已退款、預約釋放為已取消，因此本來就不計入）。報表期間最長 %d 天，超過會自動把起始日往後收；開啟「與上期比較」時查詢量會加倍。', 'ultimate-appointments' ),
		(int) UAPPT_Admin::REPORT_MAX_DAYS
	);
	?>

	<details class="uappt-notes">
		<summary>
			<?php esc_html_e( '這些數字是怎麼算的', 'ultimate-appointments' ); ?>
			<?php // 條數放在標題上：看得出裡面有東西，也看得出有多少。 ?>
			<span class="uappt-notes-count"><?php echo esc_html( count( $uappt_notes ) ); ?></span>
		</summary>
		<div class="uappt-notes-body">
			<?php foreach ( $uappt_notes as $uappt_note ) : ?>
				<p><?php echo esc_html( $uappt_note ); ?></p>
			<?php endforeach; ?>
		</div>
	</details>
