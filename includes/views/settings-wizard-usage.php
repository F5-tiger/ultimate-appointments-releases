<?php
/**
 * View：設定頁的「前台預約介面」區塊（短代碼用法對照）。
 *
 * 純參考資料，沒有任何可儲存的欄位——所以刻意放在設定表單裡但不含 input，
 * 按「儲存設定」不會受影響。
 *
 * 為什麼需要這一塊：短代碼與它的參數原本只存在於程式碼裡，管理者要用的時候
 * 無從查起，人員／服務的 ID 更是只能自己去列表頁一個一個對。這裡把「可以直接
 * 複製貼上的字串」直接產生出來。
 *
 * 由 includes/views/settings.php require 進來。
 *
 * @package Ultimate_Appointments
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'uappt_copy_field' ) ) {
	/**
	 * 一個唯讀輸入框 ＋ 複製按鈕。
	 *
	 * 用 readonly input 而不是 <code>：短代碼很容易在選取時漏掉頭尾的方括號，
	 * 輸入框可以一次選取全部，而且手機上也點得到複製。
	 *
	 * 定義在 view 裡（而不是 class）是因為只有這一頁用得到；掛
	 * function_exists() 是防止這支 view 被重複 require。
	 *
	 * @param string $value   要複製的內容。
	 * @param bool   $compact 表格儲存格裡用的窄版。
	 */
	function uappt_copy_field( $value, $compact = false ) {
		printf(
			'<span class="uappt-copy%1$s"><input type="text" readonly value="%2$s" /><button type="button" class="button uappt-copy-btn" data-copied="%3$s">%4$s</button></span>',
			$compact ? ' is-compact' : '',
			esc_attr( $value ),
			esc_attr__( '已複製', 'ultimate-appointments' ),
			esc_html__( '複製', 'ultimate-appointments' )
		);
	}
}

$uappt_pages    = UAPPT_Wizard::find_pages();
$uappt_staff    = UAPPT_Staff::get_all( true );
$uappt_services = array();

foreach ( UAPPT_Service_Index::get() as $uappt_entries ) {
	foreach ( $uappt_entries as $uappt_entry ) {
		$uappt_k = $uappt_entry['product_id'] . '|' . $uappt_entry['plan_key'];
		if ( isset( $uappt_services[ $uappt_k ] ) ) {
			continue;
		}
		$uappt_payload = UAPPT_Availability_Query::service_payload( $uappt_entry['product_id'], $uappt_entry['plan_key'] );
		if ( null !== $uappt_payload ) {
			$uappt_services[ $uappt_k ] = $uappt_payload;
		}
	}
}

// 專屬連結的範例網址：優先用已經偵測到的預約頁，沒有就先用首頁當佔位。
$uappt_example_url = ! empty( $uappt_pages ) ? $uappt_pages[0]['url'] : home_url( '/預約/' );
?>
<div class="uappt-panel">
	<?php UAPPT_Admin::panel_head( 'clipboard-list', __( '前台預約介面（短代碼）', 'ultimate-appointments' ) ); ?>
	<div class="uappt-panel-body">

		<p class="description">
			<?php esc_html_e( '把預約精靈放到任何一頁：貼上短代碼，或在 Elementor 的「終極預約」分類裡拖入「預約精靈」元件。兩種放法功能完全一樣。', 'ultimate-appointments' ); ?>
		</p>

		<h3><?php esc_html_e( '基本用法', 'ultimate-appointments' ); ?></h3>
		<?php uappt_copy_field( '[uappt_booking]' ); ?>

		<h3><?php esc_html_e( '可用參數', 'ultimate-appointments' ); ?></h3>
		<table class="widefat striped uappt-shortcode-table">
			<thead>
				<tr>
					<th><?php esc_html_e( '參數', 'ultimate-appointments' ); ?></th>
					<th><?php esc_html_e( '說明', 'ultimate-appointments' ); ?></th>
					<th><?php esc_html_e( '範例', 'ultimate-appointments' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<tr>
					<td><code>mode</code></td>
					<td><?php esc_html_e( '流程順序。預設 staff_first（先選人員），改成 service_first 就是先選項目。', 'ultimate-appointments' ); ?></td>
					<td><code>[uappt_booking mode="service_first"]</code></td>
				</tr>
				<tr>
					<td><code>staff</code></td>
					<td><?php esc_html_e( '鎖定服務人員，會跳過「選擇服務人員」那一步。', 'ultimate-appointments' ); ?></td>
					<td><code>[uappt_booking staff="1"]</code></td>
				</tr>
				<tr>
					<td><code>service</code></td>
					<td><?php esc_html_e( '鎖定服務項目（商品 ID），會跳過「選擇服務項目」那一步。', 'ultimate-appointments' ); ?></td>
					<td><code>[uappt_booking service="123"]</code></td>
				</tr>
				<tr>
					<td><code>plan</code></td>
					<td><?php esc_html_e( '搭配 service 使用，指定是哪一個服務方案。', 'ultimate-appointments' ); ?></td>
					<td><code>[uappt_booking service="123" plan="plan_xxx"]</code></td>
				</tr>
			</tbody>
		</table>

		<?php if ( ! empty( $uappt_pages ) ) : ?>
			<h3><?php esc_html_e( '目前放了預約精靈的頁面', 'ultimate-appointments' ); ?></h3>
			<ul class="uappt-wizard-pages">
				<?php foreach ( $uappt_pages as $uappt_page ) : ?>
					<li>
						<a href="<?php echo esc_url( $uappt_page['url'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $uappt_page['title'] ); ?></a>
						<span class="uappt-badge uappt-badge-muted">
							<?php echo 'shortcode' === $uappt_page['via'] ? esc_html__( '短代碼', 'ultimate-appointments' ) : esc_html__( 'Elementor 元件', 'ultimate-appointments' ); ?>
						</span>
						<a class="uappt-wizard-page-edit" href="<?php echo esc_url( get_edit_post_link( $uappt_page['id'] ) ); ?>"><?php esc_html_e( '編輯', 'ultimate-appointments' ); ?></a>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php else : ?>
			<h3><?php esc_html_e( '目前放了預約精靈的頁面', 'ultimate-appointments' ); ?></h3>
			<p class="description"><?php esc_html_e( '還沒有任何頁面放上預約精靈。建立一個頁面，把上面的短代碼貼進去即可。', 'ultimate-appointments' ); ?></p>
		<?php endif; ?>

		<h3><?php esc_html_e( '人員專屬預約連結', 'ultimate-appointments' ); ?></h3>
		<p class="description">
			<?php esc_html_e( '網址參數會覆蓋短代碼設定。把專屬連結給設計師放在 IG 個人簡介或 LINE 名片，客人點進來就已經指定好人，直接從選服務項目開始。', 'ultimate-appointments' ); ?>
			<?php if ( ! empty( $uappt_pages ) ) : ?>
				<br />
				<?php
				printf(
					/* translators: %s: 頁面標題 */
					esc_html__( '底下的連結是以「%s」為例；換成任何一個有放預約精靈的頁面都可以。', 'ultimate-appointments' ),
					esc_html( $uappt_pages[0]['title'] )
				);
				?>
			<?php endif; ?>
		</p>

		<?php if ( empty( $uappt_staff ) ) : ?>
			<p class="description"><?php esc_html_e( '目前沒有啟用中的人員。', 'ultimate-appointments' ); ?></p>
		<?php else : ?>
			<table class="widefat striped uappt-shortcode-table">
				<thead>
					<tr>
						<th><?php esc_html_e( '人員', 'ultimate-appointments' ); ?></th>
						<th><?php esc_html_e( 'ID', 'ultimate-appointments' ); ?></th>
						<th><?php esc_html_e( '短代碼', 'ultimate-appointments' ); ?></th>
						<th><?php esc_html_e( '專屬連結', 'ultimate-appointments' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $uappt_staff as $uappt_s ) : ?>
						<tr>
							<td><?php echo esc_html( $uappt_s['name'] ); ?></td>
							<td><code><?php echo esc_html( $uappt_s['id'] ); ?></code></td>
							<td><?php uappt_copy_field( sprintf( '[uappt_booking staff="%d"]', $uappt_s['id'] ), true ); ?></td>
							<td><?php uappt_copy_field( add_query_arg( 'staff', (int) $uappt_s['id'], $uappt_example_url ), true ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>

		<h3><?php esc_html_e( '服務項目對照', 'ultimate-appointments' ); ?></h3>
		<p class="description">
			<?php esc_html_e( '放在單一服務的介紹頁時用得到——鎖定服務後，客人進來直接選人員與時間。', 'ultimate-appointments' ); ?>
		</p>

		<?php if ( empty( $uappt_services ) ) : ?>
			<p class="description"><?php esc_html_e( '目前沒有可預約的服務項目。', 'ultimate-appointments' ); ?></p>
		<?php else : ?>
			<table class="widefat striped uappt-shortcode-table">
				<thead>
					<tr>
						<th><?php esc_html_e( '服務項目', 'ultimate-appointments' ); ?></th>
						<th><?php esc_html_e( '時長', 'ultimate-appointments' ); ?></th>
						<th><?php esc_html_e( '短代碼', 'ultimate-appointments' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $uappt_services as $uappt_svc ) : ?>
						<?php
						$uappt_label = ( $uappt_svc['product_name'] === $uappt_svc['name'] )
							? $uappt_svc['name']
							: $uappt_svc['product_name'] . ' — ' . $uappt_svc['name'];

						$uappt_code = '' !== $uappt_svc['plan_key']
							? sprintf( '[uappt_booking service="%d" plan="%s"]', $uappt_svc['product_id'], $uappt_svc['plan_key'] )
							: sprintf( '[uappt_booking service="%d"]', $uappt_svc['product_id'] );
						?>
						<tr>
							<td><?php echo esc_html( $uappt_label ); ?></td>
							<td><?php printf( '%d %s', (int) $uappt_svc['duration_minutes'], esc_html__( '分鐘', 'ultimate-appointments' ) ); ?></td>
							<td><?php uappt_copy_field( $uappt_code, true ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>

	</div>
</div>

<script>
/**
 * 複製按鈕。刻意用事件代理掛在整個區塊上：表格是 PHP 迴圈印出來的，
 * 逐一綁定只是多繞一圈。
 *
 * navigator.clipboard 需要安全情境（https 或 localhost），本機用自簽憑證的
 * http 站台會拿不到，所以保留 execCommand 這條退路——不然在開發站上按了
 * 完全沒反應，會以為功能壞了。
 */
( function () {
	var panel = document.currentScript && document.currentScript.previousElementSibling;
	if ( ! panel ) {
		return;
	}

	panel.addEventListener( 'click', function ( e ) {
		var btn = e.target.closest ? e.target.closest( '.uappt-copy-btn' ) : null;
		if ( ! btn ) {
			return;
		}

		var input = btn.parentElement.querySelector( 'input' );
		if ( ! input ) {
			return;
		}

		input.select();
		input.setSelectionRange( 0, 99999 );

		var done = function () {
			var original = btn.textContent;
			btn.textContent = btn.getAttribute( 'data-copied' );
			btn.classList.add( 'is-copied' );
			setTimeout( function () {
				btn.textContent = original;
				btn.classList.remove( 'is-copied' );
			}, 1500 );
		};

		if ( navigator.clipboard && window.isSecureContext ) {
			navigator.clipboard.writeText( input.value ).then( done ).catch( function () {
				if ( document.execCommand( 'copy' ) ) {
					done();
				}
			} );
			return;
		}

		if ( document.execCommand( 'copy' ) ) {
			done();
		}
	} );
} )();
</script>
