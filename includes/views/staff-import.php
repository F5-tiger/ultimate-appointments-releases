<?php
/**
 * View：人力資源批次匯入（人員主檔／逐日班表）。
 *
 * 兩段式：上半部是上傳與範本下載，下半部是上傳後的逐列預覽。預覽存在
 * transient 裡（見 UAPPT_Admin::render_staff_page()），沒有預覽時整個下半部
 * 不出現。
 *
 * 傳入變數：
 * - $preview    ['type' => 匯入類型, 'plans' => UAPPT_Import::plan() 的結果]，或 null
 * - $staff_list 目前的人員清單（給「人員編號」對照表用）
 *
 * @package Ultimate_Appointments
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


$uappt_op_labels = array(
	'create'  => array( __( '新增', 'ultimate-appointments' ), 'success' ),
	'update'  => array( __( '更新', 'ultimate-appointments' ), 'info' ),
	'delete'  => array( __( '刪除', 'ultimate-appointments' ), 'warning' ),
	'skip'    => array( __( '略過', 'ultimate-appointments' ), 'muted' ),
	'blocked' => array( __( '擋下', 'ultimate-appointments' ), 'error' ),
	'error'   => array( __( '錯誤', 'ultimate-appointments' ), 'error' ),
);

$uappt_counts = array();
if ( $preview ) {
	foreach ( $preview['plans'] as $uappt_plan ) {
		$uappt_counts[ $uappt_plan['op'] ] = isset( $uappt_counts[ $uappt_plan['op'] ] ) ? $uappt_counts[ $uappt_plan['op'] ] + 1 : 1;
	}
}
$uappt_applicable = ( isset( $uappt_counts['create'] ) ? $uappt_counts['create'] : 0 )
	+ ( isset( $uappt_counts['update'] ) ? $uappt_counts['update'] : 0 )
	+ ( isset( $uappt_counts['delete'] ) ? $uappt_counts['delete'] : 0 );
?>

	<p class="uappt-page-desc uappt-import-intro">
		<?php esc_html_e( '上傳 CSV 之後會先顯示逐列預覽，確認每一列會發生什麼事、按下「確認匯入」才會真的寫入。建議先下載目前的資料當範本，在 Excel 裡改完再傳回來——人員主檔要更新既有人員時必須帶正確的「編號」，否則會變成新增一位同名人員。', 'ultimate-appointments' ); ?>
	</p>

	<div class="uappt-import-panels">
		<?php
		$uappt_forms = array(
			UAPPT_Import::TYPE_STAFF     => array(
				'icon'  => 'users',
				'title' => __( '人員主檔', 'ultimate-appointments' ),
				'desc'  => __( '姓名、狀態、同時可服務人數、時間格顆粒、指定加價、排序、綁定帳號，以及每週固定班表。空白的儲存格代表「這一欄不動」，不會把既有資料清空；但只要檔案裡有任何一個星期欄，整週班表就以檔案為準（該天留空＝那天休息）。', 'ultimate-appointments' ),
			),
			UAPPT_Import::TYPE_OVERRIDES => array(
				'icon'  => 'calendar',
				'title' => __( '逐日班表／請假', 'ultimate-appointments' ),
				'desc'  => __( '針對單一日期覆蓋每週班表。類型填「例休」（排定不上班）、「請假」（本來要上班，臨時不上）、「自訂時段」（只上這幾段）或「清除」（拿掉這天的調整，回到每週預設）。例休與請假在班表上都是整天不開放，分開只是為了說得出原因、報表才數得出請假幾天。那天若已經有客人的預約會落在新時段之外，該列會被擋下不套用。', 'ultimate-appointments' ),
			),
		);

		foreach ( $uappt_forms as $uappt_type => $uappt_meta ) :
			?>
			<div class="uappt-panel uappt-import-panel">
				<?php UAPPT_Admin::panel_head( $uappt_meta['icon'], $uappt_meta['title'] ); ?>
				<div class="uappt-panel-body">
					<p class="description"><?php echo esc_html( $uappt_meta['desc'] ); ?></p>

					<p class="uappt-import-columns">
						<strong><?php esc_html_e( '欄位：', 'ultimate-appointments' ); ?></strong>
						<?php echo esc_html( implode( '、', UAPPT_Import::columns( $uappt_type ) ) ); ?>
					</p>

					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data" class="uappt-import-form">
						<input type="hidden" name="action" value="uappt_import_upload" />
						<input type="hidden" name="import_type" value="<?php echo esc_attr( $uappt_type ); ?>" />
						<?php wp_nonce_field( 'uappt_import_upload' ); ?>
						<input type="file" name="uappt_csv" accept=".csv,text/csv" required />
						<button type="submit" class="button button-primary"><?php esc_html_e( '上傳並預覽', 'ultimate-appointments' ); ?></button>
					</form>

					<p>
						<a
							class="button"
							href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'action' => 'uappt_import_template', 'import_type' => $uappt_type ), admin_url( 'admin-post.php' ) ), 'uappt_import_template' ) ); ?>"
						>
							<?php esc_html_e( '下載目前資料（當範本）', 'ultimate-appointments' ); ?>
						</a>
					</p>
				</div>
			</div>
		<?php endforeach; ?>
	</div>

	<?php if ( $preview ) : ?>
		<h2><?php esc_html_e( '匯入預覽', 'ultimate-appointments' ); ?></h2>
		<p class="description">
			<?php
			printf(
				/* translators: 1: 會套用的列數 2: 總列數 */
				esc_html__( '這個檔案共 %2$d 列，其中 %1$d 列會被套用。標示「錯誤」或「擋下」的列不會寫入，修正後重新上傳即可。', 'ultimate-appointments' ),
				(int) $uappt_applicable,
				count( $preview['plans'] )
			);
			?>
		</p>

		<table class="widefat striped uappt-table uappt-import-preview">
			<thead>
				<tr>
					<th class="uappt-import-col-line"><?php esc_html_e( 'CSV 行號', 'ultimate-appointments' ); ?></th>
					<th class="uappt-import-col-op"><?php esc_html_e( '動作', 'ultimate-appointments' ); ?></th>
					<th><?php esc_html_e( '對象', 'ultimate-appointments' ); ?></th>
					<th><?php esc_html_e( '說明', 'ultimate-appointments' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $preview['plans'] as $uappt_plan ) : ?>
					<?php list( $uappt_op_text, $uappt_op_tone ) = isset( $uappt_op_labels[ $uappt_plan['op'] ] ) ? $uappt_op_labels[ $uappt_plan['op'] ] : array( $uappt_plan['op'], 'muted' ); ?>
					<tr>
						<td data-label="<?php esc_attr_e( 'CSV 行號', 'ultimate-appointments' ); ?>"><?php echo esc_html( $uappt_plan['line'] ); ?></td>
						<td data-label="<?php esc_attr_e( '動作', 'ultimate-appointments' ); ?>">
							<span class="uappt-badge uappt-badge-<?php echo esc_attr( $uappt_op_tone ); ?>">
								<?php echo esc_html( $uappt_op_text ); ?>
							</span>
						</td>
						<td data-label="<?php esc_attr_e( '對象', 'ultimate-appointments' ); ?>"><?php echo esc_html( $uappt_plan['label'] ); ?></td>
						<td data-label="<?php esc_attr_e( '說明', 'ultimate-appointments' ); ?>"><?php echo esc_html( $uappt_plan['message'] ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="uappt-import-apply">
			<input type="hidden" name="action" value="uappt_import_apply" />
			<?php wp_nonce_field( 'uappt_import_apply' ); ?>
			<button
				type="submit"
				class="button button-primary"
				<?php disabled( 0 === $uappt_applicable ); ?>
				onclick="return confirm('<?php echo esc_js( sprintf( /* translators: %d: 會套用的列數 */ __( '確定要套用這 %d 列嗎？', 'ultimate-appointments' ), $uappt_applicable ) ); ?>');"
			>
				<?php esc_html_e( '確認匯入', 'ultimate-appointments' ); ?>
			</button>
			<span class="description"><?php esc_html_e( '預覽保留一小時；超過時間請重新上傳。', 'ultimate-appointments' ); ?></span>
		</form>
	<?php endif; ?>

	<?php if ( $staff_list ) : ?>
		<h2><?php esc_html_e( '人員編號對照', 'ultimate-appointments' ); ?></h2>
		<p class="description"><?php esc_html_e( '逐日班表的「人員編號」填這裡的數字，不是姓名——姓名可能重複，對錯人會把班表寫到別人身上。', 'ultimate-appointments' ); ?></p>
		<p class="uappt-import-staff-map">
			<?php foreach ( $staff_list as $uappt_staff_row ) : ?>
				<span class="uappt-import-staff-chip">
					<strong>#<?php echo esc_html( $uappt_staff_row['id'] ); ?></strong>
					<?php echo esc_html( $uappt_staff_row['name'] ); ?>
					<?php if ( 'inactive' === $uappt_staff_row['status'] ) : ?>
						<span class="uappt-badge uappt-badge-muted"><?php esc_html_e( '停用', 'ultimate-appointments' ); ?></span>
					<?php endif; ?>
				</span>
			<?php endforeach; ?>
		</p>
	<?php endif; ?>
